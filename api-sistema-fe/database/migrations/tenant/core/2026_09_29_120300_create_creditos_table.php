<?php

// Módulo Créditos (plan §2 `creditos`) — la operación de préstamo. Las
// condiciones se editan en borrador y quedan congeladas al activar (5.2). Sin
// softDeletes: la anulación es estado + motivo/usuario/fecha (§0).
// numero_credito es nullable: el correlativo se asigna al activar, así un
// borrador descartado no deja huecos en la numeración.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creditos', function (Blueprint $table) {
            $table->comment('Créditos (préstamos) otorgados a clientes.');
            $table->id();
            $table->foreignId('cliente_id')->comment('Cliente titular (clients.id).')->constrained('clients')->restrictOnDelete();
            $table->string('numero_credito', 20)->nullable()->unique()->comment('Correlativo legible (CR-00000001), asignado al activar.');
            $table->decimal('monto_capital', 12, 2)->comment('Capital prestado; múltiplo de paso_redondeo (12.1).');
            $table->decimal('tasa_interes', 8, 4)->comment('Tasa pactada en % (ej. 20.0000).');
            $table->enum('unidad_tasa', ['total', 'mensual'])->comment('total = una vez sobre el capital; mensual = por mes del plazo (1.1, 12.1).');
            $table->enum('metodo_calculo', ['simple_fijo'])->default('simple_fijo')->comment('Método de interés; v1 solo simple_fijo (1.1).');
            $table->decimal('interes_total', 12, 2)->comment('Interés pactado calculado por el motor, múltiplo de paso_redondeo (12.1).');
            $table->enum('frecuencia_unidad', ['dia', 'semana', 'quincena', 'mes', 'anio'])->comment('Unidad de la frecuencia de pago (1.3).');
            $table->smallInteger('frecuencia_intervalo')->default(1)->comment('Cada cuántas unidades vence una cuota (1.3).');
            $table->json('dias_quincena')->nullable()->comment('Días fijos del mes si la unidad es quincena, ej. [15,"ultimo"] (1.3).');
            $table->json('dias_no_laborables')->comment('Días ISO 1-7 sin vencimientos, copiado de la configuración (1.4).');
            $table->boolean('saltar_feriados')->default(false)->comment('Si el cronograma salta feriados (1.4).');
            $table->enum('regla_no_laborable', ['siguiente', 'anterior', 'mantener'])->default('siguiente')->comment('Regla para vencimientos en día no laborable (1.4).');
            $table->boolean('mora_cuenta_no_laborables')->default(true)->comment('true = mora en días calendario (1.4, 12.4).');
            $table->smallInteger('numero_cuotas')->comment('Cantidad de cuotas.');
            $table->date('fecha_desembolso')->comment('Día de entrega del dinero.');
            $table->foreignId('payment_method_id')->nullable()->comment('Método de pago del desembolso (payment_methods.id).')->constrained('payment_methods')->restrictOnDelete();
            $table->foreignId('cash_movement_id')->nullable()->comment('Movimiento de caja del desembolso (cash_movements.id); null en migración (1.20).')->constrained('cash_movements')->restrictOnDelete();
            $table->date('fecha_primer_vencimiento')->comment('Vencimiento de la cuota 1.');
            $table->decimal('tasa_interes_minimo', 8, 4)->comment('% del capital cobrado como mínimo al liquidar (1.5).');
            $table->enum('modo_mora', ['diaria_sobre_saldo'])->default('diaria_sobre_saldo')->comment('Modo de cálculo de mora (1.6).');
            $table->smallInteger('dias_gracia')->default(0)->comment('Días sin mora tras el vencimiento (1.6).');
            $table->decimal('paso_redondeo', 4, 2)->default(0.10)->comment('Paso de redondeo en soles (1.2).');
            $table->enum('tope_mora_tipo', ['porcentaje_cuota', 'porcentaje_capital', 'dias_maximos', 'sin_tope'])->default('porcentaje_cuota')->comment('Tope de mora congelado al activar (1.19).');
            $table->integer('tope_mora_valor')->nullable()->comment('% o días del tope de mora (1.19).');
            $table->enum('estado', ['borrador', 'activo', 'castigado', 'finalizado', 'anulado'])->default('borrador')->comment('Estado del crédito.');
            $table->date('fecha_castigo')->nullable()->comment('Fecha del castigo vigente; historial en credito_castigos (1.19, 12.12).');
            $table->enum('origen_registro', ['normal', 'migracion', 'renovacion'])->default('normal')->comment('Cómo se originó el crédito (1.20, 1.21).');
            $table->foreignId('credito_renovado_id')->nullable()->comment('Crédito que este renovó (creditos.id) (1.21).')->constrained('creditos')->restrictOnDelete();
            $table->smallInteger('version_cronograma_actual')->default(1)->comment('Versión vigente de credito_cuotas; toda consulta filtra por ella (1.8).');
            $table->enum('motivo_cierre', ['pagado_completo', 'liquidacion_anticipada', 'venta_prenda', 'renovacion', 'recupero_castigo'])->nullable()->comment('Por qué quedó finalizado.');
            $table->unsignedBigInteger('registrado_por')->comment('users.id que registró el crédito (sin FK).');
            $table->text('motivo_anulacion')->nullable()->comment('Motivo de la anulación (1.8).');
            $table->unsignedBigInteger('anulado_por')->nullable()->comment('users.id que anuló (sin FK).');
            $table->timestamp('anulado_en')->nullable()->comment('Momento de la anulación.');
            $table->timestamps();

            $table->index(['cliente_id', 'estado']);
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creditos');
    }
};
