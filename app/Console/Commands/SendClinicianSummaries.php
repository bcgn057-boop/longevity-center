<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Enums\NotificationChannel;
use App\Models\Appointment;
use App\Models\ReminderDelivery;
use App\Models\User;
use App\Notifications\ClinicianDailySummaryNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Help;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('reminders:clinician-summaries')]
#[Description('Send each clinician an in-app summary of tomorrow\'s appointments')]
#[Help('Runs hourly and does nothing before reminders.clinician_summary_hour (clinic timezone). Each clinician gets at most one summary per day, holding the date and a count only.')]
class SendClinicianSummaries extends Command
{
    /**
     * Execute the console command. Output holds counts only, never names.
     */
    public function handle(): int
    {
        if (! NotificationChannel::Database->enabled()) {
            $this->components->info('In-app notifications are disabled.');

            return self::SUCCESS;
        }

        $timezone = (string) config('clinic.timezone');
        $now = Carbon::now($timezone);

        if ($now->hour < (int) config('reminders.clinician_summary_hour')) {
            $this->components->info('Too early: summaries are sent from '.config('reminders.clinician_summary_hour').':00.');

            return self::SUCCESS;
        }

        $day = $now->copy()->addDay()->startOfDay();

        $counts = Appointment::query()
            ->where('status', AppointmentStatus::Scheduled)
            ->where('start_time', '>=', $day->copy()->utc()->format('Y-m-d H:i:s'))
            ->where('start_time', '<', $day->copy()->addDay()->utc()->format('Y-m-d H:i:s'))
            ->selectRaw('doctor_id, count(*) as total')
            ->groupBy('doctor_id')
            ->pluck('total', 'doctor_id');

        $sent = 0;

        foreach (User::clinicians()->whereIn('id', $counts->keys())->get() as $clinician) {
            $delivery = ReminderDelivery::claim(
                $clinician,
                $clinician,
                NotificationChannel::Database,
                ClinicianDailySummaryNotification::KIND,
                $day->copy()->utc(),
            );

            if ($delivery === null) {
                continue;
            }

            $clinician->notify(new ClinicianDailySummaryNotification(
                $delivery,
                $day->format('Y-m-d'),
                (int) $counts[$clinician->id],
            ));
            $sent++;
        }

        $this->components->info("Queued {$sent} clinician summaries for {$day->format('Y-m-d')}.");

        return self::SUCCESS;
    }
}
