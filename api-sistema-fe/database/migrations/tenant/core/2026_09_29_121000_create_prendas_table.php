<?php

// Módulo Créditos (plan §2 `prendas`, 1.11) — bienes en garantía. Cada prenda
// respalda un solo crédito. Columnas de vehículo solo aplican a tipo=vehiculo.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prendas', function (Blueprint $table) {
            $table->comment('Prendas en garantía (módulo creditos_prendas).');
            $table->id();
            $table->foreignId('credito_id')->comment('Crédito que respalda (creditos.id).')->constrained('creditos')->restrictOnDelete();
            $table->enum('tipo', ['equipo', 'vehiculo'])->comment('Tipo de prenda (1.11).');
            $table->string('descripcion', 255)->comment('Qué es la prenda.');
            $table->string('marca', 100)->nullable()->comment('Marca.');
            $table->string('modelo', 100)->nullable()->comment('Modelo.');
            $table->string('serie', 100)->nullable()->comment('Número de serie / IMEI / VIN.');
            $table->string('color', 50)->nullable()->comment('Color.');
            $table->text('accesorios')->nullable()->comment('Accesorios entregados.');
            $table->text('estado_fisico')->nullable()->comment('Estado físico al ingreso.');
            $table->string('ubicacion_custodia', 150)->nullable()->comment('Dónde se guarda.');
            $table->decimal('valor_tasado', 12, 2)->nullable()->comment('Valor de tasación.');
            $table->enum('estado', ['en_custodia', 'apta_para_venta', 'en_venta', 'vendida', 'devuelta'])->default('en_custodia')->comment('Ciclo de la prenda (1.11).');
            $table->date('fecha_ingreso')->comment('Día de ingreso a custodia.');
            $table->date('fecha_devolucion')->nullable()->comment('Día de devolución al cliente.');
            $table->date('fecha_venta')->nullable()->comment('Día de venta.');
            $table->decimal('precio_venta', 12, 2)->nullable()->comment('Precio de venta.');
            $table->decimal('excedente_por_devolver', 12, 2)->default(0)->comment('Venta menos deuda, pendiente de devolver al cliente (1.11).');
            $table->date('fecha_devolucion_excedente')->nullable()->comment('Día en que se devolvió el excedente.');
            $table->foreignId('cash_movement_venta_id')->nullable()->comment('Ingreso de caja de la venta.')->constrained('cash_movements')->restrictOnDelete();
            $table->foreignId('cash_movement_excedente_id')->nullable()->comment('Salida de caja del excedente.')->constrained('cash_movements')->restrictOnDelete();
            $table->string('placa', 15)->nullable()->comment('Solo vehículo: placa.');
            $table->smallInteger('anio')->nullable()->comment('Solo vehículo: año de fabricación.');
            $table->boolean('tarjeta_propiedad_recibida')->default(false)->comment('Solo vehículo: se recibió la tarjeta de propiedad.');
            $table->boolean('llaves_recibidas')->default(false)->comment('Solo vehículo: se recibieron las llaves.');
            $table->unsignedBigInteger('registrado_por')->comment('users.id que registró la prenda (sin FK).');
            $table->timestamps();

            $table->index(['credito_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prendas');
    }
};
