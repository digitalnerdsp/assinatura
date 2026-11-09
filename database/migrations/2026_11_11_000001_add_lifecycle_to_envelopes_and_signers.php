<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelopes', function (Blueprint $table) {
            $table->unsignedSmallInteger('validity_days')->default(30);
            $table->unsignedSmallInteger('reminder_every_days')->nullable()->default(3); // null = sem lembretes
            $table->json('notify_channels')->nullable(); // ["email","whatsapp","sms"]; null = só e-mail
        });

        Schema::table('signers', function (Blueprint $table) {
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('last_reminded_at')->nullable();
            $table->unsignedSmallInteger('reminders_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('signers', fn (Blueprint $t) => $t->dropColumn(['invited_at', 'last_reminded_at', 'reminders_count']));
        Schema::table('envelopes', fn (Blueprint $t) => $t->dropColumn(['validity_days', 'reminder_every_days', 'notify_channels']));
    }
};
