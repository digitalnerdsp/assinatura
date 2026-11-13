<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signers', function (Blueprint $table) {
            $table->boolean('is_sender')->default(false);
            $table->string('signed_via', 20)->nullable(); // link | session | api_key
        });

        Schema::table('tenant_signing_settings', function (Blueprint $table) {
            $table->string('sender_policy', 20)->default('always'); // always | optional | never
            $table->string('representative_name')->nullable();
            $table->string('representative_email')->nullable();
            $table->string('representative_cpf', 14)->nullable();
            $table->timestampTz('delegation_accepted_at')->nullable();
            $table->foreignId('delegation_accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('delegation_accepted_ip', 45)->nullable();
        });

        Schema::table('api_keys', function (Blueprint $table) {
            $table->boolean('can_sign_as_company')->default(false);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('signature_type', 10)->nullable(); // drawn | typed
            $table->text('signature_data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['signature_type', 'signature_data']));
        Schema::table('api_keys', fn (Blueprint $t) => $t->dropColumn('can_sign_as_company'));
        Schema::table('tenant_signing_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delegation_accepted_by');
            $table->dropColumn([
                'sender_policy', 'representative_name', 'representative_email', 'representative_cpf',
                'delegation_accepted_at', 'delegation_accepted_ip',
            ]);
        });
        Schema::table('signers', fn (Blueprint $t) => $t->dropColumn(['is_sender', 'signed_via']));
    }
};
