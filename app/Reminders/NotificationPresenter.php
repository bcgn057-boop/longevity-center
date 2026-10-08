<?php

namespace App\Reminders;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\CareReminder;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Words in-app notifications when the inbox is shown.
 *
 * Stored notifications hold ids only. The details are loaded here, after
 * checking the viewer may still see them, so a notification never shows
 * more than its owner is currently allowed to read.
 */
class NotificationPresenter
{
    /**
     * @return array{id: string, type: string, title: string, body: string|null, url: string|null, read: bool, created_at: string}
     */
    public function present(DatabaseNotification $notification, User $user): array
    {
        [$title, $body, $url] = match ($notification->type) {
            'appointment.reminder' => $this->appointmentReminder($notification, $user),
            'clinician.daily_summary' => $this->dailySummary($notification, $user),
            'care.reminder' => $this->careReminder($notification, $user),
            default => [__('Notification'), null, null],
        };

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'read' => $notification->read_at !== null,
            'created_at' => Carbon::parse($notification->created_at)->setTimezone($this->timezone())->format('D j M Y H:i'),
        ];
    }

    /**
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function appointmentReminder(DatabaseNotification $notification, User $user): array
    {
        $id = $notification->data['appointment_id'] ?? null;
        $appointment = is_int($id) ? Appointment::with('doctor:id,name')->find($id) : null;

        if ($appointment === null || Gate::forUser($user)->denies('view', $appointment)) {
            return [__('Appointment reminder'), __('This appointment is no longer available.'), null];
        }

        $start = $appointment->start_time->copy()->setTimezone($this->timezone());

        $body = __('Your appointment on :date at :time with :clinician.', [
            'date' => $start->format('D j M Y'),
            'time' => $start->format('H:i'),
            'clinician' => $appointment->doctor->name,
        ]);

        if ($appointment->status === AppointmentStatus::Cancelled) {
            $body .= ' '.__('It has since been cancelled.');
        }

        $url = $user->hasRole(UserRole::Patient)
            ? route('my-appointments.index')
            : route('appointments.show', $appointment);

        return [__('Appointment reminder'), $body, $url];
    }

    /**
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function dailySummary(DatabaseNotification $notification, User $user): array
    {
        $date = (string) ($notification->data['date'] ?? '');
        $count = (int) ($notification->data['appointments'] ?? 0);

        $body = trans_choice(
            '{1} You have :count appointment on :date.|[2,*] You have :count appointments on :date.',
            $count,
            ['count' => $count, 'date' => Carbon::parse($date)->format('D j M Y')],
        );

        $url = Gate::forUser($user)->allows('viewAny', Appointment::class)
            ? route('appointments.index', ['from' => $date, 'to' => $date, 'doctor_id' => $user->id])
            : null;

        return [__('Tomorrow\'s appointments'), $body, $url];
    }

    /**
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function careReminder(DatabaseNotification $notification, User $user): array
    {
        $id = $notification->data['care_reminder_id'] ?? null;
        $reminder = is_int($id) ? CareReminder::find($id) : null;

        if ($reminder === null || $reminder->patient->user_id !== $user->id) {
            return [__('Message from the clinic'), __('This message is no longer available.'), null];
        }

        return [$reminder->title, $reminder->message, null];
    }

    private function timezone(): string
    {
        return (string) config('clinic.timezone');
    }
}
