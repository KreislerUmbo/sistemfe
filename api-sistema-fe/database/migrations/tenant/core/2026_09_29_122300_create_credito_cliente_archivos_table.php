<?php

// Módulo Créditos (plan §2 `credito_cliente_archivos`, 1.23) — DNI, foto y
// otros archivos del cliente (Ley 29733: datos personales, acceso por permiso).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credito_cliente_archivos', function (Blueprint $table) {
            $table->comment('Archivos del cliente para cobranza (DNI, foto, otros).');
            $table->id();
            $table->foreignId('cliente_id')->comment('Cliente (clients.id).')->constrained('clients')->restrictOnDelete();
            $table->enum('tipo', ['dni_anverso', 'dni_reverso', 'foto_cliente', 'otro'])->comment('Tipo de archivo.');
            $table->string('ruta_archivo', 255)->comment('Ruta en el disco privado del tenant.');
            $table->unsignedBigInteger('registrado_por')->comment('users.id (sin FK).');
            $table->timestamps();

            $table->index(['cliente_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_cliente_archivos');
    }
};
