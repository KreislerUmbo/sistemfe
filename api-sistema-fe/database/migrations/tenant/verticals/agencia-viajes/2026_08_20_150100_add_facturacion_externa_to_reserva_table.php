<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_08_20_150100_add_facturacion_externa_to_reserva_table.php
//
// Facturación externa por tenant + por reserva (PEGAR-EN-CLAUDE-CODE-
// facturacion-externa-tenant.md, 2026-08-20). Override por reserva,
// independiente de tenants.facturacion_habilitada (central) — editable por
// el vendedor SOLO mientras la reserva no tenga ninguna fila en
// reserva_ventas (ver ReservaController::actualizarFacturacionExterna()),
// reversible libremente hasta ese momento. referencia_externa/
// fecha_facturacion_externa son anotación pura, sin validación contra
// ningún sistema externo — no necesitan historial completo (mismo trade-off
// ya aceptado por fecha_cancelacion/motivo_cancelacion,
// fecha_reprogramacion/motivo_reprogramacion en esta misma tabla).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reserva', function (Blueprint $table) {
            $table->boolean('facturacion_externa')->default(false)->after('motivo_reprogramacion');
            $table->string('referencia_externa')->nullable()->after('facturacion_externa');
            $table->date('fecha_facturacion_externa')->nullable()->after('referencia_externa');
        });
    }

    public function down(): void
    {
        Schema::table('reserva', function (Blueprint $table) {
            $table->dropColumn(['facturacion_externa', 'referencia_externa', 'fecha_facturacion_externa']);
        });
    }
};
