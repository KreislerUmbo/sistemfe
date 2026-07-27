<?php
// Migración correctiva — NO EJECUTAR sin revisión.
// 'sales' existe en Postgres pero nunca tuvo Schema::create en disco —
// solo alters puntuales que asumían que la tabla base ya existía
// (alter_sales_add_sunat_fields, alter_sales_add_hash_cpe_field,
// alter_sales_add_type_field, alter_sales_add_sunat_rejection_fields).
// Sin esta migración, esos alters fallan en una base nueva.
//
// Se incluyen aquí SOLO las columnas base que ningún alter existente
// cubre todavía. Las columnas que los alters YA agregan (destino,
// cod_tipo_doc_cliente, mto_oper_gravadas/exoneradas/inafectas/
// exportacion, codigo_detraccion, porcentaje_detraccion,
// porcentaje_percepcion, hash_cpe, type, sunat_error_*) NO se repiten
// aquí para no duplicarlas — sus discrepancias de nullable/default se
// corrigen en 2026_07_13_090200_fix_sales_relax_columns.php.
//
// OJO — ver reporte, punto de decisión: se dejan FUERA a propósito
// monto_retencion / monto_detraccion / monto_percepcion. La migración
// existente (2026_07_03_125245) ya agrega columnas para esto, pero con
// otro nombre: total_retencion / total_detraccion / total_percepcion.
// Postgres real usa monto_*. No genero el rename hasta confirmar contigo
// si es en efecto la misma columna mal nombrada o si son conceptos
// distintos.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('serie', 50)->nullable();
            $table->bigInteger('correlativo')->nullable();
            $table->string('n_operacion', 125)->nullable();
            $table->unsignedBigInteger('user_id'); // sin FK a nivel de BD en el real
            $table->unsignedBigInteger('client_id');
            $table->foreign('client_id')->references('id')->on('clients');
            $table->smallInteger('type_client')->default(1);
            $table->string('currency', 5)->default('S/.');
            $table->double('subtotal')->default(0);
            $table->double('total')->default(0);
            $table->smallInteger('is_exportacion')->default(0);
            $table->double('discount')->default(0)->nullable();
            $table->double('discount_global')->default(0);
            $table->string('n_comprobante_anticipo', 150)->nullable();
            $table->double('amount_anticipo')->nullable();
            $table->double('igv');
            $table->double('igv_discount_general')->default(0)->nullable();
            $table->smallInteger('type_payment')->default(1);
            $table->smallInteger('state_sale')->default(1);
            $table->smallInteger('state_payment')->default(1);
            $table->double('debt')->default(0);
            $table->double('paid_out')->default(0);
            $table->smallInteger('retencion_igv')->default(0);
            $table->text('description')->nullable();
            $table->string('cdr', 250)->nullable();
            $table->string('xml', 250)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->decimal('mto_oper_gratuitas', 12, 2)->nullable();
            $table->decimal('isc_total', 12, 2)->nullable();
            $table->decimal('icbper_total', 12, 2)->nullable();
            $table->decimal('ivap_total', 12, 2)->nullable();
            $table->decimal('total_impuestos', 12, 2)->nullable();
            $table->decimal('valor_venta', 12, 2)->nullable();
            $table->decimal('mto_imp_venta', 12, 2)->nullable();
            $table->decimal('redondeo', 6, 0)->nullable();
            $table->string('n_transaction')->nullable();
            $table->date('date')->nullable();
        });
        // real: 'n_transaction' es varchar SIN longitud (no varchar(255)).
        DB::statement('ALTER TABLE sales ALTER COLUMN n_transaction TYPE VARCHAR');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
