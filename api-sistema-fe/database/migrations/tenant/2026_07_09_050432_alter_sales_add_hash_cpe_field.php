<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Hash SUNAT (DigestValue) del XML firmado — necesario para armar el
            // string del código QR de la representación impresa (ver GreenterService::extraerHashDigest()).
            // Solo se llena cuando la venta fue enviada y aceptada por SUNAT.
            $table->string('hash_cpe')->nullable()->after('xml');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('hash_cpe');
        });
    }
};
