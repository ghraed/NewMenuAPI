<?php

namespace App\Events;

use App\Models\EventReservation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EventPlanningNotification implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public EventReservation $eventReservation,
        public array $payload
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('restaurant.'.$this->eventReservation->restaurant_id.'.events'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'event-planning.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'event' => $this->payload,
        ];
    }
}

