<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_30_110100_add_paquete_plantilla_hijo_to_paquete_plantilla_items_table.php
//
// Sesión 11b4 — permite que un ítem de un paquete_combo sea otro
// paquetes_plantilla (necesariamente tipo=tour_simple, validado en
// aplicación, no acá) en vez de una proveedor_tarifa/guia_tarifa suelta.
// Autorreferencia simple (nullable), sin recursividad real de FK — la regla
// de profundidad máxima (combo → tour_simple → ítems atómicos) se valida en
// PaquetePlantillaItemController/servicio de validación, mismo criterio ya
// usado en esta tabla para "uno de los tres, no más de uno" entre
// proveedor_tarifa_id/guia_tarifa_id/paquete_plantilla_hijo_id.
//
// `orden` NO es columna nueva — ya existe desde la migración original de
// Sesión 6 (2026_07_27_200200) y se reusa con doble propósito: orden de
// aparición en el PDF (uso original, ítems sueltos) y, cuando el ítem es un
// tour-hijo dentro de un combo, qué día del combo ocupa ese tour (ver
// PaquetePlantillaController::itinerarioCombo()).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paquete_plantilla_items', function (Blueprint $table) {
            $table->foreignId('paquete_plantilla_hijo_id')->nullable()->after('guia_tarifa_id')->constrained('paquetes_plantilla');
        });
    }

    public function down(): void
    {
        Schema::table('paquete_plantilla_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paquete_plantilla_hijo_id');
        });
    }
};
