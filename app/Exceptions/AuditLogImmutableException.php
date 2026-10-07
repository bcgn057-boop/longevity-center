<?php

namespace App\Exceptions;

use LogicException;

class AuditLogImmutableException extends LogicException
{
    public static function update(): self
    {
        return new self('Audit log entries are append-only and cannot be updated.');
    }

    public static function delete(): self
    {
        return new self('Audit log entries are append-only and cannot be deleted.');
    }
}
