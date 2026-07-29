<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_180000_add_origen_tipo_cantidad_descripcion_manual_to_alternativa_items_table.php
//
// Sesión 11b del vertical Agencia de Viajes — RETROFIT sobre alternativa_items
// (tabla ya mergeada en Sesión 7), confirmado en la sesión de diseño del
// 28-jul-2026 (ver TODO.md "Confirmado con el usuario — alternativa_items.origen_tipo"
// y plan-modulo-cotizaciones-reservas.md §3).
//
// origen_tipo: discriminador EXPLÍCITO del origen del ítem — antes había que
// inferirlo por qué FK nullable estaba llena (proveedor_tarifa_id/
// opcion_mayorista_id), fràgil apenas se agregó un tercer origen
// (cotizacion_pasaje_aereo, §2.5, esta misma sesión) y ahora un cuarto
// (manual). Se agrega nullable, se hace el backfill, y recién ahí se
// vuelve NOT NULL (mismo patrón de 2 pasos ya usado en este vertical para
// columnas NOT NULL sobre tablas con filas existentes).
//
// Backfill: proveedor_tarifa_id lleno → 'proveedor', opcion_mayorista_id
// lleno → 'mayorista'. Las filas SIN ninguna de las 2 FK llena (si las
// hubiera) quedan como 'proveedor' — antes de este retrofit esa
// combinación (ambas null) solo podía significar "precio de referencia sin
// proveedor específico todavía" (§3, caso 2 de proveedor_tarifa_id
// nullable), nunca 'manual' (ese origen no existía hasta ahora). Solo
// tenants de prueba, sin datos reales todavía — mismo criterio ya usado en
// el backfill de proveedor_tarifas.tipo_habitacion (Sesión 11a).
//
// cantidad: default 1 desde el arranque — no rompe ningún total ya
// calculado (precio_venta_snapshot × 1 = precio_venta_snapshot).
//
// descripcion_manual: nullable, solo se llena cuando origen_tipo='manual'.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->string('origen_tipo')->nullable(); // se vuelve NOT NULL abajo, tras el backfill
            $table->unsignedInteger('cantidad')->default(1);
            $table->text('descripcion_manual')->nullable();
        });

        DB::table('alternativa_items')->whereNotNull('proveedor_tarifa_id')->update(['origen_tipo' => 'proveedor']);
        DB::table('alternativa_items')->whereNotNull('opcion_mayorista_id')->update(['origen_tipo' => 'mayorista']);
        DB::table('alternativa_items')->whereNull('origen_tipo')->update(['origen_tipo' => 'proveedor']);

        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->string('origen_tipo')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('alternativa_items', function (Blueprint $table) {
            $table->dropColumn(['origen_tipo', 'cantidad', 'descripcion_manual']);
        });
    }
};
