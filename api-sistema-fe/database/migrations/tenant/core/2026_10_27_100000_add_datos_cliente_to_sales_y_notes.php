<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// T-064 (obs. 18, H60): copia de los datos del cliente en el comprobante.
// Antes los PDF (y el XML) leían documento, razón social y dirección del
// cliente al imprimir: editar el cliente cambiaba la reimpresión de todos sus
// comprobantes ya aceptados por SUNAT. Mismo criterio que cod_tipo_doc_cliente,
// que ya se copiaba al crear.
//
// Comprobantes existentes: se llenan con los datos ACTUALES del cliente. Es lo
// mejor que se tiene (lo que se envió de verdad está en el XML guardado, no en
// columnas); coincide con lo que el PDF mostraba hasta hoy.
return new class extends Migration
{
    private const TABLAS = ['sales', 'notes'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->string('tipo_doc_cliente', 50)->nullable()
                    ->comment('Copia de clients.type_document al emitir (rótulo del PDF: DNI, RUC…)');
                $table->string('num_doc_cliente', 50)->nullable()
                    ->comment('Copia de clients.n_document al emitir');
                $table->string('razon_social_cliente', 255)->nullable()
                    ->comment('Copia de clients.full_name al emitir');
                $table->string('direccion_cliente', 255)->nullable()
                    ->comment('Copia de clients.address al emitir (T-065: o la dirección elegida)');
            });

            DB::statement("
                UPDATE {$tabla} AS t
                SET tipo_doc_cliente = c.type_document,
                    num_doc_cliente = c.n_document,
                    razon_social_cliente = c.full_name,
                    direccion_cliente = c.address
                FROM clients AS c
                WHERE c.id = t.client_id
                  AND t.razon_social_cliente IS NULL
            ");
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn(['tipo_doc_cliente', 'num_doc_cliente', 'razon_social_cliente', 'direccion_cliente']);
            });
        }
    }
};
