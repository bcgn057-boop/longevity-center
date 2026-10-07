<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event')->index();

            // Deliberately not a foreign key: audit entries must survive user deletion
            // untouched, so nothing may cascade into this table.
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('actor_email')->nullable();

            $table->nullableMorphs('auditable');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            // Tamper-evident hash chain. previous_hash is unique so the chain can never fork.
            $table->char('previous_hash', 64)->unique();
            $table->char('hash', 64)->unique();

            $table->timestamp('created_at')->index();
        });

        $this->createAppendOnlyTriggers();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_prevent_update');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_prevent_delete');
        }

        Schema::dropIfExists('audit_logs');
    }

    /**
     * Make the database itself reject any UPDATE or DELETE on audit entries.
     */
    private function createAppendOnlyTriggers(): void
    {
        $driver = DB::getDriverName();

        if ($driver !== 'sqlite') {
            // Fail loudly rather than silently running without database-level protection.
            throw new RuntimeException("Append-only triggers for audit_logs are not implemented for the [{$driver}] driver.");
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_prevent_update
            BEFORE UPDATE ON audit_logs
            BEGIN
                SELECT RAISE(ABORT, 'audit_logs is append-only: updates are not allowed');
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_prevent_delete
            BEFORE DELETE ON audit_logs
            BEGIN
                SELECT RAISE(ABORT, 'audit_logs is append-only: deletes are not allowed');
            END
            SQL);
    }
};
