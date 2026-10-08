<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One blood draw or lab report for a patient. Patients only see a report
     * once a doctor or practitioner has reviewed and released it. Reports are
     * never deleted.
     */
    public function up(): void
    {
        Schema::create('lab_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->dateTime('collected_at');
            $table->string('lab_name')->nullable();
            $table->string('status')->default('pending_review');

            // Encrypted
            $table->text('notes')->nullable();

            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'collected_at']);
            $table->index(['status', 'collected_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lab_reports');
    }
};
