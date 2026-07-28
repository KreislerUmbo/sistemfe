<?php
// database/migrations/tenant/core/2026_07_28_130100_alter_sale_details_add_descripcion_detalle.php
//
// Sesión 9a del vertical Agencia de Viajes — plan-modulo-cotizaciones-reservas.md
// §6.1. Tabla CORE compartida (`sale_details`), igual que
// 2026_07_28_130000_alter_products_add_controla_stock.php — corre sobre
// TODOS los tenants.
//
// descripcion_detalle: nullable, sin default más allá de null — retail
// sigue funcionando exactamente igual, esta columna queda sin usar para
// esos tenants. Detalle real concatenado de qué incluye la línea (ej.
// "Hotel Río Cumbaza (2 noches) + Tour a Lamas"), en vez de depender del
// product.title genérico de los 5 productos de viaje.
//
// NOTA (hallazgo, no un problema de esta migración): `sale_details` ya
// tiene una columna `description` (text, nullable, desde la migración
// original) pero está muerta en la práctica — confirmado por grep,
// SaleController::store()/update() nunca la puebla en $campos_detalle. No
// se reutiliza esa columna para este propósito porque el plan
// (escrito antes de esta sesión) ya especifica `descripcion_detalle` como
// campo nuevo, con semántica propia (resumen concatenado del servicio,
// no una descripción libre genérica) — cambiarlo ahora sería desviarse de
// un plan ya aprobado sin que nadie lo haya pedido.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_details', function (Blueprint $table) {
            $table->text('descripcion_detalle')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('sale_details', function (Blueprint $table) {
            $table->dropColumn('descripcion_detalle');
        });
    }
};
