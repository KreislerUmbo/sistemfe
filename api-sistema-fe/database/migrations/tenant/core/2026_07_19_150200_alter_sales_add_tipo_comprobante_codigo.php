<?php
// database/migrations/tenant/2026_07_19_150200_alter_sales_add_tipo_comprobante_codigo.php
//
// Escrita en el mismo momento en que SaleController::store() reserva el
// correlativo (Paso 2) — nunca inferida después desde el prefijo de 'serie'.
// El prefijo de una serie NV es libre (ej. "NV001" pero también podría ser
// cualquier otro), así que parsear el prefijo se rompe apenas exista una
// serie NV con un prefijo distinto — esta columna es la única fuente de
// verdad de qué tipo de documento es una venta.
//
// Sin FK real (mismo caso cross-boundary que serie_comprobantes.
// tipo_comprobante_codigo → tipos_comprobante en la base central).
//
// Nullable y sin backfill: no hay datos reales que migrar en este módulo
// (confirmado, sin ventas existentes que dependan de esta columna todavía).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('tipo_comprobante_codigo', 4)->nullable()->after('serie');
            $table->index('tipo_comprobante_codigo');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['tipo_comprobante_codigo']);
            $table->dropColumn('tipo_comprobante_codigo');
        });
    }
};
