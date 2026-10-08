<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One measured value. The value and comment are encrypted; the low/high
     * flag is worked out when shown, never stored, so nothing in plain text
     * reveals whether a result is abnormal.
     *
     * Results are never changed or deleted: a correction is a new row that
     * points to the one it replaces (supersedes_id).
     */
    public function up(): void
    {
        Schema::create('lab_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_report_id')->constrained()->restrictOnDelete();
            $table->foreignId('biomarker_id')->constrained()->restrictOnDelete();

            // Encrypted, e.g. "5.4" or "<0.5"
            $table->text('value');
            $table->string('unit');

            // The range printed on the lab's report, in the result's unit.
            $table->decimal('reference_low', 14, 4)->nullable();
            $table->decimal('reference_high', 14, 4)->nullable();

            // Encrypted
            $table->text('comment')->nullable();

            $table->foreignId('supersedes_id')->nullable()->unique()->constrained('lab_results')->restrictOnDelete();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['lab_report_id', 'biomarker_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lab_results');
    }
};
