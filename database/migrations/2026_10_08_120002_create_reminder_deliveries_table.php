<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The dispatch log: one row per reminder, recipient, channel and appointment
     * time. The unique key means a reminder can never be sent twice, while
     * moving the appointment to a new time allows a fresh reminder.
     *
     * Rows hold ids and short codes only: never a phone number, an email
     * address, message text or a provider's response body.
     */
    public function up(): void
    {
        Schema::create('reminder_deliveries', function (Blueprint $table) {
            $table->id();
            $table->morphs('remindable');
            $table->morphs('recipient');
            $table->string('channel');
            $table->string('kind');
            $table->dateTime('scheduled_for');
            $table->string('status');
            $table->string('reason_code', 64)->nullable();
            $table->string('provider_message_id')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['remindable_type', 'remindable_id', 'recipient_type', 'recipient_id', 'channel', 'kind', 'scheduled_for'],
                'reminder_deliveries_once',
            );
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminder_deliveries');
    }
};
