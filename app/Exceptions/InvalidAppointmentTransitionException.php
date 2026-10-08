<?php

namespace App\Exceptions;

use App\Enums\AppointmentStatus;
use LogicException;

class InvalidAppointmentTransitionException extends LogicException
{
    public static function between(AppointmentStatus $from, AppointmentStatus $to): self
    {
        return new self("An appointment cannot change from {$from->value} to {$to->value}.");
    }

    public static function finalised(AppointmentStatus $status): self
    {
        return new self("A {$status->value} appointment can no longer be changed.");
    }
}
