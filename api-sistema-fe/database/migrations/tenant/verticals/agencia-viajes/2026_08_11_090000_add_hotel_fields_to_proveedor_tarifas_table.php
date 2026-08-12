<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_11_090000_add_hotel_fields_to_proveedor_tarifas_table.php
//
// Consolidación de hoteles — opciones_hotel/opciones_hotel_tarifas dejan de
// ser la única forma de vender una habitación: una tarifa de hotel ahora es
// una proveedor_tarifa más, buscable en la biblioteca del cotizador como
// cualquier otro servicio. Estos campos existían SOLO en
// opciones_hotel_tarifas (precio_costo_cama_adicional/precio_venta_cama_adicional,
// ver 2026_08_06_090000_add_cama_adicional_nino_to_opciones_hotel_tables.php)
// o no existían en ningún lado (descripcion/regimen_comida/tipo_cama) — se
// promueven acá a proveedor_tarifas, donde vive ahora la tarifa real.
// opciones_hotel_tarifas/opciones_hotel se mantienen intactas — siguen
// siendo el mecanismo de opcion_mayorista (paquetes internacionales con
// fecha fija), no se tocan en esta migración.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proveedor_tarifas', function (Blueprint $table) {
            $table->decimal('precio_costo_cama_adicional', 10, 2)->nullable()->after('precio_venta_infante');
            $table->decimal('precio_venta_cama_adicional', 10, 2)->nullable()->after('precio_costo_cama_adicional');
            $table->string('descripcion')->nullable()->after('tipo_habitacion'); // corto — "vista al mar", "balcón"
            $table->string('regimen_comida')->nullable()->after('descripcion'); // 'solo_alojamiento' | 'desayuno' | 'media_pension' | 'pension_completa' — enum a nivel app
            $table->string('tipo_cama')->nullable()->after('regimen_comida'); // sin enum — mucha variedad real (king, queen, 2 twin, etc.)
        });
    }

    public function down(): void
    {
        Schema::table('proveedor_tarifas', function (Blueprint $table) {
            $table->dropColumn(['precio_costo_cama_adicional', 'precio_venta_cama_adicional', 'descripcion', 'regimen_comida', 'tipo_cama']);
        });
    }
};
