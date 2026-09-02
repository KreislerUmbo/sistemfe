<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_01_140000_add_opcion_mayorista_a_reserva_items_table.php
//
// Sesión 12h — reasignación en vivo de OpcionMayorista en ReservaItem
// (auditoria-arquitectonica-agencia-viajes.md §9.2,
// PEGAR-EN-CLAUDE-CODE-reasignar-mayorista-vivo.md). El brief original
// asumía que reserva_items.opcion_mayorista_id ya existía ("el cambio es
// de comportamiento, no de schema") — CORRECCIÓN 01-sep-2026 confirmada
// contra el modelo real: no existe, esta migración la agrega junto con
// el resto de columnas de auditoría de la reasignación.
//
// opcion_mayorista_id: se puebla al aceptar la reserva
// (ReservaController::crearReservaItemDesdeAlternativaItem(), copiado 1:1
// de alternativa_items.opcion_mayorista_id, mismo patrón que
// proveedor_tarifa_id) y se reescribe SOLO vía
// ReservaController::reasignarMayorista() después.
// opcion_mayorista_original_id: se escribe una única vez, la primera vez
// que se reasigna — nunca se pisa en reasignaciones siguientes (mismo
// trade-off de auditoría simple que fecha_viaje_desde_original en
// Reserva: conserva el ORIGEN real, no un historial completo de cada
// paso intermedio).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reserva_items', function (Blueprint $table) {
            $table->foreignId('opcion_mayorista_id')->nullable()->after('destino_tributario')
                ->constrained('opcion_mayorista')->nullOnDelete();
            $table->foreignId('opcion_mayorista_original_id')->nullable()->after('opcion_mayorista_id')
                ->constrained('opcion_mayorista')->nullOnDelete();
            $table->text('motivo_reasignacion_mayorista')->nullable()->after('opcion_mayorista_original_id');
            $table->timestamp('fecha_reasignacion_mayorista')->nullable()->after('motivo_reasignacion_mayorista');
            $table->unsignedInteger('veces_reasignado_mayorista')->default(0)->after('fecha_reasignacion_mayorista');
        });
    }

    public function down(): void
    {
        Schema::table('reserva_items', function (Blueprint $table) {
            $table->dropColumn(['motivo_reasignacion_mayorista', 'fecha_reasignacion_mayorista', 'veces_reasignado_mayorista']);
            $table->dropConstrainedForeignId('opcion_mayorista_original_id');
            $table->dropConstrainedForeignId('opcion_mayorista_id');
        });
    }
};
