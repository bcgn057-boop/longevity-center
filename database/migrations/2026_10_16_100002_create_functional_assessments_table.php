<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A set of measurements taken on one day: at-home (height, weight,
     * waist, blood pressure) or at-center functional tests (grip strength,
     * chair stand, 6-minute walk). The values and note are encrypted.
     * Entries are never edited or deleted; a correction is a new entry.
     */
    public function up(): void
    {
        Schema::create('functional_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->string('kind');
            $table->date('measured_on');

            // Encrypted JSON of field => value.
            $table->text('measurements');

            // Encrypted
            $table->text('notes')->nullable();

            // Who entered it and where: the patient's portal or the clinic.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recorded_via');
            $table->timestamps();

            $table->index(['patient_id', 'kind', 'measured_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('functional_assessments');
    }
};
