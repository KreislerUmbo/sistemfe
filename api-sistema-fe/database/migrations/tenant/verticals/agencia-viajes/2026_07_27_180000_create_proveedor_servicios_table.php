<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_27_180000_create_proveedor_servicios_table.php
//
// Sesión 4 del vertical Agencia de Viajes (plan-hoja-de-ruta-ejecucion.md) —
// plan-modulo-proveedores.md §2.6 ("Relación con destino_servicio, costo,
// margen y piso de descuento", confirmado 24-jul-2026). Tabla puente pura,
// sin columnas extra documentadas más allá de las 2 FK — el costo/margen/
// piso de descuento vive en proveedor_tarifas (Sesión 5, no acá).
//
// Ambas FK son reales: proveedores (Sesión 3) y destino_servicio (Sesión 3)
// están en la misma DB tenant — a diferencia de las referencias cross-DB a
// proveedor_tipos (central), que nunca llevan FK real.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedor_servicios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->foreignId('destino_servicio_id')->constrained('destino_servicio');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedor_servicios');
    }
};
