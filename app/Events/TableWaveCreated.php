<?php

namespace App\Events;

use App\Models\TableWave;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TableWaveCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public TableWave $wave,
        public array $payload
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('restaurant.'.$this->wave->restaurant_id.'.table.'.$this->wave->restaurant_table_id.'.waves'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'table-wave.created';
    }

    public function broadcastWith(): array
    {
        return [
            'wave' => $this->payload,
        ];
    }
}
