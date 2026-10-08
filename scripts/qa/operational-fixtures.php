<?php

require __DIR__.'/bootstrap.php';
if (getenv('QA_OPERATIONAL') !== '1') {
    throw new RuntimeException('Operational rehearsal needs the guarded transports.');
}
use App\Models\EventNotificationLog;
use App\Models\EventReservation;
use App\Models\Invoice;
use App\Models\Restaurant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$prefix = 'QA_RUN_'.getenv('QA_RUN_ID').'_operations';
$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    Artisan::call('migrate:fresh', ['--force' => true]);
    (new Database\Seeders\FeatureSeeder)->run();
    $owner = App\Models\User::factory()->admin()->create(['name' => $prefix.'_admin', 'email' => $prefix.'@example.invalid', 'password' => getenv('PLAYWRIGHT_PROFILE_PASSWORD')]);
    $restaurant = Restaurant::factory()->for($owner, 'user')->create([
        'name' => $prefix, 'slug' => strtolower(str_replace('_', '-', $prefix)),
        'custom_domain' => 'operations.qa.invalid', 'custom_domain_status' => 'pending_dns',
    ]);
    foreach (['custom_domain', 'qr_menu', 'finance_dashboard', 'vat_invoices', 'expense_management', 'dish_profitability'] as $feature) {
        $restaurant->features()->attach(App\Models\Feature::where('key', $feature)->firstOrFail()->id, ['enabled' => true]);
    }
    $invoice = Invoice::factory()->paid()->for($restaurant)->create([
        'invoice_number' => $prefix.'_receipt', 'subtotal' => '25.00', 'discount_amount' => '2.00',
        'taxable_subtotal' => '23.00', 'vat_rate' => '10.00', 'vat_amount' => '2.30', 'total' => '25.30',
        'payment_method' => 'cash', 'payment_reference' => $prefix.'_cash',
        'pdf_disk' => 'local', 'pdf_path' => $prefix.'/receipt.txt',
    ]);
    Illuminate\Support\Facades\Storage::disk('local')->put($invoice->pdf_path, $prefix.' paid USD 25.30');
    Illuminate\Support\Facades\Storage::disk('local')->put($prefix.'/key-proof.enc', Illuminate\Support\Facades\Crypt::encryptString($prefix.'_key'));
    App\Models\TableSession::create([
        'uuid' => (string) Illuminate\Support\Str::uuid(), 'restaurant_id' => $restaurant->id,
        'restaurant_table_id' => $restaurant->tables()->firstOrFail()->id, 'table_number' => 1,
        'status' => 'closed', 'finalized_invoice_id' => $invoice->id,
    ]);
    $dueTime = Illuminate\Support\Carbon::parse(getenv('QA_SCHEDULE_TIME'));
    foreach (['due', 'future', 'draft'] as $scenario) {
        EventReservation::create([
            'restaurant_id' => $restaurant->id, 'title' => $prefix.'_'.$scenario,
            'customer_name' => $prefix, 'customer_phone' => '0000000000', 'customer_email' => $prefix.'@example.invalid',
            'start_at' => $dueTime->copy()->addHours($scenario === 'future' ? 48 : 12),
            'end_at' => $dueTime->copy()->addHours($scenario === 'future' ? 51 : 15),
            'status' => $scenario === 'draft' ? 'draft' : 'confirmed',
        ]);
    }
    App\Jobs\ProvisionRestaurantDomainJob::dispatch($restaurant->id);
    if (DB::table('jobs')->count() !== 1 || $restaurant->fresh()->custom_domain_status !== 'pending_dns') {
        throw new RuntimeException('Queued job must remain pending until a real worker runs.');
    }
    echo json_encode(['restaurant_id' => $restaurant->id, 'queued' => 1, 'invoice_id' => $invoice->id]);
} elseif ($mode === 'worker-check') {
    $restaurant = Restaurant::where('name', $prefix)->firstOrFail();
    if ($restaurant->custom_domain_status !== 'active' || ! $restaurant->ssl_issued_at
        || $restaurant->domains()->whereNotNull('verified_at')->count() !== 1
        || DB::table('jobs')->count() !== 0 || DB::table('failed_jobs')->count() !== 0) {
        throw new RuntimeException('Worker did not complete the queued domain bridge transaction.');
    }
    echo json_encode(['pending_jobs' => 0, 'failed_jobs' => 0, 'verified_domain_rows' => 1]);
} elseif ($mode === 'scheduler-check') {
    $due = EventReservation::where('title', $prefix.'_due')->firstOrFail();
    $count = EventNotificationLog::where('event_reservation_id', $due->id)->count();
    if ($count !== 12 || EventNotificationLog::where('event_reservation_id', '!=', $due->id)->exists()
        || EventNotificationLog::where('notification_type', '!=', EventReservation::NOTIFICATION_T_MINUS_1D)->exists()) {
        throw new RuntimeException('Due reminder must log each target role/channel once; future/draft must not send.');
    }
    echo json_encode(['due_logs' => $count, 'future_or_draft_logs' => 0]);
} elseif ($mode === 'fingerprint') {
    $tables = DB::select('SHOW TABLES');
    $result = [];
    foreach ($tables as $row) {
        $name = array_values((array) $row)[0];
        $rows = DB::table($name)->get()->map(fn ($r) => json_encode($r))->sort()->values()->all();
        $result[$name] = ['rows' => count($rows), 'sha256' => hash('sha256', json_encode($rows))];
    }
    ksort($result);
    echo json_encode($result);
} elseif ($mode === 'rollback-check') {
    if (Schema::hasColumn('table_sessions', 'finalized_invoice_id')) {
        throw new RuntimeException('Latest migration down did not remove its column.');
    }
    if (Invoice::where('invoice_number', $prefix.'_receipt')->firstOrFail()->total !== '25.30') {
        throw new RuntimeException('Migration rollback corrupted settled invoice.');
    }
    echo json_encode(['latest_column_removed' => true, 'paid_invoice_preserved' => true]);
} elseif ($mode === 'restore-check') {
    $invoice = Invoice::where('invoice_number', $prefix.'_receipt')->firstOrFail();
    $session = App\Models\TableSession::where('finalized_invoice_id', $invoice->id)->firstOrFail();
    if ($invoice->total !== '25.30' || $invoice->status !== 'paid' || $session->status !== 'closed'
        || Illuminate\Support\Facades\Storage::disk('local')->get($invoice->pdf_path) !== $prefix.' paid USD 25.30'
        || Illuminate\Support\Facades\Crypt::decryptString(Illuminate\Support\Facades\Storage::disk('local')->get($prefix.'/key-proof.enc')) !== $prefix.'_key') {
        throw new RuntimeException('Restored receipt, money or finalization link does not match.');
    }
    echo json_encode(['total' => $invoice->total, 'status' => $invoice->status, 'finalized_invoice_link' => true, 'private_storage' => true, 'encrypted_storage_decrypted' => true]);
} else {
    throw new RuntimeException('Unknown operational fixture action.');
}
