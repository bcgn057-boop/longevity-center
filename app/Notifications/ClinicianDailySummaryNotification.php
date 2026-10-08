<?php

namespace App\Notifications;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Models\ReminderDelivery;
use App\Reminders\ReminderDeliveryException;
use App\Reminders\TracksDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Tells a clinician how many appointments they have the next day.
 *
 * In-app only, and holding just the date and a count: never patient names.
 * The clinician follows it to the schedule, where access is checked.
 */
class ClinicianDailySummaryNotification extends Notification implements ShouldQueue, TracksDelivery
{
    use Queueable;

    public const KIND = 'clinician_daily_summary';

    public int $tries;

    public function __construct(
        public ReminderDelivery $delivery,
        public string $date,
        public int $appointments,
    ) {
        $this->tries = count($this->backoff()) + 1;
        $this->afterCommit();
    }

    public function delivery(): ReminderDelivery
    {
        return $this->delivery;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return array_map(intval(...), (array) config('reminders.backoff'));
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [NotificationChannel::Database->driver()];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $delivery = $this->delivery->fresh();

        if ($delivery === null || $delivery->status !== DeliveryStatus::Queued) {
            return false;
        }

        $delivery->forceFill(['attempts' => $delivery->attempts + 1])->save();

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'date' => $this->date,
            'appointments' => $this->appointments,
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'clinician.daily_summary';
    }

    public function failed(Throwable $exception): void
    {
        $this->delivery->fresh()?->markFailed(
            $exception instanceof ReminderDeliveryException ? $exception->code() : 'error_'.class_basename($exception),
        );
    }
}
