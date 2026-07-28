<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_120200_create_cronograma_pago_proveedor_table.php
//
// Sesión 8b del vertical Agencia de Viajes — plan-modulo-cotizaciones-reservas.md
// §4.6: lo que la agencia DEBE pagar y cuándo (cronograma), a diferencia de
// `pago_proveedor` (sección 6, no construida todavía) que solo registra
// pagos YA realizados — cuando se registre un pago real ahí, se vinculará
// a la cuota correspondiente de este cronograma (Sesión 9+).
//
// proveedor_id/opcion_mayorista_id: ambos nullable, FK real a proveedores
// (Sesión 3) / opcion_mayorista (Sesión 7b). Regla de negocio "uno de los
// dos, no ambos" NO se modela como CHECK constraint — se valida a nivel de
// aplicación cuando se construya el CRUD real (Sesión 11), mismo criterio
// ya usado en opciones_hotel (Sesión 5) y paquete_plantilla_items (Sesión 6).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cronograma_pago_proveedor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->nullable()->constrained('proveedores');
            $table->foreignId('opcion_mayorista_id')->nullable()->constrained('opcion_mayorista');
            $table->unsignedSmallInteger('numero_cuota');
            $table->decimal('monto_programado', 10, 2);
            $table->date('fecha_vencimiento');
            $table->string('estado')->default('pendiente'); // 'pendiente' | 'pagado' | 'vencido'
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cronograma_pago_proveedor');
    }
};
