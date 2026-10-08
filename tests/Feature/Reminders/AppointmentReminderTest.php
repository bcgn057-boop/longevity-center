<?php

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\NotificationPreference;
use App\Models\Patient;
use App\Models\ReminderDelivery;
use App\Notifications\AppointmentReminderNotification;
use App\Notifications\Channels\ReminderMailChannel;
use App\Notifications\Channels\WhatsAppChannel;
use App\Reminders\NotificationConsent;
use App\Reminders\ReminderDeliveryException;
use App\Reminders\ReminderDispatcher;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

const META_URL = 'https://graph.facebook.com/v23.0/123456789/messages';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-01 10:00:00', 'UTC'));

    config([
        'clinic.name' => 'Longevity Center',
        'reminders.channels.whatsapp.enabled' => true,
        'reminders.channels.whatsapp.provider' => 'meta',
        'reminders.channels.whatsapp.template' => 'appointment_reminder',
        'services.meta_whatsapp.token' => 'test-token',
        'services.meta_whatsapp.phone_number_id' => '123456789',
        'services.meta_whatsapp.api_version' => 'v23.0',
    ]);

    Http::preventStrayRequests();

    $this->account = userWithRole(UserRole::Patient);
    $this->patient = Patient::factory()->withAccount($this->account)->create([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
        'phone' => '+14155550123',
    ]);
    $this->appointment = Appointment::factory()
        ->for($this->patient)
        ->at('2026-11-02 09:30')
        ->create(['reason' => 'Discuss thyroid results']);
});

function consentTo(NotificationChannel ...$channels): void
{
    foreach ($channels as $channel) {
        app(NotificationConsent::class)->grant(test()->patient, $channel, NotificationPreference::SOURCE_STAFF, null);
    }
}

function remind(): array
{
    return app(ReminderDispatcher::class)->remindPatient(test()->appointment);
}

function delivery(NotificationChannel $channel): ?ReminderDelivery
{
    return ReminderDelivery::where('channel', $channel)->first();
}

function fakeMeta(int $status = 200, array $body = ['messages' => [['id' => 'wamid.TEST123']]]): void
{
    Http::fake([META_URL => Http::response($body, $status)]);
}

describe('queueing', function () {
    test('reminders are queued, one job per consented channel', function () {
        Queue::fake();
        consentTo(NotificationChannel::WhatsApp, NotificationChannel::Mail);

        remind();

        Queue::assertPushed(SendQueuedNotifications::class, 3);
        Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job) => $job->channels === [WhatsAppChannel::class]
            && $job->notification instanceof AppointmentReminderNotification);

        expect(ReminderDelivery::where('status', DeliveryStatus::Queued)->count())->toBe(3);
    });

    test('a channel without consent is skipped and never queued', function () {
        Queue::fake();

        remind();

        Queue::assertPushed(SendQueuedNotifications::class, 1); // in-app only
        Queue::assertNotPushed(SendQueuedNotifications::class, fn ($job) => $job->channels === [WhatsAppChannel::class]);

        expect(delivery(NotificationChannel::WhatsApp)->status)->toBe(DeliveryStatus::Skipped)
            ->and(delivery(NotificationChannel::WhatsApp)->reason_code)->toBe('no_consent')
            ->and(delivery(NotificationChannel::Mail)->reason_code)->toBe('no_consent');
    });

    test('a disabled channel is not considered at all', function () {
        Queue::fake();
        config(['reminders.channels.whatsapp.enabled' => false]);
        consentTo(NotificationChannel::WhatsApp);

        remind();

        expect(delivery(NotificationChannel::WhatsApp))->toBeNull();
    });

    test('the same reminder is never sent twice', function () {
        Queue::fake();
        consentTo(NotificationChannel::WhatsApp);

        expect(remind())->toHaveCount(3)
            ->and(remind())->toHaveCount(0);

        Queue::assertPushed(SendQueuedNotifications::class, 2);
        expect(ReminderDelivery::count())->toBe(3);
    });

    test('moving the appointment allows a fresh reminder for the new time', function () {
        Queue::fake();
        remind();

        $this->appointment->update(['start_time' => '2026-11-02 11:00', 'end_time' => '2026-11-02 11:30']);

        expect(remind())->toHaveCount(3);
    });

    test('cancelled appointments get no reminders', function () {
        Queue::fake();
        $this->appointment->cancel(null);

        expect(remind())->toBe([]);
        Queue::assertNothingPushed();
    });
});

describe('whatsapp', function () {
    test('an opted-in patient is sent the template and nothing else', function () {
        fakeMeta();
        consentTo(NotificationChannel::WhatsApp);

        remind();

        Http::assertSent(fn (Request $request) => $request->url() === META_URL
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->data() === [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => '14155550123',
                'type' => 'template',
                'template' => ['name' => 'appointment_reminder', 'language' => ['code' => 'en']],
            ]);

        $delivery = delivery(NotificationChannel::WhatsApp);

        expect($delivery->status)->toBe(DeliveryStatus::Sent)
            ->and($delivery->provider_message_id)->toBe('wamid.TEST123')
            ->and($delivery->sent_at)->not->toBeNull();
    });

    test('the request to the provider carries no patient or clinical details', function () {
        fakeMeta();
        consentTo(NotificationChannel::WhatsApp);

        remind();

        Http::assertSent(function (Request $request) {
            $body = $request->body();

            foreach (['Ada', 'Lovelace', 'thyroid', '2026-11-02', '09:30', 'LC-', $this->appointment->doctor->name] as $detail) {
                if (str_contains($body, $detail)) {
                    return false;
                }
            }

            return true;
        });
    });

    test('missing consent skips gracefully without calling the provider', function () {
        fakeMeta();

        remind();

        Http::assertNothingSent();
        expect(delivery(NotificationChannel::WhatsApp)->reason_code)->toBe('no_consent');
    });

    test('a changed phone number skips until consent is renewed', function () {
        fakeMeta();
        consentTo(NotificationChannel::WhatsApp);
        $this->patient->update(['phone' => '+14155550999']);

        remind();

        Http::assertNothingSent();
        expect(delivery(NotificationChannel::WhatsApp)->reason_code)->toBe('destination_changed');
    });

    test('patients without a portal account are not sent WhatsApp messages', function () {
        fakeMeta();
        consentTo(NotificationChannel::WhatsApp);
        $this->patient->user()->dissociate()->save();

        remind();

        Http::assertNothingSent();
        expect(delivery(NotificationChannel::WhatsApp)->reason_code)->toBe('no_portal_account')
            ->and(delivery(NotificationChannel::Database)->reason_code)->toBe('no_portal_account');
    });

    test('provider errors are recorded as a code only', function (int $status, array $body, string $code) {
        fakeMeta($status, $body);
        consentTo(NotificationChannel::WhatsApp);

        remind();

        expect(delivery(NotificationChannel::WhatsApp)->status)->toBe(DeliveryStatus::Failed)
            ->and(delivery(NotificationChannel::WhatsApp)->reason_code)->toBe($code);
    })->with([
        'rate limited' => [429, [], 'provider_http_429'],
        'meta error' => [400, ['error' => ['code' => 131026, 'message' => 'Message undeliverable to +14155550123']], 'meta_error_131026'],
        'bad response' => [200, ['unexpected' => true], 'provider_bad_response'],
    ]);

    test('a missing Meta configuration fails safely', function () {
        config(['services.meta_whatsapp.token' => null]);
        consentTo(NotificationChannel::WhatsApp);

        remind();

        expect(delivery(NotificationChannel::WhatsApp)->reason_code)->toBe('meta_not_configured');
    });

    test('the log provider sends nothing and logs only a masked number', function () {
        config(['reminders.channels.whatsapp.provider' => 'log']);
        Log::spy();
        consentTo(NotificationChannel::WhatsApp);

        remind();

        Http::assertNothingSent();
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $context['to'] === '+•••••••••23'
            && $context['template'] === 'appointment_reminder'
            && ! str_contains(json_encode($context), '4155550123'));

        expect(delivery(NotificationChannel::WhatsApp)->status)->toBe(DeliveryStatus::Sent)
            ->and(delivery(NotificationChannel::WhatsApp)->provider_message_id)->toStartWith('log-');
    });
});

describe('re-checking when the reminder is sent', function () {
    function runQueuedJobs(): void
    {
        foreach (Queue::pushed(SendQueuedNotifications::class) as $job) {
            $job->handle(app(ChannelManager::class));
        }
    }

    beforeEach(function () {
        Queue::fake();
        fakeMeta();
        consentTo(NotificationChannel::WhatsApp, NotificationChannel::Mail);
        remind();
    });

    test('queued reminders are sent when nothing has changed', function () {
        runQueuedJobs();

        expect(ReminderDelivery::where('status', DeliveryStatus::Sent)->count())->toBe(3);
    });

    test('a reminder for an appointment cancelled since queueing is skipped', function () {
        $this->appointment->cancel(null);

        runQueuedJobs();

        Http::assertNothingSent();
        expect(ReminderDelivery::pluck('reason_code')->unique()->all())->toBe(['appointment_not_scheduled']);
    });

    test('a reminder for an appointment moved since queueing is skipped', function () {
        $this->appointment->update(['start_time' => '2026-11-03 09:30', 'end_time' => '2026-11-03 10:00']);

        runQueuedJobs();

        expect(ReminderDelivery::pluck('reason_code')->unique()->all())->toBe(['appointment_moved']);
    });

    test('consent withdrawn since queueing stops the message', function () {
        app(NotificationConsent::class)->withdraw($this->patient, NotificationChannel::WhatsApp, NotificationPreference::SOURCE_SELF, null);

        runQueuedJobs();

        Http::assertNothingSent();
        expect(delivery(NotificationChannel::WhatsApp)->reason_code)->toBe('no_consent')
            ->and(delivery(NotificationChannel::Mail)->status)->toBe(DeliveryStatus::Sent);
    });

    test('a job that runs twice sends once', function () {
        runQueuedJobs();
        runQueuedJobs();

        Http::assertSentCount(1);
    });
});

describe('mail failures', function () {
    test('a mail server error is recorded as a code and never repeats the address', function () {
        Mail::extend('failing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('550 Mailbox ada@example.com unavailable');
            }

            public function __toString(): string
            {
                return 'failing';
            }
        });
        config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);
        consentTo(NotificationChannel::Mail);
        $reported = [];
        app(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported) {
            $reported[] = $e->getMessage();
        });

        remind();

        expect(delivery(NotificationChannel::Mail)->status)->toBe(DeliveryStatus::Failed)
            ->and(delivery(NotificationChannel::Mail)->reason_code)->toBe('mail_transport_error')
            ->and(json_encode($reported))->not->toContain('ada@example.com');
    });

    test('the mail channel never chains the original error', function () {
        Mail::extend('failing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('550 Mailbox ada@example.com unavailable');
            }

            public function __toString(): string
            {
                return 'failing';
            }
        });
        config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);
        $delivery = new ReminderDelivery;
        $delivery->channel = NotificationChannel::Mail;

        try {
            app(ReminderMailChannel::class)->send($this->patient, new AppointmentReminderNotification($this->appointment, $delivery));
            $this->fail('Expected the send to fail.');
        } catch (ReminderDeliveryException $e) {
            expect($e->getMessage())->toBe('mail_transport_error')
                ->and($e->getPrevious())->toBeNull();
        }
    });
});

describe('content', function () {
    test('the email gives the clinic, date and time and a portal link, nothing clinical', function () {
        $delivery = new ReminderDelivery;
        $delivery->channel = NotificationChannel::Mail;
        config(['clinic.timezone' => 'Asia/Kolkata']);

        $mail = (new AppointmentReminderNotification($this->appointment, $delivery))->toMail($this->patient);
        $html = (string) $mail->render();

        expect($html)
            ->toContain('Longevity Center')
            ->toContain('Monday 2 November 2026')
            ->toContain('15:00')
            ->toContain(route('my-appointments.index'))
            ->not->toContain('Ada')
            ->not->toContain('Lovelace')
            ->not->toContain('thyroid')
            ->not->toContain($this->patient->medical_record_number)
            ->not->toContain($this->appointment->doctor->name);
    });

    test('patients without an account are told to contact the clinic instead', function () {
        $this->patient->user()->dissociate()->save();
        $delivery = new ReminderDelivery;
        $delivery->channel = NotificationChannel::Mail;

        $html = (string) (new AppointmentReminderNotification($this->appointment, $delivery))->toMail($this->patient)->render();

        expect($html)->toContain('please contact the clinic')
            ->not->toContain(route('my-appointments.index'));
    });

    test('in-app reminders go to the linked account and hold ids only', function () {
        remind();

        $notification = $this->account->notifications()->sole();

        expect($notification->type)->toBe('appointment.reminder')
            ->and($notification->data)->toBe(['appointment_id' => $this->appointment->id])
            ->and(delivery(NotificationChannel::Database)->status)->toBe(DeliveryStatus::Sent);
    });

    test('email reminders are sent to the address on the patient record', function () {
        Notification::fake();
        consentTo(NotificationChannel::Mail);

        remind();

        Notification::assertSentTo(
            $this->patient,
            AppointmentReminderNotification::class,
            fn ($notification, array $channels, $notifiable) => $channels === [ReminderMailChannel::class]
                && $notifiable->routeNotificationFor('mail') === 'ada@example.com',
        );
    });

    test('the delivery log and audit log hold no addresses or clinical details', function () {
        fakeMeta(400, ['error' => ['code' => 131026, 'message' => 'Undeliverable to +14155550123']]);
        consentTo(NotificationChannel::WhatsApp, NotificationChannel::Mail);

        remind();

        $logs = json_encode(ReminderDelivery::query()->toBase()->get()).json_encode(AuditLog::query()->toBase()->get());

        expect($logs)
            ->not->toContain('4155550123')
            ->not->toContain('ada@example.com')
            ->not->toContain('thyroid')
            ->not->toContain('Undeliverable');
    });
});
