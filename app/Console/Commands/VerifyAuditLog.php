<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Help;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('audit:verify')]
#[Description('Verify the integrity of the audit log hash chain')]
#[Help('Each entry is sealed with an HMAC over its contents and the previous entry\'s hash, keyed with APP_KEY. Rotating APP_KEY will make every existing entry fail verification until the chain is re-sealed.')]
class VerifyAuditLog extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AuditLogger $audit): int
    {
        $expectedPrevious = AuditLogger::GENESIS_HASH;
        $count = 0;

        foreach (AuditLog::query()->orderBy('id')->lazyById(500) as $log) {
            if (! hash_equals($expectedPrevious, $log->previous_hash)) {
                $this->components->error("Chain broken at entry #{$log->id}: an earlier entry was removed, reordered or altered.");

                return self::FAILURE;
            }

            if (! hash_equals($audit->hashFor($log), $log->hash)) {
                $this->components->error("Entry #{$log->id} has been modified: its contents do not match its hash.");

                return self::FAILURE;
            }

            $expectedPrevious = $log->hash;
            $count++;
        }

        $this->components->info("Audit log intact: {$count} entries verified.");

        return self::SUCCESS;
    }
}
