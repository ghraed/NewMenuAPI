<?php

require __DIR__.'/bootstrap.php';
if (getenv('QA_OPERATIONAL') !== '1') {
    throw new RuntimeException('Task 9 recovery requires guarded operational transports.');
}
$prefix = 'QA_RUN_'.getenv('QA_RUN_ID').'_operations';
$restaurant = App\Models\Restaurant::where('name', $prefix)->firstOrFail();
$session = $restaurant->tableSessions()->firstOrFail();
$mode = $argv[1] ?? '';
$key = hash('sha256', $prefix.'_recovery_request');
$payload = hash('sha256', $prefix.'_recovery_payload');
if ($mode === 'seed') {
    $order = App\Models\Order::factory()->for($restaurant)->create([
        'table_session_id' => $session->id, 'restaurant_table_id' => $session->restaurant_table_id,
        'order_number' => $prefix.'_request_order', 'guest_name' => $prefix,
        'status' => App\Models\Order::STATUS_PENDING_STAFF_CONFIRMATION, 'total' => '0.00',
    ]);
    Illuminate\Support\Facades\DB::table('guest_order_idempotency')->insert([
        'table_session_id' => $session->id, 'key_hash' => $key, 'payload_hash' => $payload,
        'order_id' => $order->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $batch = Illuminate\Support\Facades\DB::table('migrations')->where('migration', '2026_10_06_000200_add_finalized_invoice_to_table_sessions')->value('batch');
    echo json_encode(['task8_batch' => $batch, 'synthetic_request_rows' => 1]);
} elseif ($mode === 'down') {
    if (Illuminate\Support\Facades\Schema::hasTable('guest_order_idempotency')
        || ! $session->finalized_invoice_id
        || ! $restaurant->orders()->where('order_number', $prefix.'_request_order')->exists()) {
        throw new RuntimeException('Request migration rollback must preserve orders and finalization links.');
    }
    echo json_encode(['request_table_removed' => true, 'order_and_finalization_preserved' => true]);
} elseif ($mode === 'up') {
    if (! Illuminate\Support\Facades\Schema::hasTable('guest_order_idempotency')
        || Illuminate\Support\Facades\DB::table('guest_order_idempotency')->count() !== 0) {
        throw new RuntimeException('Recreated request schema must be empty before backup restoration.');
    }
    echo json_encode(['request_table_recreated' => true, 'data_restore_required' => true]);
} elseif ($mode === 'restore') {
    $row = Illuminate\Support\Facades\DB::table('guest_order_idempotency')->where('table_session_id', $session->id)->where('key_hash', $key)->first();
    if (! $row || $row->payload_hash !== $payload || App\Models\Order::findOrFail($row->order_id)->order_number !== $prefix.'_request_order') {
        throw new RuntimeException('Restored request identity must point to its original order and payload.');
    }
    echo json_encode(['restored_request_identity' => true, 'request_rows' => 1]);
} else {
    throw new RuntimeException('Unsupported Task 9 recovery fixture action.');
}
