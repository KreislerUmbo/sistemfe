<?php

// Módulo Créditos — Fase 3 (03-api.md, decisión 1). Idempotencia de TODAS las
// escrituras del módulo (activar, cobrar, liquidar, corregir, renovar, migrar,
// condonar…). La fila se inserta al inicio de la transacción: un reintento
// concurrente con la misma clave queda esperando el índice único y, al confirmar
// el primero, lee la respuesta guardada. Misma clave con otro contenido u otro
// usuario → 409. Si la operación falla, la transacción revierte también esta fila
// y la clave puede reintentarse.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credito_operaciones', function (Blueprint $table) {
            $table->comment('Registro de idempotencia de las escrituras del módulo de créditos.');
            $table->id();
            $table->string('clave', 64)->unique()->comment('clave_idempotencia generada por el cliente para el intento.');
            $table->string('operacion', 40)->comment('Operación, ej. credito.activar, pago.cobrar.');
            $table->unsignedBigInteger('credito_id')->nullable()->comment('Crédito afectado (sin FK: puede ser uno recién creado).');
            $table->unsignedBigInteger('usuario_id')->comment('users.id que envió la solicitud (sin FK).');
            $table->string('hash_solicitud', 64)->comment('SHA-256 del contenido normalizado; distinto con la misma clave = 409.');
            $table->json('respuesta')->nullable()->comment('Respuesta devuelta, repetida ante reintentos.');
            $table->timestamps();

            $table->index('credito_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_operaciones');
    }
};
