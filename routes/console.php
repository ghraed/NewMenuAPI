<?php

use App\Models\Dish;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\GlobalIngredient;
use App\Models\Ingredient;
use App\Models\PayrollPeriod;
use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\DummyDishesSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('seed:prod', function () {
    $email = env('ADMIN_EMAIL');
    $password = env('ADMIN_PASSWORD');
    $name = env('ADMIN_NAME', 'Admin');

    if (!$email || !$password) {
        $this->error('ADMIN_EMAIL and ADMIN_PASSWORD must be set in the environment.');
        return 1;
    }

    $user = User::firstOrCreate(
        ['email' => $email],
        ['name' => $name, 'password' => Hash::make($password)]
    );

    if (!$user->wasRecentlyCreated && $user->name !== $name) {
        $user->update(['name' => $name]);
    }

    $restaurant = $user->restaurant;
    if (!$restaurant) {
        $restaurantName = env('ADMIN_RESTAURANT_NAME', $name . "'s Restaurant");
        $restaurantSlug = env('ADMIN_RESTAURANT_SLUG', Str::slug($restaurantName));
        $restaurantAddress = env('ADMIN_RESTAURANT_ADDRESS', '');
        $restaurantDescription = env('ADMIN_RESTAURANT_DESCRIPTION', '');

        if (Restaurant::where('slug', $restaurantSlug)->exists()) {
            $restaurantSlug = $restaurantSlug . '-' . Str::random(4);
        }

        $restaurant = Restaurant::create([
            'uuid' => Str::uuid(),
            'user_id' => $user->id,
            'name' => $restaurantName,
            'slug' => $restaurantSlug,
            'description' => $restaurantDescription,
            'address' => $restaurantAddress,
        ]);
    }

    $this->info('Admin user ready: ' . $user->email);
    $this->info('Restaurant: ' . $restaurant->name . ' (' . $restaurant->slug . ')');
})->purpose('Create or update a production admin user and restaurant.');

Schedule::command('dishes:cleanup-deleted-assets')->dailyAt('02:00');

Artisan::command('dishes:purge-dummy', function () {
    $dummyDishes = Dish::query()
        ->where('description', 'like', DummyDishesSeeder::descriptionMarker().'%')
        ->get();

    if ($dummyDishes->isEmpty()) {
        $this->info('No dummy dishes found.');
        return 0;
    }

    $count = $dummyDishes->count();

    Dish::query()
        ->whereIn('id', $dummyDishes->pluck('id'))
        ->delete();

    $this->info(sprintf('Deleted %d dummy dishes.', $count));

    return 0;
})->purpose('Delete all dummy dishes created by the DummyDishesSeeder.');


Artisan::command('ingredients:backfill-global-links', function () {
    $globalByNormalized = GlobalIngredient::query()
        ->get()
        ->keyBy(fn (GlobalIngredient $ingredient) => $ingredient->normalized_name);

    if ($globalByNormalized->isEmpty()) {
        $this->warn('No global ingredients found. Seed them first using php artisan db:seed.');

        return 0;
    }

    $updatedCount = 0;

    Ingredient::query()
        ->whereNull('global_ingredient_id')
        ->orderBy('id')
        ->chunkById(200, function ($ingredients) use ($globalByNormalized, &$updatedCount) {
            foreach ($ingredients as $ingredient) {
                $normalizedName = strtolower(trim((string) $ingredient->name));
                $normalizedName = str_replace('&', 'and', $normalizedName);
                $normalizedName = preg_replace('/[^a-z0-9]+/', ' ', $normalizedName) ?? $normalizedName;
                $normalizedName = trim(preg_replace('/\s+/', ' ', $normalizedName) ?? $normalizedName);

                if ($normalizedName === '') {
                    continue;
                }

                /** @var GlobalIngredient|null $matchedGlobal */
                $matchedGlobal = $globalByNormalized->get($normalizedName);
                if (! $matchedGlobal) {
                    continue;
                }

                $ingredient->update([
                    'global_ingredient_id' => $matchedGlobal->id,
                ]);

                $updatedCount++;
            }
        });

    $this->info(sprintf('Backfill complete. Linked %d local ingredients to global catalog rows.', $updatedCount));

    return 0;
})->purpose('Backfill ingredients.global_ingredient_id by normalized local ingredient names.');

Artisan::command('finance:backfill-payroll-mirror-expenses {--restaurant_id=} {--period_id=}', function () {
    if (!\Illuminate\Support\Facades\Schema::hasColumn('expenses', 'payroll_period_id')) {
        $this->error('Missing expenses.payroll_period_id. Run latest migrations first.');
        return 1;
    }

    $restaurantId = $this->option('restaurant_id');
    $periodId = $this->option('period_id');

    $query = PayrollPeriod::query()
        ->where('status', PayrollPeriod::STATUS_PAID)
        ->with(['entries', 'restaurant']);

    if (is_numeric($restaurantId)) {
        $query->where('restaurant_id', (int) $restaurantId);
    }

    if (is_numeric($periodId)) {
        $query->where('id', (int) $periodId);
    }

    $processed = 0;

    $query->orderBy('id')->chunkById(100, function ($periods) use (&$processed) {
        foreach ($periods as $period) {
            $restaurant = $period->restaurant;
            if (! $restaurant) {
                continue;
            }

            $grossCents = (int) (
                (int) $period->entries->sum('base_amount_cents')
                + (int) $period->entries->sum('overtime_amount_cents')
                + (int) $period->entries->sum('bonus_amount_cents')
                + (int) $period->entries->sum('allowance_amount_cents')
                + (int) $period->entries->sum('reimbursement_amount_cents')
            );
            $employeeCount = $period->entries->pluck('user_id')->unique()->count();
            $currency = strtoupper((string) ($restaurant->currency ?? 'USD'));

            $payrollCategory = ExpenseCategory::query()->firstOrCreate(
                [
                    'restaurant_id' => $restaurant->id,
                    'code' => 'payroll',
                ],
                [
                    'name' => 'Payroll',
                    'is_active' => true,
                ]
            );

            $paidDate = $period->paid_at?->copy()->toDateString() ?? now()->toDateString();

            Expense::query()->updateOrCreate(
                [
                    'restaurant_id' => $restaurant->id,
                    'payroll_period_id' => $period->id,
                ],
                [
                    'uuid' => (string) Str::uuid(),
                    'expense_category_id' => $payrollCategory->id,
                    'vendor_id' => null,
                    'expense_date' => $paidDate,
                    'amount_cents' => max(0, $grossCents),
                    'tax_amount_cents' => 0,
                    'currency' => $currency,
                    'status' => Expense::STATUS_PAID,
                    'payment_method' => null,
                    'reference_no' => 'PAYROLL-'.$period->id,
                    'description' => sprintf(
                        'Payroll period %s to %s (%d employees)',
                        $period->period_start?->toDateString() ?? '-',
                        $period->period_end?->toDateString() ?? '-',
                        $employeeCount
                    ),
                    'notes' => is_string($period->notes) && trim($period->notes) !== '' ? trim($period->notes) : null,
                    'due_date' => null,
                    'paid_at' => $period->paid_at ?? now(),
                    'created_by' => null,
                    'approved_by' => null,
                ]
            );

            $processed++;
        }
    });

    $this->info(sprintf('Backfill complete. Mirrored %d paid payroll period(s).', $processed));

    return 0;
})->purpose('Backfill or re-sync payroll mirror expenses for paid payroll periods.');
