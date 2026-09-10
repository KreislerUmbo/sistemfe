<?php
// Fase 1a (plan-modulo-menus-y-roles.md §9.6) — mismo patrón que
// AuditLogger/central_audit_logs (confirmado leyendo su implementación real
// antes de replicar), adaptado a conexión de TENANT (a diferencia de
// central_audit_logs) porque esto registra cambios de roles/permisos DE UN
// TENANT, hechos por un usuario de ESE tenant vía el guard 'api' — no tiene
// sentido en la conexión central.
//
// target_label queda denormalizado a propósito (mismo criterio que
// permission_shadow_logs, Fase 0c): el rol/usuario puede borrarse después,
// el registro de auditoría debe sobrevivir igual.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_audit_logs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
            $table->string('actor_email')->nullable();

            $table->string('target_type'); // 'role' | 'user'
            $table->unsignedBigInteger('target_id');
            $table->string('target_label')->nullable();

            $table->string('accion'); // permission_attached|permission_detached|role_attached|role_detached
            $table->json('detalle')->nullable(); // nombres de permisos/roles involucrados

            $table->timestamps();

            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_audit_logs');
    }
};
