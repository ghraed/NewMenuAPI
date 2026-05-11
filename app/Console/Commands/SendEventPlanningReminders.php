<?php

namespace App\Console\Commands;

use App\Models\EventNotificationLog;
use App\Models\EventReservation;
use App\Services\EventPlanningAlertService;
use Illuminate\Console\Command;

class SendEventPlanningReminders extends Command
{
    protected $signature = 'events:send-planning-reminders';

    protected $description = 'Send T-1 day planning reminders for confirmed upcoming events.';

    public function __construct(
        private readonly EventPlanningAlertService $eventPlanningAlertService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = now();
        $windowEnd = $now->copy()->addDay();

        $events = EventReservation::query()
            ->where('status', EventReservation::STATUS_CONFIRMED)
            ->where('start_at', '>', $now)
            ->where('start_at', '<=', $windowEnd)
            ->whereDoesntHave('notificationLogs', function ($query): void {
                $query->where('notification_type', EventReservation::NOTIFICATION_T_MINUS_1D);
            })
            ->get();

        $sentCount = 0;
        foreach ($events as $eventReservation) {
            $alreadyLogged = EventNotificationLog::query()
                ->where('event_reservation_id', $eventReservation->id)
                ->where('notification_type', EventReservation::NOTIFICATION_T_MINUS_1D)
                ->exists();

            if ($alreadyLogged) {
                continue;
            }

            $this->eventPlanningAlertService->dispatchTMinusOneDayReminder($eventReservation);
            $sentCount++;
        }

        $this->info(sprintf('Event planning reminders sent: %d', $sentCount));

        return self::SUCCESS;
    }
}

