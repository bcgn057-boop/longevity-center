<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Why a correction was made (e.g. "transcription error"). Encrypted, and
     * required on every result that corrects an earlier one.
     */
    public function up(): void
    {
        Schema::table('lab_results', function (Blueprint $table) {
            $table->text('correction_reason')->nullable()->after('comment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lab_results', function (Blueprint $table) {
            $table->dropColumn('correction_reason');
        });
    }
};
