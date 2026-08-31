<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\PendingOrderAlertOutbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeliverPendingOrderAlerts extends Command
{
    protected $signature = 'orders:deliver-pending-alerts {--limit=100}';

    protected $description = 'Retry durable pending-order alert outbox deliveries';

    public function handle(PendingOrderAlertOutbox $outbox): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $orderIds = DB::table('order_alert_outboxes')
            ->where(fn ($query) => $query->whereNull('web_push_delivered_at')->orWhereNull('mobile_push_delivered_at'))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('order_id');

        Order::query()->whereKey($orderIds)->get()->each(fn (Order $order) => $outbox->deliver($order));

        $this->info(sprintf('Processed %d pending order alert record(s).', $orderIds->count()));

        return self::SUCCESS;
    }
}
