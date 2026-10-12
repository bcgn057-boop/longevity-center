<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A protocol a doctor issues to a patient: a named set of interventions
     * with the doctor's notes. The title and notes can reveal a condition, so
     * they are encrypted. Prescriptions are discontinued, never deleted.
     */
    public function up(): void
    {
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();

            // Encrypted
            $table->text('title');
            $table->text('notes')->nullable();

            $table->string('status')->default('active');

            // Calendar days in the clinic's timezone.
            $table->date('starts_on');
            $table->date('ends_on')->nullable();

            $table->foreignId('prescribed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('discontinued_at')->nullable();
            $table->foreignId('discontinued_by')->nullable()->constrained('users')->nullOnDelete();

            // Encrypted
            $table->text('discontinued_reason')->nullable();

            $table->timestamps();

            $table->index(['patient_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prescriptions');
    }
};
