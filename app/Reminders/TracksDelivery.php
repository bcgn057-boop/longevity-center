<?php

namespace App\Reminders;

use App\Models\ReminderDelivery;

/**
 * A notification that belongs to a row in the delivery log.
 */
interface TracksDelivery
{
    public function delivery(): ReminderDelivery;
}
