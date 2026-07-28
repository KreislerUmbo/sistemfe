<?php
// database/migrations/tenant/core/2026_07_28_130000_alter_products_add_controla_stock.php
//
// Sesión 9a del vertical Agencia de Viajes (plan-hoja-de-ruta-ejecucion.md) —
// plan-modulo-cotizaciones-reservas.md §6.1. A diferencia de todas las
// migraciones anteriores del vertical (tenant/verticals/agencia-viajes/),
// esta vive en tenant/core/ porque `products` es una tabla CORE compartida
// — corre sobre TODOS los tenants al hacer tenants:migrate, no solo
// agencia_viajes.
//
// controla_stock: default TRUE — el comportamiento de retail no cambia en
// absoluto (decrement/increment de stock sigue corriendo igual para todo
// producto existente). Queda en `false` únicamente para los 5 productos
// genéricos de viaje sembrados por
// ReglaCancelacionSeeder-style-seeder... ver
// database/seeders/ProductoGenericoViajeSeeder.php (Sesión 9a, standalone,
// vive en tenant/verticals/agencia-viajes/ porque solo tiene sentido para
// tenants agencia_viajes — el ALTER en sí es neutral para cualquier giro).
//
// Esta migración SOLO agrega la columna — no toca
// SaleController::store()/update() (que hoy decrementa stock sin
// condición). Conectar `controla_stock` a esa lógica es trabajo de una
// sesión posterior (la reserva→Sale de §6.2+), documentado, no resuelto
// acá a propósito: el plan pide explícitamente no tocar la lógica de
// retail existente en esta migración.
//
// Decisión explícita del plan, documentada acá para que no se pierda:
// NO se hace `sale_details.product_id` nullable (cambio más riesgoso al
// core compartido) — en su lugar, un servicio de viaje se factura contra
// uno de estos 5 productos genéricos con controla_stock=false.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('controla_stock')->default(true)->after('contenido_neto_litros');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('controla_stock');
        });
    }
};
