<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_09_24_090000_add_override_tributario_to_reserva_items_table.php
//
// Caso 3 confirmado con el usuario (2026-09-24): un servicio realizado en
// la Amazonía está exonerado de IGV según Ley 27037, independientemente del
// domicilio fiscal de la agencia — pero ReservaFacturacionController::
// detectarMezclaTributaria() seguía bloqueando el 100% de la facturación con
// destino≠nacional sin ninguna excepción (ver
// project_agencia_viajes_impuestos_captura_propagacion en memoria, "Pasos
// 3-4 pendientes de 2 respuestas del contador").
//
// En vez de confiar a ciegas en destino_tributario/tip_afe_igv tal como
// llegó copiado de la proveedor_tarifa (dato que ya se encontró sospechoso
// al menos una vez para un producto real), se exige una confirmación
// explícita por ítem antes de poder facturarlo como Amazonía/exonerado —
// mismo patrón de auditoría que motivo_reasignacion_mayorista/hotel.
// 'extranjero'/exportación queda fuera de este mecanismo a propósito, sigue
// bloqueado sin excepción (requiere validaciones legales que este flujo no
// hace: cliente no domiciliado, medios bancarizados).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reserva_items', function (Blueprint $table) {
            $table->text('motivo_override_tributario')->nullable()->after('destino_tributario');
            $table->timestamp('fecha_override_tributario')->nullable()->after('motivo_override_tributario');
        });
    }

    public function down(): void
    {
        Schema::table('reserva_items', function (Blueprint $table) {
            $table->dropColumn(['motivo_override_tributario', 'fecha_override_tributario']);
        });
    }
};
