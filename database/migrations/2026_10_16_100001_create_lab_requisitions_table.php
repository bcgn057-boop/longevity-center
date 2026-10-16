<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A clinician's order for tests from one lab partner, for one patient.
     * The partner's lab users see only their own requisitions, and enter
     * reports against them. Instructions are encrypted. Requisitions are
     * cancelled, never deleted.
     *
     * Reports record their partner (null for the clinic's own lab), so lab
     * users can be limited to their partner's reports.
     */
    public function up(): void
    {
        Schema::create('lab_requisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('lab_partner_id')->constrained()->restrictOnDelete();

            // Codes from the partner's test menu.
            $table->json('tests');

            // Encrypted
            $table->text('instructions')->nullable();

            $table->string('status')->default('ordered');
            $table->foreignId('ordered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reported_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['lab_partner_id', 'status']);
            $table->index(['patient_id', 'created_at']);
        });

        Schema::table('lab_reports', function (Blueprint $table) {
            $table->foreignId('lab_partner_id')->nullable()->after('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('lab_requisition_id')->nullable()->after('lab_partner_id')->constrained()->restrictOnDelete();

            $table->index(['lab_partner_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lab_reports', function (Blueprint $table) {
            $table->dropIndex(['lab_partner_id', 'status']);
            $table->dropConstrainedForeignId('lab_requisition_id');
            $table->dropConstrainedForeignId('lab_partner_id');
        });

        Schema::dropIfExists('lab_requisitions');
    }
};
