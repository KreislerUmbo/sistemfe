<?php

// Módulo Créditos (decisión Fase 2) — auditoría de acciones sensibles de usuarios
// del tenant (anular pago/crédito, corregir, condonar, reprogramar, castigar,
// autorizar excepción, editar referencia). AuditLogger es solo del panel central
// (central_audit_logs), por eso el módulo tiene la suya. Alimenta el reporte de
// Control (1.16). Solo inserción.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credito_auditoria', function (Blueprint $table) {
            $table->comment('Auditoría de acciones sensibles del módulo de créditos (solo inserción).');
            $table->id();
            $table->string('accion', 60)->comment('Acción, ej. pago.anular, credito.corregir, mora.condonar.');
            $table->string('auditable_type', 60)->comment('Entidad afectada, ej. credito_pago.');
            $table->unsignedBigInteger('auditable_id')->comment('id de la entidad afectada.');
            $table->unsignedBigInteger('credito_id')->nullable()->comment('Crédito relacionado, para filtrar (sin FK).');
            $table->json('antes')->nullable()->comment('Estado relevante antes de la acción.');
            $table->json('despues')->nullable()->comment('Estado relevante después de la acción.');
            $table->text('motivo')->nullable()->comment('Motivo informado por el usuario.');
            $table->unsignedBigInteger('usuario_id')->comment('users.id que ejecutó la acción (sin FK).');
            $table->string('ip_address', 45)->nullable()->comment('IP de la petición.');
            $table->timestamps();

            $table->index('credito_id');
            $table->index(['accion', 'created_at']);
            $table->index(['usuario_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_auditoria');
    }
};
