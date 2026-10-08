<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per recipient and channel holds the current consent. The full
     * history of grants and withdrawals is in the audit log.
     *
     * Consent is bound to the address it was given for through a keyed hash,
     * never the address itself: if the phone number or email changes, the
     * hash no longer matches and nothing is sent until consent is renewed.
     */
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->morphs('notifiable');
            $table->string('channel');
            $table->string('destination_hash', 64)->nullable();
            $table->dateTime('consented_at')->nullable();
            $table->dateTime('withdrawn_at')->nullable();
            $table->unsignedSmallInteger('consent_version')->nullable();
            $table->string('source');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['notifiable_type', 'notifiable_id', 'channel']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
