<?php
// database/migrations/tenant/core/2026_08_24_120000_add_correction_fields_to_advances_table.php
//
// Tier 2 del módulo Adelantos (hallazgo de auditoría, 2026-08-21): un
// comprobante de adelanto ya aceptado por SUNAT es inmutable — la única
// corrección posible es anular (NC motivo 01) + reemitir. AdvanceController::
// corregir() reasigna advances.sale_id al comprobante nuevo, MISMO Advance.id
// (preserva applications/historial) — estas columnas dejan auditoría simple
// de la corrección más reciente, mismo trade-off ya aceptado en el proyecto
// para Reserva.fecha_cancelacion/motivo_cancelacion y
// fecha_reprogramacion/motivo_reprogramacion (no es un historial completo,
// solo la última corrección queda visible).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advances', function (Blueprint $table) {
            $table->unsignedBigInteger('corrected_from_sale_id')->nullable()->after('sale_id');
            $table->text('correction_reason')->nullable();
            $table->timestamp('corrected_at')->nullable();
            $table->unsignedBigInteger('corrected_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('advances', function (Blueprint $table) {
            $table->dropColumn(['corrected_from_sale_id', 'correction_reason', 'corrected_at', 'corrected_by']);
        });
    }
};
