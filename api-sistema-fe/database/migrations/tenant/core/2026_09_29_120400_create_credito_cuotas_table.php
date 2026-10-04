<?php

// Módulo Créditos (plan §2 `credito_cuotas`) — cronograma. Los acumulados
// (capital_pagado, interes_pagado, mora_pagada, interes_condonado, cargo_pagado,
// estado, fecha_pago, dias_atraso_al_pagar) se DERIVAN del reparto del motor
// sobre las aplicaciones vigentes y se reescriben en cada cobro/anulación
// (1.9, 12.10: interes_condonado nunca se guarda por separado).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credito_cuotas', function (Blueprint $table) {
            $table->comment('Cuotas del cronograma de cada crédito, por versión.');
            $table->id();
            $table->foreignId('credito_id')->comment('Crédito (creditos.id).')->constrained('creditos')->restrictOnDelete();
            $table->smallInteger('version_cronograma')->default(1)->comment('Versión del cronograma; las anteriores quedan anuladas (1.8).');
            $table->smallInteger('numero_cuota')->comment('1..n.');
            $table->date('fecha_inicio_periodo')->comment('Inicio del período (desembolso o vencimiento anterior); base del proporcional y la mora.');
            $table->date('fecha_vencimiento')->comment('Vencimiento vigente (puede cambiar al reprogramar, 1.18).');
            $table->date('fecha_vencimiento_original')->comment('Vencimiento original; el divisor de la mora usa este período (1.18, 12.4).');
            $table->decimal('monto_capital', 12, 2)->comment('Parte de capital de la cuota.');
            $table->decimal('monto_interes', 12, 2)->comment('Parte de interés de la cuota.');
            $table->decimal('monto_total', 12, 2)->comment('Capital + interés (sin cargo).');
            $table->decimal('capital_pagado', 12, 2)->default(0)->comment('Acumulado aplicado a capital (derivado).');
            $table->decimal('interes_pagado', 12, 2)->default(0)->comment('Acumulado aplicado a interés (derivado).');
            $table->decimal('mora_pagada', 12, 2)->default(0)->comment('Mora cobrada de esta cuota (derivado).');
            $table->decimal('mora_condonada', 12, 2)->default(0)->comment('Suma de condonaciones vigentes (credito_condonaciones).');
            $table->decimal('mora_congelada', 12, 2)->default(0)->comment('Mora acumulada congelada al reprogramar (1.18).');
            $table->decimal('interes_condonado', 12, 2)->default(0)->comment('Interés descontado por liquidación anticipada (derivado del reparto, 1.5).');
            $table->decimal('cargo_monto', 12, 2)->default(0)->comment('Cargo por reprogramación sumado a la cuota (1.18).');
            $table->decimal('cargo_pagado', 12, 2)->default(0)->comment('Acumulado aplicado al cargo (derivado).');
            $table->enum('estado', ['pendiente', 'pagada', 'anulada'])->default('pendiente')->comment('anulada solo para versiones de cronograma reemplazadas (1.8).');
            $table->date('fecha_pago')->nullable()->comment('Día en que quedó totalmente pagada (derivado).');
            $table->smallInteger('dias_atraso_al_pagar')->nullable()->comment('Días de atraso al pagarse; historial de puntualidad (1.6).');
            $table->timestamps();

            $table->unique(['credito_id', 'version_cronograma', 'numero_cuota']);
            $table->index(['fecha_vencimiento', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_cuotas');
    }
};
