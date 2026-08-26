<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Retira sales.state_sale (1=venta, 2="cotización") — el concepto de
// "cotización" dentro de Sale se retiró por completo: disparaba efectos
// reales (descuento de stock, cash_movement, cronograma de crédito) sin
// condicionar a state_sale, y FacturacionElectronicaController::enviarSunat()
// nunca lo validaba, así que una "cotización" con tipo fiscal podía
// terminar enviada a SUNAT. Reemplazado por el módulo independiente
// "Cotizaciones Comerciales" (commercial_quotes), sin ningún efecto fiscal
// ni de stock. Verificado antes de aplicar: 0 filas con state_sale=2 en los
// 5 tenants reales (negocio2, umbo-archivado, sandbox, umbo, agencia-demo).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('state_sale');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->smallInteger('state_sale')->default(1);
        });
    }
};
