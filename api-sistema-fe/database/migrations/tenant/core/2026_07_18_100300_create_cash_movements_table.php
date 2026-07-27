<?php

// Módulo Caja — Fase 1 (plan-modulo-caja.md §4, §5 regla #1). Toda
// entrada/salida de dinero dentro de una sesión de caja. Nunca se edita ni
// se borra a nivel de dato (regla de integridad #1 del plan) — eso se hace
// cumplir en Fase 4 (no hay lógica de negocio en esta fase), pero por eso
// esta migración NO lleva softDeletes(): un ->delete() sobre un movimiento
// de dinero debe fallar por FK, nunca desaparecer en silencio.
//
// reference_type/reference_id: polimórfico "manual", no $table->morphs() de
// Eloquent — no hay precedente de morphTo/morphMany en el proyecto (grep
// confirmado antes de escribir esto), y el plan pide explícitamente NO usarlo
// porque apunta a modelos con convenciones propias (sales ya existe;
// advances/installments/credit_notes son de módulos futuros). Se usan
// columnas planas sin FK real.
//
// counterparty_id: mismo criterio, sin FK real (apunta a clients o suppliers
// según counterparty_type) — se valida en la capa de aplicación (Fase 4),
// no aquí.
//
// 'type'/'direction'/'status' son strings planos (no enum de Blueprint),
// mismo criterio que cash_concepts.direction en Fase 0 — 'type' en
// particular ya se anticipa que va a crecer (transfer_in/out,
// mobile_settlement, employee_advance — plan §12), así que un CHECK/enum de
// Postgres solo agregaría fricción a futuras migraciones sin aportar una
// garantía real que la aplicación no dé ya.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('cash_session_id');
            $table->foreign('cash_session_id')->references('id')->on('cash_sessions')->onDelete('restrict');

            $table->string('type');

            $table->unsignedBigInteger('payment_method_id');
            $table->foreign('payment_method_id')->references('id')->on('payment_methods')->onDelete('restrict');

            $table->string('direction');
            $table->decimal('amount', 10, 2);

            // Polimórfico manual — ver comentario arriba.
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->unsignedBigInteger('concept_id')->nullable();
            $table->foreign('concept_id')->references('id')->on('cash_concepts')->onDelete('restrict');

            $table->text('description')->nullable();

            $table->string('counterparty_type')->nullable();
            // Sin FK real — ver comentario arriba.
            $table->unsignedBigInteger('counterparty_id')->nullable();
            $table->string('counterparty_name')->nullable();
            $table->string('counterparty_document')->nullable();

            $table->string('attachment_path')->nullable();

            $table->unsignedBigInteger('corrected_movement_id')->nullable();
            $table->foreign('corrected_movement_id')->references('id')->on('cash_movements')->onDelete('restrict');

            $table->unsignedBigInteger('corrected_by')->nullable();
            $table->foreign('corrected_by')->references('id')->on('users')->onDelete('restrict');
            $table->timestamp('corrected_at')->nullable();

            $table->string('status')->default('confirmed');

            $table->unsignedBigInteger('created_by');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');

            $table->timestamps();

            $table->index('cash_session_id');
            $table->index(['reference_type', 'reference_id']);
            $table->index(['counterparty_type', 'counterparty_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
