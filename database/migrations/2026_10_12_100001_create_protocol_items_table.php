<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One intervention on a prescription, e.g. "Metformin 500 mg, once a day
     * with dinner". The name, dose and instructions are encrypted.
     *
     * Items are never edited or deleted, so adherence always points at the
     * exact dose the patient was asked to take. An adjustment is a new item
     * that points to the one it replaces (supersedes_id); the old one is
     * discontinued.
     */
    public function up(): void
    {
        Schema::create('protocol_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescription_id')->constrained()->restrictOnDelete();
            $table->string('type');

            // Encrypted
            $table->text('name');
            $table->text('dosage')->nullable();
            $table->text('instructions')->nullable();

            $table->string('frequency');
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->foreignId('supersedes_id')->nullable()->unique()->constrained('protocol_items')->restrictOnDelete();

            // Encrypted
            $table->text('adjustment_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('discontinued_at')->nullable();
            $table->foreignId('discontinued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['prescription_id', 'discontinued_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('protocol_items');
    }
};
