<?php

namespace App\Notifications;

use App\Enums\DeliveryStatus;
use App\Enums\ReminderSkipReason;
use App\Models\ReminderDelivery;
use App\Reminders\ReminderDeliveryException;
use App\Reminders\TracksDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * A queued notification that belongs to one row in the delivery log and is
 * sent on that row's channel only.
 *
 * Just before sending it re-checks whether it is still due (skipReason()),
 * records the attempt, and on final failure stores a short code: never an
 * exception message, which may contain an address.
 */
abstract class TrackedNotification extends Notification implements ShouldQueue, TracksDelivery
{
    use Queueable;

    public int $tries;

    public function __construct(public ReminderDelivery $delivery)
    {
        $this->tries = count($this->backoff()) + 1;

        // Never send for a change whose transaction is rolled back.
        $this->afterCommit();
    }

    /**
     * Why the notification should no longer be sent, or null if it should.
     */
    abstract protected function skipReason(object $notifiable, ReminderDelivery $delivery): ?ReminderSkipReason;

    public function delivery(): ReminderDelivery
    {
        return $this->delivery;
    }

    /**
     * Seconds to wait before each retry.
     *
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
        return [$this->delivery->channel->driver()];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $delivery = $this->delivery->fresh();

        if ($delivery === null || $delivery->status !== DeliveryStatus::Queued) {
            return false;
        }

        $delivery->forceFill(['attempts' => $delivery->attempts + 1])->save();

        $reason = $this->skipReason($notifiable, $delivery);

        if ($reason !== null) {
            $delivery->markSkipped($reason);

            return false;
        }

        return true;
    }

    /**
     * Called once all retries are used up.
     */
    public function failed(Throwable $exception): void
    {
        $this->delivery->fresh()?->markFailed(
            $exception instanceof ReminderDeliveryException ? $exception->code() : 'error_'.class_basename($exception),
        );
    }
}
