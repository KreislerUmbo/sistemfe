<?php

// T-059 (CRM C4): por dónde llegó esta consulta. El catálogo vive en core (crm_origenes_contacto),
// en la misma base del tenant, así que la FK es real; core se migra antes que los verticales.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->foreignId('origen_contacto_id')->nullable()->constrained('crm_origenes_contacto');
        });
    }

    public function down(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origen_contacto_id');
        });
    }
};
