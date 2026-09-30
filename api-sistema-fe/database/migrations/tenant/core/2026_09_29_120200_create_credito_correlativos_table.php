<?php

// Módulo Créditos (decisión Fase 2) — contadores de numero_credito y
// numero_recibo. Mismo patrón que codigo_secuencias / serie_comprobantes: fila
// semilla creada aquí + lockForUpdate() + incremento atómico
// (CorrelativoCreditoService). Nunca MAX(numero) sobre la tabla de negocio.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credito_correlativos', function (Blueprint $table) {
            $table->comment('Contadores de correlativos del módulo de créditos (una fila por tipo).');
            $table->id();
            $table->enum('tipo', ['credito', 'recibo'])->unique()->comment('credito = numero_credito; recibo = numero_recibo.');
            $table->string('prefijo', 10)->comment('Prefijo del número, ej. CR o RC.');
            $table->unsignedBigInteger('ultimo_numero')->default(0)->comment('Último número emitido; se incrementa con lockForUpdate.');
            $table->timestamps();
        });

        DB::table('credito_correlativos')->insert([
            ['tipo' => 'credito', 'prefijo' => 'CR', 'ultimo_numero' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['tipo' => 'recibo', 'prefijo' => 'RC', 'ultimo_numero' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_correlativos');
    }
};
