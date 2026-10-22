<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A patient's intake questionnaire. Each section is a JSON document,
     * encrypted at rest. The secure link's token is stored only as a hash.
     * Forms are kept, never deleted.
     */
    public function up(): void
    {
        Schema::create('patient_intake_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();

            // The clinician who sent or started the form.
            $table->foreignId('doctor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status')->default('draft');

            // sha256 of the emailed link's token; the token itself is never stored.
            $table->char('token_hash', 64)->nullable()->unique();
            $table->dateTime('token_expires_at')->nullable();

            // Encrypted JSON, one per section.
            $table->text('demographics')->nullable();
            $table->text('conditions')->nullable();
            $table->text('surgeries')->nullable();
            $table->text('medications')->nullable();
            $table->text('lifestyle')->nullable();
            $table->text('family_history')->nullable();
            $table->text('vaccinations')->nullable();
            $table->text('womens_health')->nullable();

            // Encrypted JSON: clinician-only measurements, functional tests and scores.
            $table->text('clinical')->nullable();

            $table->dateTime('sent_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['patient_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_intake_forms');
    }
};
