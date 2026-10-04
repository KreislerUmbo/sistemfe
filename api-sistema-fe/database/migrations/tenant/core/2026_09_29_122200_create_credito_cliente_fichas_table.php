<?php

// Módulo Créditos (plan §2 `credito_cliente_fichas`, 1.23) — datos para ubicar y
// cobrar al cliente, sin tocar clients.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credito_cliente_fichas', function (Blueprint $table) {
            $table->comment('Ficha de cobro del cliente.');
            $table->id();
            $table->foreignId('cliente_id')->unique()->comment('Cliente (clients.id).')->constrained('clients')->restrictOnDelete();
            $table->string('direccion_cobro', 255)->nullable()->comment('Dirección donde se cobra.');
            $table->enum('tipo_direccion', ['casa', 'negocio'])->nullable()->comment('Tipo de dirección.');
            $table->string('referencia', 255)->nullable()->comment('Referencia para llegar.');
            $table->decimal('latitud', 10, 7)->nullable()->comment('Latitud para abrir en mapas.');
            $table->decimal('longitud', 10, 7)->nullable()->comment('Longitud para abrir en mapas.');
            $table->string('telefono_alterno', 20)->nullable()->comment('Teléfono alterno.');
            $table->string('ocupacion', 150)->nullable()->comment('Ocupación o negocio.');
            $table->text('notas')->nullable()->comment('Notas de cobranza.');
            $table->unsignedBigInteger('actualizado_por')->nullable()->comment('users.id (sin FK).');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_cliente_fichas');
    }
};
