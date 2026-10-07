<?php

namespace App\Exceptions;

use LogicException;

class MedicalRecordRetentionException extends LogicException
{
    public function __construct()
    {
        parent::__construct('Patient records must be retained and cannot be permanently deleted. Archive them instead.');
    }
}
