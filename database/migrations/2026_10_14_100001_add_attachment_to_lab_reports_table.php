<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The lab's original PDF, if one was attached. The file itself is stored
     * encrypted on the private lab_attachments disk; the original file name
     * can name the patient, so it is encrypted too. The checksum is of the
     * original file, to show it has not changed since upload.
     */
    public function up(): void
    {
        Schema::table('lab_reports', function (Blueprint $table) {
            $table->string('attachment_path')->nullable()->after('notes');

            // Encrypted
            $table->text('attachment_name')->nullable()->after('attachment_path');

            $table->unsignedInteger('attachment_size')->nullable()->after('attachment_name');
            $table->char('attachment_sha256', 64)->nullable()->after('attachment_size');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lab_reports', function (Blueprint $table) {
            $table->dropColumn(['attachment_path', 'attachment_name', 'attachment_size', 'attachment_sha256']);
        });
    }
};
