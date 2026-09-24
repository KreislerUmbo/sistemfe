<?php
// database/migrations/tenant/core/2026_09_24_140000_add_override_moneda_facturacion_to_sales_table.php
//
// Gap real (2026-09-24): ReservaFacturacionController::store() fijaba la
// moneda de la Sale SIEMPRE desde reserva.alternativa.moneda_cotizacion,
// sin ninguna opción de facturar en una moneda distinta (caso real: cotización
// en USD, cliente pide el comprobante en soles). Mismo criterio de trazabilidad
// que motivo_override_tributario/fecha_override_tributario (reserva_items,
// 2026-09-24): cualquier override de un valor "de catálogo" queda auditado con
// motivo explícito, nunca en silencio.
//
// moneda_original_cotizacion: null si esta Sale se facturó en la misma moneda
// de la cotización de origen (el caso normal, sin override) — poblado solo
// cuando currency terminó siendo distinto, para poder reconstruir "de qué
// moneda vino" en cualquier reporte futuro.
// tipo_cambio_override_moneda: el tipo de cambio efectivamente aplicado para
// convertir los montos (sugerido desde TipoCambioSunatResolver, editable por
// el vendedor) — nunca se recalcula después de creada la Sale.
// motivo_override_moneda: obligatorio en el request cuando se pide facturar
// en una moneda distinta a la de la cotización (ver ReservaFacturacionController).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('moneda_original_cotizacion', 5)->nullable()->after('tipo_cambio_sunat_aplicado');
            $table->decimal('tipo_cambio_override_moneda', 10, 4)->nullable()->after('moneda_original_cotizacion');
            $table->text('motivo_override_moneda')->nullable()->after('tipo_cambio_override_moneda');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['moneda_original_cotizacion', 'tipo_cambio_override_moneda', 'motivo_override_moneda']);
        });
    }
};
