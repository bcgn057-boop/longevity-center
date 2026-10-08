<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Enums\CareReminderStatus;
use App\Enums\DeliveryStatus;
use App\Models\Appointment;
use App\Models\CareReminder;
use App\Notifications\AppointmentReminderNotification;
use App\Reminders\ReminderDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Help;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;

#[Signature('reminders:dispatch')]
#[Description('Queue reminders for upcoming appointments and due care reminders')]
#[Help('Runs every 5 minutes. Care reminders are sent once their due time has passed. Each appointment starting within reminders.appointment_lead_minutes, and not sooner than reminders.minimum_notice_minutes, is reminded once per channel at its current time. Safe to run at any time: reminders already in the delivery log are never sent again.')]
class DispatchReminders extends Command
{
    /**
     * Execute the console command. Output holds counts only, never names.
     */
    public function handle(ReminderDispatcher $dispatcher): int
    {
        $from = now()->utc()->addMinutes((int) config('reminders.minimum_notice_minutes'));
        $until = now()->utc()->addMinutes((int) config('reminders.appointment_lead_minutes'));

        $appointments = 0;
        $queued = 0;
        $skipped = 0;

        Appointment::query()
            ->with('patient')
            ->where('status', AppointmentStatus::Scheduled)
            ->where('start_time', '>', $from->format('Y-m-d H:i:s'))
            ->where('start_time', '<=', $until->format('Y-m-d H:i:s'))
            ->whereNotExists(fn (Builder $query) => $query
                ->selectRaw('1')
                ->from('reminder_deliveries')
                ->where('reminder_deliveries.remindable_type', (new Appointment)->getMorphClass())
                ->whereColumn('reminder_deliveries.remindable_id', 'appointments.id')
                ->where('reminder_deliveries.kind', AppointmentReminderNotification::KIND)
                ->whereColumn('reminder_deliveries.scheduled_for', 'appointments.start_time'))
            ->chunkById(100, function (Collection $chunk) use ($dispatcher, &$appointments, &$queued, &$skipped) {
                foreach ($chunk as $appointment) {
                    $appointments++;

                    foreach ($dispatcher->remindPatient($appointment) as $delivery) {
                        $delivery->status === DeliveryStatus::Skipped ? $skipped++ : $queued++;
                    }
                }
            });

        $this->components->info("Checked {$appointments} appointments: {$queued} reminders queued, {$skipped} skipped.");

        $careReminders = 0;

        CareReminder::query()
            ->with('patient')
            ->where('status', CareReminderStatus::Scheduled)
            ->where('due_at', '<=', now()->utc()->format('Y-m-d H:i:s'))
            ->chunkById(100, function (Collection $chunk) use ($dispatcher, &$careReminders) {
                foreach ($chunk as $reminder) {
                    $dispatcher->sendCareReminder($reminder);
                    $careReminders++;
                }
            });

        $this->components->info("Sent {$careReminders} care reminders.");

        return self::SUCCESS;
    }
}
