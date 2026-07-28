<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_07_28_140100_create_pago_proveedor_table.php
//
// Sesión 9b del vertical Agencia de Viajes — plan-modulo-cotizaciones-reservas.md
// §6 (intro): el core de Sale/SalePayment/Advance/Installment es solo para
// lo que la agencia le COBRA al cliente — no tiene nada para lo que la
// agencia le PAGA a sus proveedores/mayoristas. `pago_proveedor` cubre eso,
// sin reimplementar nada del core.
//
// Contraparte de cronograma_pago_proveedor (Sesión 8b): ese es lo
// PROGRAMADO, este es lo YA PAGADO. Al registrar un pago acá se vincula
// (referencia informativa, no FK — el plan no la pide) a la cuota
// correspondiente del cronograma.
//
// proveedor_id/opcion_mayorista_id: ambos nullable, FK real a proveedores
// (Sesión 3) / opcion_mayorista (Sesión 7b). Regla de negocio "uno de los
// dos, no ambos" NO se modela como CHECK constraint — mismo criterio
// idéntico a cronograma_pago_proveedor (Sesión 8b), opciones_hotel
// (Sesión 5) y paquete_plantilla_items (Sesión 6).
//
// referencia_documento: texto libre — número de factura que da el
// proveedor, NO es un comprobante que la agencia emite (no pasa por
// Greenter/SUNAT).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pago_proveedor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->nullable()->constrained('proveedores');
            $table->foreignId('opcion_mayorista_id')->nullable()->constrained('opcion_mayorista');
            $table->decimal('monto', 10, 2);
            $table->string('moneda'); // 'PEN' | 'USD'
            $table->date('fecha');
            $table->string('tipo'); // 'adelanto_reserva' | 'pago_final'
            $table->string('referencia_documento')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pago_proveedor');
    }
};
