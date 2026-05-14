<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AccountingOrderCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public Order $order,
        public array $payload
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('restaurant.'.$this->order->restaurant_id.'.accounting'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'accounting-order.created';
    }

    public function broadcastWith(): array
    {
        return [
            'order' => $this->payload,
        ];
    }
}
