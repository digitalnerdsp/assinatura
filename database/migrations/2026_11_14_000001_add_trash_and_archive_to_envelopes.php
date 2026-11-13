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
            $table->timestampTz('trashed_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->index(['tenant_id', 'trashed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('envelopes', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'trashed_at']);
            $table->dropColumn(['trashed_at', 'archived_at']);
        });
    }
};
