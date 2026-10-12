<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A patient's daily tick against one protocol item. One row per item per
     * day, which the patient can tick and untick; the note is encrypted.
     * Rows are kept, never deleted.
     */
    public function up(): void
    {
        Schema::create('protocol_adherences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protocol_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();

            // A calendar day in the clinic's timezone.
            $table->date('scheduled_date');

            $table->boolean('taken')->default(false);
            $table->dateTime('taken_at')->nullable();

            // Encrypted
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['protocol_item_id', 'scheduled_date']);
            $table->index(['patient_id', 'scheduled_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('protocol_adherences');
    }
};
