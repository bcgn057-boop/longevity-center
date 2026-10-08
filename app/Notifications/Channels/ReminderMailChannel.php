<?php

namespace App\Notifications\Channels;

use App\Reminders\ReminderDeliveryException;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * The mail channel for reminders. A mail server's error often repeats the
 * recipient's address, so failures are replaced with a short code before
 * they can reach laravel.log or the failed_jobs table.
 */
class ReminderMailChannel extends MailChannel
{
    /**
     * @param  mixed  $notifiable
     */
    public function send($notifiable, Notification $notification)
    {
        try {
            return parent::send($notifiable, $notification);
        } catch (Throwable) {
            throw new ReminderDeliveryException('mail_transport_error');
        }
    }
}
