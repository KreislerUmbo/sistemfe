<?php

// T-059 (CRM C4): el origen del primer contacto del cliente. Lo llena la primera cotización que
// llega con origen (CotizacionCreacionService) y se corrige en la ficha; las siguientes
// cotizaciones guardan el suyo sin tocar este.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('origen_contacto_id')->nullable()->constrained('crm_origenes_contacto');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origen_contacto_id');
        });
    }
};
