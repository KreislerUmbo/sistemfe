<?php

// Módulo Créditos (plan §2 `credito_configuracion`) — valores por defecto del
// negocio, fila única. Se copian al crédito al crearlo (editables) y quedan
// congelados al activarlo (1.4). La fila se siembra aquí con los defaults del plan.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credito_configuracion', function (Blueprint $table) {
            $table->comment('Configuración del módulo de créditos; una sola fila (fila_unica).');
            $table->id();
            $table->boolean('fila_unica')->default(true)->unique()->comment('Garantiza una sola fila (check + unique).');
            $table->decimal('tasa_interes_minimo', 8, 4)->default(10)->comment('% del capital cobrado como interés mínimo al liquidar antes (1.5).');
            $table->smallInteger('dias_gracia')->default(0)->comment('Días después del vencimiento sin mora (1.6).');
            $table->decimal('paso_redondeo', 4, 2)->default(0.10)->comment('Redondeo de cuotas, liquidación y mora en soles (1.2).');
            $table->json('dias_no_laborables')->comment('Días ISO 1-7 sin vencimientos, ej. [7] = domingo (1.4).');
            $table->boolean('saltar_feriados')->default(false)->comment('Si los vencimientos saltan los feriados de la tabla feriados (1.4).');
            $table->enum('regla_no_laborable', ['siguiente', 'anterior', 'mantener'])->default('siguiente')->comment('Qué hacer si un vencimiento cae en día no laborable (1.4).');
            $table->boolean('mora_cuenta_no_laborables')->default(true)->comment('true = la mora cuenta días calendario; false = solo laborables (1.4, 12.4).');
            $table->smallInteger('dias_para_venta')->default(30)->comment('Días de atraso para marcar prendas apta_para_venta (1.11).');
            $table->decimal('porcentaje_prestamo_max', 5, 2)->nullable()->comment('% máximo del valor tasado que se puede prestar; null = sin límite (1.11).');
            $table->enum('validacion_tasacion', ['ninguna', 'advertir', 'bloquear'])->default('ninguna')->comment('Qué hacer si el préstamo supera porcentaje_prestamo_max (1.11).');
            $table->enum('modo_asignacion_cartera', ['cliente', 'credito', 'zona'])->default('cliente')->comment('Cómo se asigna la cartera de cobradores; v1 solo cliente (1.14).');
            $table->smallInteger('max_creditos_activos')->default(2)->comment('Créditos activos simultáneos por cliente (1.17).');
            $table->decimal('deuda_maxima_cliente', 12, 2)->nullable()->comment('Saldo de capital máximo por cliente incluido el nuevo; null = sin tope (1.17).');
            $table->smallInteger('dias_atraso_bloqueo')->default(7)->comment('Días de atraso desde los que el cliente es moroso y se bloquea (1.17, 1.19).');
            $table->smallInteger('max_garantias_por_garante')->default(3)->comment('Créditos activos que un garante puede respaldar antes de advertir (1.17).');
            $table->decimal('tasa_maxima', 8, 4)->nullable()->comment('Tasa máxima permitida en %; fijar con el límite legal BCRP (§11). null = sin límite.');
            $table->smallInteger('max_numero_cuotas')->default(365)->comment('Número máximo de cuotas de un crédito (5.2).');
            $table->smallInteger('umbral_alerta_anulaciones')->default(5)->comment('Anulaciones de pagos por usuario que disparan alerta en el reporte de Control (1.16).');
            $table->enum('cargo_reprogramacion_tipo', ['ninguno', 'fijo', 'interes_por_dias'])->default('ninguno')->comment('Cálculo del cargo por reprogramar fechas (1.18).');
            $table->decimal('cargo_reprogramacion_monto', 12, 2)->default(0)->comment('Monto del cargo cuando el tipo es fijo (1.18).');
            $table->smallInteger('dias_aviso_garante')->default(15)->comment('Días de atraso para listar el crédito en "Cobrar al garante" (1.19).');
            $table->smallInteger('dias_para_castigo')->default(90)->comment('Días de atraso para castigar el crédito automáticamente (1.19).');
            $table->enum('tope_mora_tipo', ['porcentaje_cuota', 'porcentaje_capital', 'dias_maximos', 'sin_tope'])->default('porcentaje_cuota')->comment('Tipo de tope de la mora (1.19).');
            $table->integer('tope_mora_valor')->nullable()->default(100)->comment('% (porcentaje_*) o número de días (dias_maximos) del tope de mora (1.19).');
            $table->smallInteger('dias_max_pago_retroactivo')->default(3)->comment('Días hacia atrás permitidos para registrar un pago con fecha anterior (1.22).');
            $table->unsignedBigInteger('actualizado_por')->nullable()->comment('users.id que modificó la configuración por última vez (sin FK).');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE credito_configuracion ADD CONSTRAINT credito_configuracion_fila_unica CHECK (fila_unica)');
        DB::table('credito_configuracion')->insert([
            'fila_unica' => true,
            'dias_no_laborables' => json_encode([7]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_configuracion');
    }
};
