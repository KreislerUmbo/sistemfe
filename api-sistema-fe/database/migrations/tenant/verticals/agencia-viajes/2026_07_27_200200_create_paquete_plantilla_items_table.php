<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_27_200200_create_paquete_plantilla_items_table.php
//
// Sesión 6 del vertical Agencia de Viajes — plan-modulo-cotizaciones-reservas.md
// §3.7: "items_incluidos (...) esto es lo que genera el 'Incluye' del PDF
// automáticamente — NO es texto libre, cada ítem es un destino_servicio +
// proveedor_tarifa real". El plan nombra el concepto `items_incluidos` pero
// nunca lo baja a nombre de tabla concreto — se nombra acá
// `paquete_plantilla_items` (decisión de esta sesión, no del documento
// original) siguiendo el mismo patrón `{tabla_padre}_items` que ya usa
// `tour_itinerario_items` para el mismo `paquetes_plantilla`.
//
// proveedor_tarifa_id cubre destino_servicio IMPLÍCITAMENTE (cadena
// proveedor_tarifa → proveedor_servicio → destino_servicio, todas ya con
// FK real entre sí desde Sesiones 4-5) — no hace falta una FK redundante
// a destino_servicio acá. Cubre ítems regulares ("Traslado ida y vuelta",
// "Almuerzo típico", "Entradas").
//
// guia_tarifa_id es específico para "Guía de Turismo" — resuelto
// 25-jul-2026 en plan-modulo-tours-catalogo.md §6: SÍ necesita tarifa
// propia (guia_tarifas, Sesión 5), no un guia_id directo sin costo/margen.
//
// Regla de negocio "uno de los dos entre proveedor_tarifa_id/guia_tarifa_id,
// no ambos" NO se modela como CHECK constraint — mismo criterio que
// opciones_hotel (opcion_mayorista_id/paquete_plantilla_id, Sesión 5): se
// valida a nivel de aplicación cuando se construya el CRUD real (Sesión 7+).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paquete_plantilla_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paquete_plantilla_id')->constrained('paquetes_plantilla');
            $table->foreignId('proveedor_tarifa_id')->nullable()->constrained('proveedor_tarifas');
            $table->foreignId('guia_tarifa_id')->nullable()->constrained('guia_tarifas');
            $table->unsignedSmallInteger('orden')->nullable(); // orden de aparición en el "Incluye" del PDF
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paquete_plantilla_items');
    }
};
