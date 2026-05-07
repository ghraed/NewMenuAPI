<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class FinancePayrollController extends Controller
{
    private static ?bool $payrollMirrorSchemaReady = null;

    public function periodsIndex(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $periods = PayrollPeriod::query()
            ->where('restaurant_id', $restaurant->id)
            ->with([
                'entries.user:id,name,email,phone,role',
                'processedBy:id,name,email,role',
                'adjustmentOfPeriod:id,period_start,period_end,status',
            ])
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'periods' => $periods->map(fn (PayrollPeriod $period): array => $this->formatPeriod($period))->values(),
        ]);
    }

    public function periodsStore(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $validated = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'period_type' => ['nullable', 'in:regular,adjustment'],
            'adjustment_of_period_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $periodType = (string) ($validated['period_type'] ?? PayrollPeriod::TYPE_REGULAR);
        $adjustmentOfPeriodId = isset($validated['adjustment_of_period_id']) ? (int) $validated['adjustment_of_period_id'] : null;

        if ($periodType === PayrollPeriod::TYPE_ADJUSTMENT) {
            if ($adjustmentOfPeriodId === null) {
                throw ValidationException::withMessages([
                    'adjustment_of_period_id' => 'Adjustment period must reference an original payroll period.',
                ]);
            }

            $originPeriod = PayrollPeriod::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('id', $adjustmentOfPeriodId)
                ->first();
            if (! $originPeriod) {
                throw ValidationException::withMessages([
                    'adjustment_of_period_id' => 'Referenced original payroll period was not found.',
                ]);
            }
            if ($originPeriod->period_type !== PayrollPeriod::TYPE_REGULAR) {
                throw ValidationException::withMessages([
                    'adjustment_of_period_id' => 'Adjustment period must reference a regular payroll period.',
                ]);
            }
            if ($originPeriod->status !== PayrollPeriod::STATUS_PAID) {
                throw ValidationException::withMessages([
                    'adjustment_of_period_id' => 'You can only create adjustments for paid payroll periods.',
                ]);
            }

            $overlapsRegular = PayrollPeriod::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('period_type', PayrollPeriod::TYPE_REGULAR)
                ->whereDate('period_start', '<=', $validated['period_end'])
                ->whereDate('period_end', '>=', $validated['period_start'])
                ->exists();
            if ($overlapsRegular) {
                throw ValidationException::withMessages([
                    'period_start' => 'Adjustment period cannot overlap regular payroll periods.',
                ]);
            }
        } else {
            $hasOverlap = PayrollPeriod::query()
                ->where('restaurant_id', $restaurant->id)
                ->whereDate('period_start', '<=', $validated['period_end'])
                ->whereDate('period_end', '>=', $validated['period_start'])
                ->exists();
            if ($hasOverlap) {
                throw ValidationException::withMessages([
                    'period_start' => 'Payroll period overlaps an existing period.',
                ]);
            }
        }

        $period = PayrollPeriod::query()->create([
            'restaurant_id' => $restaurant->id,
            'period_start' => $validated['period_start'],
            'period_end' => $validated['period_end'],
            'period_type' => $periodType,
            'adjustment_of_period_id' => $periodType === PayrollPeriod::TYPE_ADJUSTMENT ? $adjustmentOfPeriodId : null,
            'status' => PayrollPeriod::STATUS_DRAFT,
            'notes' => $this->normalizeOptionalString($validated['notes'] ?? null),
        ]);

        return response()->json([
            'message' => 'Payroll period created successfully.',
            'period' => $this->formatPeriod($period->fresh(['entries.user:id,name,email,phone,role', 'processedBy:id,name,email,role'])),
        ], 201);
    }

    public function queryPeriods(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $validated = $request->validate([
            'mode' => ['required', 'in:monthly,range'],
            'year' => ['required_if:mode,monthly', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required_if:mode,monthly', 'integer', 'min:1', 'max:12'],
            'date_from' => ['required_if:mode,range', 'date'],
            'date_to' => ['required_if:mode,range', 'date', 'after_or_equal:date_from'],
            'split_mode' => ['nullable', 'in:full,weekly,custom_days'],
            'split_days' => ['required_if:split_mode,custom_days', 'integer', 'min:1', 'max:31'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        [$windowStart, $windowEnd] = $this->resolveQueryWindow($validated);
        $splitMode = (string) ($validated['split_mode'] ?? 'full');
        $splitDays = isset($validated['split_days']) ? (int) $validated['split_days'] : null;
        $notes = $this->normalizeOptionalString($validated['notes'] ?? null);
        $targetSegments = $this->buildQuerySegments($windowStart, $windowEnd, $splitMode, $splitDays);

        $result = DB::transaction(function () use ($restaurant, $windowStart, $windowEnd, $notes, $targetSegments, $splitMode): array {
            $existing = PayrollPeriod::query()
                ->where('restaurant_id', $restaurant->id)
                ->whereDate('period_start', '<=', $windowEnd->toDateString())
                ->whereDate('period_end', '>=', $windowStart->toDateString())
                ->orderBy('period_start')
                ->orderBy('period_end')
                ->lockForUpdate()
                ->get();
            $createdIds = [];

            if ($splitMode === 'full') {
                if ($existing->isEmpty()) {
                    $period = PayrollPeriod::query()->create([
                        'restaurant_id' => $restaurant->id,
                        'period_start' => $windowStart->toDateString(),
                        'period_end' => $windowEnd->toDateString(),
                        'status' => PayrollPeriod::STATUS_DRAFT,
                        'notes' => $notes,
                    ]);

                    return [
                        'periods' => [$period],
                        'created_ids' => [$period->id],
                    ];
                }

                $selected = [];
                $cursor = $windowStart->copy();

                foreach ($existing as $period) {
                    $periodStart = Carbon::parse($period->period_start)->startOfDay();
                    $periodEnd = Carbon::parse($period->period_end)->endOfDay();

                    if ($periodStart->gt($cursor) && $cursor->lte($windowEnd)) {
                        $gapEnd = $periodStart->copy()->subDay();
                        if ($gapEnd->gt($windowEnd)) {
                            $gapEnd = $windowEnd->copy();
                        }
                        if ($cursor->lte($gapEnd)) {
                            $created = PayrollPeriod::query()->create([
                                'restaurant_id' => $restaurant->id,
                                'period_start' => $cursor->toDateString(),
                                'period_end' => $gapEnd->toDateString(),
                                'status' => PayrollPeriod::STATUS_DRAFT,
                                'notes' => $notes,
                            ]);
                            $selected[] = $created;
                            $createdIds[] = $created->id;
                        }
                    }

                    if ($periodEnd->lt($windowStart) || $periodStart->gt($windowEnd)) {
                        continue;
                    }

                    $selected[] = $period;

                    $nextCursor = $periodEnd->copy()->addDay()->startOfDay();
                    if ($nextCursor->gt($cursor)) {
                        $cursor = $nextCursor;
                    }
                }

                if ($cursor->lte($windowEnd)) {
                    $created = PayrollPeriod::query()->create([
                        'restaurant_id' => $restaurant->id,
                        'period_start' => $cursor->toDateString(),
                        'period_end' => $windowEnd->toDateString(),
                        'status' => PayrollPeriod::STATUS_DRAFT,
                        'notes' => $notes,
                    ]);
                    $selected[] = $created;
                    $createdIds[] = $created->id;
                }

                usort($selected, function (PayrollPeriod $a, PayrollPeriod $b): int {
                    $startCompare = strcmp((string) $a->period_start, (string) $b->period_start);
                    if ($startCompare !== 0) {
                        return $startCompare;
                    }

                    return (int) $a->id <=> (int) $b->id;
                });

                return [
                    'periods' => $selected,
                    'created_ids' => $createdIds,
                ];
            }

            $existingByRange = $existing
                ->keyBy(fn (PayrollPeriod $period): string => $period->period_start->toDateString().'|'.$period->period_end->toDateString());

            $selected = [];
            foreach ($targetSegments as $segment) {
                $key = $segment['start']->toDateString().'|'.$segment['end']->toDateString();
                $period = $existingByRange->get($key);
                if ($period) {
                    $selected[] = $period;
                    continue;
                }

                $created = PayrollPeriod::query()->create([
                    'restaurant_id' => $restaurant->id,
                    'period_start' => $segment['start']->toDateString(),
                    'period_end' => $segment['end']->toDateString(),
                    'status' => PayrollPeriod::STATUS_DRAFT,
                    'notes' => $notes,
                ]);
                $selected[] = $created;
                $createdIds[] = $created->id;
            }

            usort($selected, function (PayrollPeriod $a, PayrollPeriod $b): int {
                $startCompare = strcmp((string) $a->period_start, (string) $b->period_start);
                if ($startCompare !== 0) {
                    return $startCompare;
                }

                return (int) $a->id <=> (int) $b->id;
            });

            return [
                'periods' => $selected,
                'created_ids' => $createdIds,
            ];
        });

        $periodIds = collect($result['periods'] ?? [])->pluck('id')->values()->all();
        $createdIds = collect($result['created_ids'] ?? [])->map(fn ($id): int => (int) $id)->values()->all();
        $resolvedPeriods = PayrollPeriod::query()
            ->whereIn('id', $periodIds)
            ->with(['entries.user:id,name,email,phone,role', 'processedBy:id,name,email,role'])
            ->orderBy('period_start')
            ->orderBy('id')
            ->get();

        return response()->json([
            'mode' => $validated['mode'],
            'split_mode' => $splitMode,
            'split_days' => $splitMode === 'custom_days' ? $splitDays : null,
            'created_period_ids' => $createdIds,
            'created_count' => count($createdIds),
            'window' => [
                'date_from' => $windowStart->toDateString(),
                'date_to' => $windowEnd->toDateString(),
            ],
            'periods' => $resolvedPeriods->map(fn (PayrollPeriod $period): array => $this->formatPeriod($period))->values(),
        ]);
    }

    public function periodsUpdate(Request $request, PayrollPeriod $payrollPeriod): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $period = $this->assertPeriodBelongsToRestaurant($payrollPeriod, $restaurant);

        $validated = $request->validate([
            'status' => ['sometimes', 'required', 'in:draft,approved,paid'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $payload = [];
        $nextStatus = $validated['status'] ?? $period->status;

        if ($period->status === PayrollPeriod::STATUS_DRAFT && $nextStatus === PayrollPeriod::STATUS_PAID) {
            throw ValidationException::withMessages([
                'status' => 'Payroll period must be approved before it is marked paid.',
            ]);
        }

        if ($period->status === PayrollPeriod::STATUS_PAID && $nextStatus !== PayrollPeriod::STATUS_PAID) {
            throw ValidationException::withMessages([
                'status' => 'Paid payroll period cannot be moved back to approved or draft.',
            ]);
        }

        if (array_key_exists('status', $validated)) {
            $payload['status'] = $nextStatus;
        }
        if ($nextStatus === PayrollPeriod::STATUS_APPROVED && $period->status !== PayrollPeriod::STATUS_APPROVED) {
            $payload['approved_at'] = now();
            $payload['processed_by'] = $request->user()?->id;
        }
        if ($nextStatus !== PayrollPeriod::STATUS_APPROVED && array_key_exists('status', $validated)) {
            $payload['approved_at'] = null;
        }
        if ($nextStatus === PayrollPeriod::STATUS_PAID && $period->status !== PayrollPeriod::STATUS_PAID) {
            $payload['paid_at'] = now();
            $payload['processed_by'] = $request->user()?->id;
        }
        if ($nextStatus !== PayrollPeriod::STATUS_PAID && array_key_exists('status', $validated)) {
            $payload['paid_at'] = null;
        }
        if ($nextStatus === PayrollPeriod::STATUS_DRAFT && array_key_exists('status', $validated)) {
            $payload['processed_by'] = null;
        }
        if (array_key_exists('notes', $validated)) {
            $payload['notes'] = $this->normalizeOptionalString($validated['notes']);
        }

        $period = DB::transaction(function () use ($period, $payload, $nextStatus, $request, $restaurant): PayrollPeriod {
            if ($payload !== []) {
                $period->update($payload);
            }

            if ($nextStatus === PayrollPeriod::STATUS_PAID) {
                $this->syncPayrollMirrorExpense($period, $restaurant, $request->user()?->id);
            }

            $relations = ['entries.user:id,name,email,phone,role', 'processedBy:id,name,email,role'];
            if ($this->isPayrollMirrorSchemaReady()) {
                $relations[] = 'mirroredExpense:id,payroll_period_id';
            }

            return $period->fresh($relations);
        });

        return response()->json([
            'message' => 'Payroll period updated successfully.',
            'period' => $this->formatPeriod($period),
        ]);
    }

    public function entriesUpsert(Request $request, PayrollPeriod $payrollPeriod): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $period = $this->assertPeriodBelongsToRestaurant($payrollPeriod, $restaurant);

        if ($period->status === PayrollPeriod::STATUS_PAID) {
            throw ValidationException::withMessages([
                'status' => 'Paid payroll period entries cannot be modified.',
            ]);
        }

        $isAdjustmentPeriod = $period->period_type === PayrollPeriod::TYPE_ADJUSTMENT;

        $validated = $request->validate([
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.user_id' => ['required', 'integer', 'distinct'],
            'entries.*.base_amount_cents' => $isAdjustmentPeriod
                ? ['required', 'integer']
                : ['required', 'integer', 'min:0'],
            'entries.*.overtime_amount_cents' => ['sometimes', 'integer', 'min:0'],
            'entries.*.bonus_amount_cents' => ['sometimes', 'integer', 'min:0'],
            'entries.*.allowance_amount_cents' => ['sometimes', 'integer', 'min:0'],
            'entries.*.reimbursement_amount_cents' => ['sometimes', 'integer', 'min:0'],
            'entries.*.deduction_amount_cents' => ['sometimes', 'integer', 'min:0'],
            'entries.*.tax_amount_cents' => ['sometimes', 'integer', 'min:0'],
            'entries.*.currency' => ['sometimes', 'string', 'size:3'],
            'entries.*.notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $employeeIds = $this->resolveRestaurantEmployeeIds($restaurant);
        $submittedUserIds = collect($validated['entries'])->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $unknownIds = array_values(array_diff($submittedUserIds, $employeeIds));
        if ($unknownIds !== []) {
            throw ValidationException::withMessages([
                'entries' => 'One or more users are not eligible employees for this restaurant.',
            ]);
        }

        DB::transaction(function () use ($validated, $period, $restaurant): void {
            foreach ($validated['entries'] as $index => $entryPayload) {
                if ($isAdjustmentPeriod) {
                    $correction = (int) $entryPayload['base_amount_cents'];
                    $note = $this->normalizeOptionalString($entryPayload['notes'] ?? null);
                    if ($note === null) {
                        throw ValidationException::withMessages([
                            "entries.{$index}.notes" => 'Adjustment entries require a note explaining the correction.',
                        ]);
                    }
                    $base = $correction;
                    $overtime = 0;
                    $bonus = 0;
                    $allowance = 0;
                    $reimbursement = 0;
                    $deduction = 0;
                    $tax = 0;
                    $net = $correction;
                } else {
                    $base = (int) $entryPayload['base_amount_cents'];
                    $overtime = (int) ($entryPayload['overtime_amount_cents'] ?? 0);
                    $bonus = (int) ($entryPayload['bonus_amount_cents'] ?? 0);
                    $allowance = (int) ($entryPayload['allowance_amount_cents'] ?? 0);
                    $reimbursement = (int) ($entryPayload['reimbursement_amount_cents'] ?? 0);
                    $deduction = (int) ($entryPayload['deduction_amount_cents'] ?? 0);
                    $tax = (int) ($entryPayload['tax_amount_cents'] ?? 0);
                    $net = $base + $overtime + $bonus + $allowance + $reimbursement - $deduction - $tax;
                    if ($net < 0) {
                        throw ValidationException::withMessages([
                            "entries.{$index}.deduction_amount_cents" => 'Payroll entry net pay cannot be negative.',
                        ]);
                    }
                }

                PayrollEntry::query()->updateOrCreate(
                    [
                        'payroll_period_id' => $period->id,
                        'user_id' => (int) $entryPayload['user_id'],
                    ],
                    [
                        'restaurant_id' => $restaurant->id,
                        'base_amount_cents' => $base,
                        'overtime_amount_cents' => $overtime,
                        'bonus_amount_cents' => $bonus,
                        'allowance_amount_cents' => $allowance,
                        'reimbursement_amount_cents' => $reimbursement,
                        'deduction_amount_cents' => $deduction,
                        'tax_amount_cents' => $tax,
                        'net_amount_cents' => $net,
                        'currency' => strtoupper((string) ($entryPayload['currency'] ?? 'USD')),
                        'notes' => $this->normalizeOptionalString($entryPayload['notes'] ?? null),
                    ]
                );
            }
        });

        return response()->json([
            'message' => 'Payroll entries saved successfully.',
            'period' => $this->formatPeriod($period->fresh(['entries.user:id,name,email,phone,role', 'processedBy:id,name,email,role'])),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'period_status' => ['nullable', 'in:approved_paid,all'],
        ]);

        [$from, $to] = $this->resolveSummaryRange(
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null
        );

        $periodQuery = PayrollPeriod::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereDate('period_start', '<=', $to->toDateString())
            ->whereDate('period_end', '>=', $from->toDateString());

        if (($validated['period_status'] ?? 'approved_paid') === 'approved_paid') {
            $periodQuery->whereIn('status', [PayrollPeriod::STATUS_APPROVED, PayrollPeriod::STATUS_PAID]);
        }

        $periodIds = $periodQuery->pluck('id');

        $aggregateRow = PayrollEntry::query()
            ->whereIn('payroll_period_id', $periodIds)
            ->selectRaw('
                SUM(base_amount_cents) as base_cents,
                SUM(overtime_amount_cents) as overtime_cents,
                SUM(bonus_amount_cents) as bonus_cents,
                SUM(allowance_amount_cents) as allowance_cents,
                SUM(reimbursement_amount_cents) as reimbursement_cents,
                SUM(deduction_amount_cents) as deduction_cents,
                SUM(tax_amount_cents) as tax_cents,
                SUM(net_amount_cents) as net_cents,
                COUNT(DISTINCT user_id) as employee_count
            ')
            ->first();

        $base = (int) ($aggregateRow?->base_cents ?? 0);
        $overtime = (int) ($aggregateRow?->overtime_cents ?? 0);
        $bonus = (int) ($aggregateRow?->bonus_cents ?? 0);
        $allowance = (int) ($aggregateRow?->allowance_cents ?? 0);
        $reimbursement = (int) ($aggregateRow?->reimbursement_cents ?? 0);
        $deduction = (int) ($aggregateRow?->deduction_cents ?? 0);
        $tax = (int) ($aggregateRow?->tax_cents ?? 0);
        $net = (int) ($aggregateRow?->net_cents ?? 0);
        $gross = $base + $overtime + $bonus + $allowance + $reimbursement;

        return response()->json([
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'mode' => [
                'period_status' => $validated['period_status'] ?? 'approved_paid',
            ],
            'totals' => [
                'gross_pay' => round($gross / 100, 2),
                'deductions' => round($deduction / 100, 2),
                'tax' => round($tax / 100, 2),
                'net_pay' => round($net / 100, 2),
                'employee_count' => (int) ($aggregateRow?->employee_count ?? 0),
            ],
        ]);
    }

    private function getRestaurantForRequest(Request $request): Restaurant
    {
        $user = $request->user();
        $user->loadMissing('restaurant', 'staffRestaurants');

        $restaurant = $user->currentRestaurant();
        if (! $restaurant) {
            abort(403, 'No restaurant is linked to this account');
        }

        return $restaurant;
    }

    private function assertPeriodBelongsToRestaurant(PayrollPeriod $period, Restaurant $restaurant): PayrollPeriod
    {
        if ((int) $period->restaurant_id !== (int) $restaurant->id) {
            abort(404);
        }

        return $period;
    }

    /**
     * @return array<int>
     */
    private function resolveRestaurantEmployeeIds(Restaurant $restaurant): array
    {
        return $restaurant->staffUsers()
            ->whereIn('users.role', [User::ROLE_STAFF, User::ROLE_CHEF])
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array{0:Carbon,1:Carbon}
     */
    private function resolveSummaryRange(?string $dateFrom, ?string $dateTo): array
    {
        if ($dateFrom !== null && $dateTo !== null) {
            return [Carbon::parse($dateFrom)->startOfDay(), Carbon::parse($dateTo)->endOfDay()];
        }

        return [now()->startOfMonth(), now()->endOfMonth()];
    }

    /**
     * @param array<string,mixed> $validated
     * @return array{0:Carbon,1:Carbon}
     */
    private function resolveQueryWindow(array $validated): array
    {
        if (($validated['mode'] ?? null) === 'monthly') {
            $year = (int) $validated['year'];
            $month = (int) $validated['month'];
            $from = Carbon::createFromDate($year, $month, 1)->startOfDay();
            $to = $from->copy()->endOfMonth()->endOfDay();

            return [$from, $to];
        }

        $from = Carbon::parse((string) $validated['date_from'])->startOfDay();
        $to = Carbon::parse((string) $validated['date_to'])->endOfDay();

        return [$from, $to];
    }

    /**
     * @return array<int,array{start:Carbon,end:Carbon}>
     */
    private function buildQuerySegments(Carbon $windowStart, Carbon $windowEnd, string $splitMode, ?int $splitDays): array
    {
        if ($splitMode === 'full') {
            return [[
                'start' => $windowStart->copy()->startOfDay(),
                'end' => $windowEnd->copy()->endOfDay(),
            ]];
        }

        $segments = [];
        $cursor = $windowStart->copy()->startOfDay();
        $daysPerSegment = $splitMode === 'weekly'
            ? 7
            : max(1, (int) ($splitDays ?? 1));

        while ($cursor->lte($windowEnd)) {
            $segmentStart = $cursor->copy()->startOfDay();
            $segmentEnd = $cursor->copy()->addDays($daysPerSegment - 1)->endOfDay();
            if ($segmentEnd->gt($windowEnd)) {
                $segmentEnd = $windowEnd->copy()->endOfDay();
            }

            $segments[] = [
                'start' => $segmentStart,
                'end' => $segmentEnd,
            ];

            $cursor = $segmentEnd->copy()->addDay()->startOfDay();
        }

        return $segments;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        return $normalized === '' ? null : $normalized;
    }

    private function syncPayrollMirrorExpense(PayrollPeriod $period, Restaurant $restaurant, ?int $performedByUserId): void
    {
        if (! $this->isPayrollMirrorSchemaReady()) {
            throw ValidationException::withMessages([
                'status' => 'Payroll mirror schema is not ready yet. Please run the latest database migrations.',
            ]);
        }

        $period->loadMissing('entries');

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
                'notes' => $this->normalizeOptionalString($period->notes),
                'due_date' => null,
                'paid_at' => $period->paid_at ?? now(),
                'created_by' => $performedByUserId,
                'approved_by' => $performedByUserId,
            ]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function formatPeriod(PayrollPeriod $period): array
    {
        $relations = [
            'entries.user:id,name,email,phone,role',
            'processedBy:id,name,email,role',
            'adjustmentOfPeriod:id,period_start,period_end,status',
        ];
        $mirroredExpenseId = null;
        if ($this->isPayrollMirrorSchemaReady()) {
            try {
                $relations[] = 'mirroredExpense:id,payroll_period_id';
                $period->loadMissing($relations);
                $mirroredExpenseId = $period->mirroredExpense?->id;
            } catch (Throwable) {
                self::$payrollMirrorSchemaReady = false;
                $period->loadMissing([
                    'entries.user:id,name,email,phone,role',
                    'processedBy:id,name,email,role',
                    'adjustmentOfPeriod:id,period_start,period_end,status',
                ]);
            }
        } else {
            $period->loadMissing($relations);
        }

        $base = (int) $period->entries->sum('base_amount_cents');
        $overtime = (int) $period->entries->sum('overtime_amount_cents');
        $bonus = (int) $period->entries->sum('bonus_amount_cents');
        $allowance = (int) $period->entries->sum('allowance_amount_cents');
        $reimbursement = (int) $period->entries->sum('reimbursement_amount_cents');
        $deduction = (int) $period->entries->sum('deduction_amount_cents');
        $tax = (int) $period->entries->sum('tax_amount_cents');
        $net = (int) $period->entries->sum('net_amount_cents');

        return [
            'id' => $period->id,
            'restaurant_id' => $period->restaurant_id,
            'period_start' => $period->period_start?->toDateString(),
            'period_end' => $period->period_end?->toDateString(),
            'period_type' => $period->period_type ?? PayrollPeriod::TYPE_REGULAR,
            'adjustment_of_period_id' => $period->adjustment_of_period_id,
            'status' => $period->status,
            'approved_at' => $period->approved_at?->toISOString(),
            'paid_at' => $period->paid_at?->toISOString(),
            'notes' => $period->notes,
            'created_at' => $period->created_at?->toISOString(),
            'updated_at' => $period->updated_at?->toISOString(),
            'processed_by' => $period->processedBy ? [
                'id' => $period->processedBy->id,
                'name' => $period->processedBy->name,
                'email' => $period->processedBy->email,
                'role' => $period->processedBy->role,
            ] : null,
            'adjustment_of_period' => $period->adjustmentOfPeriod ? [
                'id' => $period->adjustmentOfPeriod->id,
                'period_start' => $period->adjustmentOfPeriod->period_start?->toDateString(),
                'period_end' => $period->adjustmentOfPeriod->period_end?->toDateString(),
                'status' => $period->adjustmentOfPeriod->status,
            ] : null,
            'mirrored_expense_id' => $mirroredExpenseId,
            'entries' => $period->entries
                ->map(fn (PayrollEntry $entry): array => [
                    'id' => $entry->id,
                    'user_id' => $entry->user_id,
                    'employee' => $entry->user ? [
                        'id' => $entry->user->id,
                        'name' => $entry->user->name,
                        'email' => $entry->user->email,
                        'phone' => $entry->user->phone,
                        'role' => $entry->user->role,
                    ] : null,
                    'base_amount_cents' => $entry->base_amount_cents,
                    'overtime_amount_cents' => $entry->overtime_amount_cents,
                    'bonus_amount_cents' => $entry->bonus_amount_cents,
                    'allowance_amount_cents' => $entry->allowance_amount_cents,
                    'reimbursement_amount_cents' => $entry->reimbursement_amount_cents,
                    'deduction_amount_cents' => $entry->deduction_amount_cents,
                    'tax_amount_cents' => $entry->tax_amount_cents,
                    'net_amount_cents' => $entry->net_amount_cents,
                    'currency' => $entry->currency,
                    'notes' => $entry->notes,
                ])
                ->values(),
            'totals' => [
                'gross_pay' => round(($base + $overtime + $bonus + $allowance + $reimbursement) / 100, 2),
                'deductions' => round($deduction / 100, 2),
                'tax' => round($tax / 100, 2),
                'net_pay' => round($net / 100, 2),
                'employee_count' => $period->entries->pluck('user_id')->unique()->count(),
            ],
        ];
    }

    private function isPayrollMirrorSchemaReady(): bool
    {
        if (self::$payrollMirrorSchemaReady !== null) {
            return self::$payrollMirrorSchemaReady;
        }

        self::$payrollMirrorSchemaReady = Schema::hasColumn('expenses', 'payroll_period_id');

        return self::$payrollMirrorSchemaReady;
    }
}
