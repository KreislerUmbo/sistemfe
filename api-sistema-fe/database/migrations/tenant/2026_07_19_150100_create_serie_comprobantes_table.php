<?php
// database/migrations/tenant/2026_07_19_150100_create_serie_comprobantes_table.php
//
// Módulo de series de comprobantes — tabla operativa por sucursal (tenant,
// a diferencia del catálogo tipos_comprobante que es central). Una fila acá
// es una serie real usable para emitir, con su propio correlativo.
//
// tipo_comprobante_codigo es una referencia cross-boundary (tenant → central)
// — mismo caso ya existente de products.codigo_detraccion → detraction_codes,
// sin FK real de Postgres entre bases distintas. Se valida a nivel de
// aplicación en SerieComprobanteController::store() (existencia +
// activo_greenter=true OR codigo='NV'), igual criterio que
// ProductController::store() valida codigo_detraccion contra DetractionCode.
//
// correlativo_actual arranca en 0 — la fila semilla que resuelve el bug de
// concurrencia real: reservarCorrelativo() ahora siempre tiene una fila que
// bloquear con lockForUpdate() desde el momento en que la serie se crea,
// nunca antes de la primera venta.
//
// unique(branch_id, tipo_comprobante_codigo, moneda): una sucursal puede
// tener más de una serie activa del mismo tipo de comprobante solo si la
// moneda es distinta (práctica contable interna, no requisito de SUNAT).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serie_comprobantes', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('branch_id');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('restrict');

            $table->string('tipo_comprobante_codigo', 4); // sin FK real — ver nota arriba

            $table->string('moneda', 3); // 'PEN' / 'USD'

            $table->string('serie', 10);

            $table->unsignedBigInteger('correlativo_actual')->default(0);
            $table->unsignedBigInteger('correlativo_inicial')->default(1);

            $table->date('fecha_inicio');
            $table->boolean('activo')->default(true);

            $table->timestamps();

            $table->unique(['branch_id', 'tipo_comprobante_codigo', 'moneda']);
            $table->index('tipo_comprobante_codigo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serie_comprobantes');
    }
};
