<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelopes', function (Blueprint $table) {
            $table->boolean('require_otp')->default(false)->after('required_level');
            $table->boolean('require_selfie')->default(false)->after('require_otp');
            $table->boolean('require_document')->default(false)->after('require_selfie');
            $table->boolean('require_geolocation')->default(false)->after('require_document');
        });

        // Envelopes antigos com nível "advanced" exigiam o código por e-mail.
        DB::table('envelopes')->where('required_level', 'advanced')->update(['require_otp' => true]);
    }

    public function down(): void
    {
        Schema::table('envelopes', function (Blueprint $table) {
            $table->dropColumn(['require_otp', 'require_selfie', 'require_document', 'require_geolocation']);
        });
    }
};
