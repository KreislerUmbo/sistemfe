<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_110000_create_reserva_table.php
//
// Sesión 8a del vertical Agencia de Viajes (plan-hoja-de-ruta-ejecucion.md) —
// plan-modulo-cotizaciones-reservas.md §4 (intro): entidad separada de la
// cotización, creada al aceptar una alternativa (copia sus ítems/precios ya
// cerrados vía reserva_items→alternativa_items, no duplicados acá).
//
// alternativa_id: FK real a alternativas (Sesión 7a) — la alternativa
// aceptada que originó esta reserva.
//
// mayorista_elegida_id: nullable, FK real a opcion_mayorista (Sesión 7b) —
// solo aplica al caso de paquete internacional (§2.4); local/nacional queda
// null. estado_reserva_mayorista solo tiene sentido cuando esta columna no
// es null, no se modela como CHECK constraint (mismo criterio ya usado en
// opciones_hotel/paquete_plantilla_id — regla de negocio, no invariante de
// schema).
//
// fecha_cancelacion/motivo_cancelacion/porcentaje_reembolso_aplicado/
// monto_reembolso: schema listo desde ahora (§4.2), pero la LÓGICA de
// cálculo automático (reglas_cancelacion, tabla de %, jobs de mora) es
// Fase 2 — explícito en el plan ("todo lo de 4.2 queda documentado y listo,
// pero su implementación real se hace en Fase 2"). Estas 4 columnas quedan
// sin usar hasta esa fase; lo único que SÍ entra en el primer lanzamiento
// es `estado: activa | cancelada` (para que el reporte operativo, §8,
// pueda filtrar reservas canceladas) y la liberación de cupo en
// salidas_mayorista al cancelar (lógica de aplicación, no columna nueva).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserva', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alternativa_id')->constrained('alternativas');
            $table->foreignId('mayorista_elegida_id')->nullable()->constrained('opcion_mayorista');
            $table->string('estado_reserva_mayorista')->nullable(); // 'pendiente' | 'confirmada' — solo aplica si mayorista_elegida_id no es null
            $table->string('estado')->default('activa'); // 'activa' | 'cancelada'
            $table->timestamp('fecha_cancelacion')->nullable(); // Fase 2 (§4.2), sin usar todavía
            $table->string('motivo_cancelacion')->nullable(); // 'voluntaria' | 'fuerza_mayor' | 'clima' | 'falta_pago_cuotas' — Fase 2
            $table->decimal('porcentaje_reembolso_aplicado', 5, 2)->nullable(); // Fase 2
            $table->decimal('monto_reembolso', 10, 2)->nullable(); // Fase 2
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserva');
    }
};
