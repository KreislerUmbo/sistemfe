<?php

// Distingue una venta normal de un adelanto (anticipo) de cliente — ver
// módulo Adelantos. Un registro 'advance' en sales es, a la vez, el
// comprobante SUNAT del anticipo: FacturacionElectronicaController::
// enviarSunat() lo usa para forzar tipo de operación '0104' (Catálogo 51,
// Venta interna - Anticipos) en vez de calcularlo desde retencion_igv/
// is_exportacion como hace con una venta normal.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Postgres ignora after() (feature solo de MySQL en este grammar) — la
            // columna queda al final de la tabla, sin impacto funcional.
            $table->enum('type', ['sale', 'advance'])->default('sale');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
