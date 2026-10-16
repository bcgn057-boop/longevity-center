<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * External labs the clinic sends tests to. Each lab user belongs to one
     * partner and only ever sees that partner's requisitions and reports.
     */
    public function up(): void
    {
        Schema::create('lab_partners', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');

            // The tests the partner offers: [{code, label}, ...]
            $table->json('tests');

            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('lab_partner_id')->nullable()->after('role_id')->constrained()->restrictOnDelete();
            $table->dateTime('deactivated_at')->nullable()->after('lab_partner_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lab_partner_id');
            $table->dropColumn('deactivated_at');
        });

        Schema::dropIfExists('lab_partners');
    }
};
