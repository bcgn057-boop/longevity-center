<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Staff-written reminders for a patient (e.g. "Book your follow-up blood
     * test"). The text may be clinical, so it is encrypted and only shown in
     * the patient portal; email and WhatsApp only say there is a new message.
     * Reminders are cancelled, never deleted.
     */
    public function up(): void
    {
        Schema::create('care_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();

            // Encrypted
            $table->text('title');
            $table->text('message')->nullable();

            $table->dateTime('due_at');
            $table->string('status')->default('scheduled');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'due_at']);
            $table->index(['patient_id', 'due_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('care_reminders');
    }
};
