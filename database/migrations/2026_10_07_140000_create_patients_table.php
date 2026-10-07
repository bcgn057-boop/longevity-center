<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Columns holding identifying or medical data are stored encrypted (text),
     * so they cannot be indexed or searched. Names stay in plain text for search.
     */
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();

            // Assigned right after insert, once the id is known.
            $table->string('medical_record_number')->nullable()->unique();

            $table->string('first_name');
            $table->string('last_name');
            $table->string('sex');

            // Encrypted
            $table->text('date_of_birth');
            $table->text('email')->nullable();
            $table->text('phone')->nullable();
            $table->text('address')->nullable();
            $table->text('medical_history')->nullable();
            $table->text('allergies')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['last_name', 'first_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
