<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_120000_create_reglas_cancelacion_table.php
//
// Sesión 8b del vertical Agencia de Viajes (plan-hoja-de-ruta-ejecucion.md) —
// plan-modulo-cotizaciones-reservas.md §4.2. Solo el schema y la carga
// inicial de la regla general entran en el primer lanzamiento — la LÓGICA
// que consume esta tabla (calcular porcentaje_reembolso_aplicado al
// cancelar una reserva) es Fase 2, explícito en el plan (mismo criterio ya
// documentado en reserva.motivo_cancelacion/porcentaje_reembolso_aplicado/
// monto_reembolso, Sesión 8a).
//
// proveedor_id: nullable, FK real a proveedores (Sesión 3) — null = regla
// general de la agencia (carga inicial, ver ReglaCancelacionSeeder); con
// valor = ese proveedor tiene su propia regla más estricta.
//
// dias_max_antes: nullable = sin tope (la franja "> N días antes" no tiene
// límite superior).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reglas_cancelacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->nullable()->constrained('proveedores');
            $table->unsignedSmallInteger('dias_min_antes');
            $table->unsignedSmallInteger('dias_max_antes')->nullable();
            $table->decimal('porcentaje_reembolso', 5, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reglas_cancelacion');
    }
};
