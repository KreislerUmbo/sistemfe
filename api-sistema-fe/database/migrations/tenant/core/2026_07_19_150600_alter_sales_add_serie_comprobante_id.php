<?php
// database/migrations/tenant/2026_07_19_150600_alter_sales_add_serie_comprobante_id.php
//
// FK directa a la fila de serie_comprobantes usada al crear la venta.
// tipo_comprobante_codigo (migración anterior) ya identifica el TIPO de
// documento, pero no la fila operativa concreta — para reservar el
// correlativo en enviarSunat() (documentos fiscales, reserva diferida al
// envío real, no a la creación) hace falta saber exactamente qué fila de
// serie_comprobantes bloquear con lockForUpdate(). Sin esta columna,
// habría que re-derivar (branch_id del usuario actual + tipo + moneda) al
// momento del envío — ambiguo si el usuario que envía no es el mismo que
// creó la venta, o si cambió de sucursal después. serie_comprobante_id se
// llena una sola vez en SaleController::store(), junto con
// tipo_comprobante_codigo, y no se vuelve a tocar después.
//
// FK real de Postgres — misma base tenant, sin problema cross-boundary.
// Nullable, sin backfill (mismo motivo que tipo_comprobante_codigo: no hay
// datos reales que migrar en este módulo).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedBigInteger('serie_comprobante_id')->nullable()->after('tipo_comprobante_codigo');
            $table->foreign('serie_comprobante_id')->references('id')->on('serie_comprobantes')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['serie_comprobante_id']);
            $table->dropColumn('serie_comprobante_id');
        });
    }
};
