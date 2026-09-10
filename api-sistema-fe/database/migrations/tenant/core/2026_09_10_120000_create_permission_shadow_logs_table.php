<?php
// Fase 0c (plan-modulo-menus-y-roles.md §9.1, paso 2 — modo "sombra"): registra,
// sin bloquear, cada request a una ruta de Bucket B (31 rutas operativas
// cotidianas) hecha por un usuario que NO tiene el permiso Spatie que esa ruta
// exigiría si se gateara de verdad. Insumo real para el backfill dirigido de la
// Parte 2 — nunca se usa para bloquear nada en esta fase.
//
// user_email/tenant_id quedan denormalizados a propósito: permiten leer la
// Parte 2 sin cruzar contra `users`/`tenants` (el usuario puede haberse borrado
// entre que se generó el log y que se lee), y tenant_id facilita exportar/unir
// logs de varios tenants si hiciera falta comparar umbo vs. agencia-demo.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_shadow_logs', function (Blueprint $table) {
            $table->id();

            $table->string('tenant_id')->nullable();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->string('user_email')->nullable();

            $table->string('metodo_http', 10);
            $table->string('ruta', 255); // patrón de ruta (api/sales/{sale}), no la URL resuelta
            $table->string('permiso_faltante', 100);

            $table->timestamps();

            $table->index(['permiso_faltante', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_shadow_logs');
    }
};
