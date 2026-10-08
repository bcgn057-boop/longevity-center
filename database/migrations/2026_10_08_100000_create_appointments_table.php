<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Times are stored in UTC. Free-text reasons are encrypted. Appointments are
     * never deleted: mistakes are cancelled, so the booking history survives.
     */
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained('users')->restrictOnDelete();

            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->string('status')->default('scheduled');

            // Encrypted
            $table->text('reason')->nullable();

            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable(); // Encrypted

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Double-booking checks look up a clinician's or patient's appointments by time.
            $table->index(['doctor_id', 'start_time']);
            $table->index(['patient_id', 'start_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
