<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_140000_create_sale_detail_items_table.php
//
// Sesión 9b del vertical Agencia de Viajes (plan-hoja-de-ruta-ejecucion.md) —
// plan-modulo-cotizaciones-reservas.md §6.2: tabla puente. Al generar el
// Sale desde una reserva, el sistema propone por defecto una línea de
// factura por tipo de servicio (agrupando reserva_items), pero el usuario
// puede fusionar/separar líneas manualmente antes de emitir — esta tabla
// registra qué reserva_items terminó cubriendo cada sale_detail (línea de
// factura), sin perder trazabilidad.
//
// A diferencia de todas las demás tablas de Sesión 9, ESTA sí vive en
// tenant/verticals/agencia-viajes/ (no tenant/core/) — aunque referencia
// sale_details (core), la tabla puente en sí solo tiene sentido para
// agencia_viajes (reserva_item_id no existe en ningún otro giro).
//
// IMPORTANTE (documentado explícitamente por el plan): el reporte
// operativo (Sesión 10) y los itinerarios siguen leyendo de reserva_items
// DIRECTAMENTE, nunca de sale_detail_items/sale_details — la factura es
// solo una representación distinta de los mismos datos para SUNAT, no la
// fuente de verdad operativa. No usar esta tabla como atajo para evitar
// un JOIN contra reserva_items en reportes futuros.
//
// sale_detail_id: FK real a sale_details (core, tabla 'sale_details').

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_detail_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_detail_id')->constrained('sale_details');
            $table->foreignId('reserva_item_id')->constrained('reserva_items');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_detail_items');
    }
};
