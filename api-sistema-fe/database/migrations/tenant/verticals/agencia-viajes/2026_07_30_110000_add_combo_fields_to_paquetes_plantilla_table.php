<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_30_110000_add_combo_fields_to_paquetes_plantilla_table.php
//
// Sesión 11b4 del vertical Agencia de Viajes (plan-hoja-de-ruta-ejecucion.md) —
// REEMPLAZA el diseño original de esta fila (tabla `tours` separada +
// proveedor_tarifas.tour_id, nunca implementado). Diseño nuevo validado:
// paquetes_plantilla sigue siendo una única tabla, pero ahora distingue
// 'tour_simple' (lo que ya existía, Sesión 6) de 'paquete_combo' (nuevo:
// agrupa 2+ tours_simple, ej. "Paquete Tarapoto 3D/2N" = día 1 Alto Mayo +
// día 2 Laguna Azul). Ver plan-modulo-cotizaciones-reservas.md §3.7
// (actualizado en esta sesión) para el detalle completo.
//
// activo: borrado lógico, mismo patrón que guias.activo — todo lo existente
// hoy (Sesiones 6-11b3) son tours_simple ya activos, backfill explícito.
//
// descuento_tipo/descuento_valor/margen_minimo_pct: solo tienen sentido para
// tipo='paquete_combo' (descuento sobre la suma de tours incluidos, con piso
// de margen — mismo patrón ya usado en proveedor_tarifas.margen_minimo_pct).
// Se dejan nullable y sin CHECK cross-columna — la regla "solo aplica si
// tipo=paquete_combo" se valida en aplicación (mismo criterio ya usado en
// todo este vertical para reglas que no son expresables como constraint de
// Postgres sin acoplar demasiado el schema a la lógica de negocio).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paquetes_plantilla', function (Blueprint $table) {
            $table->string('tipo')->default('tour_simple')->after('categoria'); // 'tour_simple' | 'paquete_combo'
            $table->boolean('activo')->default(true)->after('publicado_web');
            $table->string('descuento_tipo')->nullable()->after('activo'); // 'porcentaje' | 'monto' — solo si tipo=paquete_combo
            $table->decimal('descuento_valor', 10, 2)->nullable()->after('descuento_tipo');
            $table->decimal('margen_minimo_pct', 5, 2)->nullable()->after('descuento_valor'); // piso de utilidad del combo tras el descuento
        });
    }

    public function down(): void
    {
        Schema::table('paquetes_plantilla', function (Blueprint $table) {
            $table->dropColumn(['tipo', 'activo', 'descuento_tipo', 'descuento_valor', 'margen_minimo_pct']);
        });
    }
};
