<?php

// T-135 — comprobante de compra (plan-tours-venta-simple-costo-real.md §3.7), solo montos: los
// pagos (T-136), las líneas de producto (T-154) y la asignación (T-152) llegan después.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compras', function (Blueprint $table) {
            $table->id();

            $table->foreignId('proveedor_id')->constrained('proveedores');
            // D3: copia del documento al registrar, y la clave con la que se detecta el duplicado.
            $table->string('proveedor_documento', 30)->nullable();
            $table->string('clave_proveedor', 40);

            // Tabla 10 de SUNAT (catálogo central tipos_comprobante, sin FK cross-DB).
            $table->string('tipo_comprobante_codigo', 2);
            $table->string('serie', 20);
            $table->string('numero', 20);
            $table->date('fecha_emision');
            $table->char('periodo', 7); // AAAA-MM de anotación

            $table->string('moneda', 3);
            $table->decimal('tipo_cambio', 10, 4)->nullable();

            $table->decimal('base_gravada', 14, 2)->default(0);
            $table->decimal('igv', 14, 2)->default(0);
            $table->decimal('exonerado', 14, 2)->default(0);
            $table->decimal('inafecto', 14, 2)->default(0);
            $table->decimal('otros', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);

            $table->string('condicion', 10); // contado | credito
            $table->date('fecha_vencimiento')->nullable();

            $table->foreignId('compra_referencia_id')->nullable()->constrained('compras'); // NC/ND

            // Rutas en el disco 'private' del tenant.
            $table->string('adjunto_pdf')->nullable();
            $table->string('adjunto_xml')->nullable();

            $table->string('estado_sunat', 20)->default('sin_validar'); // lo llena T-139
            $table->text('observaciones')->nullable();

            $table->timestamp('anulada_at')->nullable(); // UTC
            $table->unsignedBigInteger('anulada_por')->nullable();
            $table->string('motivo_anulacion', 255)->nullable();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->index('periodo');
            $table->index('proveedor_id');
        });

        // D3: una anulada no impide volver a registrar el comprobante bien.
        DB::statement(
            'create unique index compras_comprobante_unico on compras '
            .'(clave_proveedor, tipo_comprobante_codigo, serie, numero) where anulada_at is null'
        );

        // D6/D7. Un periodo sin fila está abierto. `historial` guarda cada cierre y reapertura:
        // las columnas sueltas solo reflejan el último ciclo.
        Schema::create('compra_periodos', function (Blueprint $table) {
            $table->id();
            $table->char('periodo', 7)->unique();
            $table->string('estado', 10)->default('abierto'); // abierto | cerrado
            $table->timestamp('cerrado_at')->nullable();
            $table->unsignedBigInteger('cerrado_por')->nullable();
            $table->timestamp('reabierto_at')->nullable();
            $table->unsignedBigInteger('reabierto_por')->nullable();
            $table->string('motivo_reapertura', 255)->nullable();
            $table->json('historial')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_periodos');
        Schema::dropIfExists('compras');
    }
};
