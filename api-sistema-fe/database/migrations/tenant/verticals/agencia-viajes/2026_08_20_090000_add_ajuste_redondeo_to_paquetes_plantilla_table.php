<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_20_090000_add_ajuste_redondeo_to_paquetes_plantilla_table.php
//
// Fix "ajuste de redondeo en precio de tours/combos" (brief 2026-08-18,
// ejecutado DESPUÉS del fix de ítems sueltos del combo — mismo repo, rama
// separada). El vendedor arma un tour/combo con ítems reales cuya suma da
// un número no redondo (ej. S/93.66) pero el negocio quiere cobrar un
// número redondo (ej. S/100) — hoy no existe ningún mecanismo consistente:
// precio_venta_final es puramente decorativo (desdePlantilla() nunca lo
// usa, solo la suma de ítems) y descuento_valor solo permite restar
// (min:0 en el validator), nunca sumar.
//
// Diseño acordado con el usuario: campo propio y permanente, positivo O
// negativo (a diferencia de descuento_valor), aplica a AMBOS tipos
// (tour_simple y paquete_combo) — mismo campo, mismo mecanismo para los
// dos. null = sin ajuste, comportamiento actual sin cambios para cualquier
// tour/combo existente que no lo use.
//
// Se descartó modelarlo como una fila más en paquete_plantilla_items (un
// "servicio suelto falso" sin proveedor/guía/tour real detrás) porque
// rompería ComboValidationService::validarExclusividadMutua() (cada ítem
// del combo/tour debe ser un servicio real y auditable) y contaminaría
// cualquier reporte futuro de "qué proveedores incluye este paquete".

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paquetes_plantilla', function (Blueprint $table) {
            $table->decimal('ajuste_redondeo', 10, 2)->nullable()->after('margen_minimo_pct');
        });
    }

    public function down(): void
    {
        Schema::table('paquetes_plantilla', function (Blueprint $table) {
            $table->dropColumn('ajuste_redondeo');
        });
    }
};
