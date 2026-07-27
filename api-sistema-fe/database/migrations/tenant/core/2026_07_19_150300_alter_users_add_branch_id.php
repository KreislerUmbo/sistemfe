<?php
// database/migrations/tenant/2026_07_19_150300_alter_users_add_branch_id.php
//
// Sucursal fija del usuario — determina qué series de comprobantes ve en
// register.vue/edit.vue (Paso 3.5), salvo que tenga el permiso
// can_switch_branch (ver 2026_07_19_150400_...), en cuyo caso puede elegir
// entre todas las branches activas en el form de venta.
//
// Nullable a propósito: usuarios ya existentes (o nuevos sin asignar todavía)
// no deben quedar bloqueados por una migración — la ausencia de branch_id se
// resuelve explícitamente como 422 en SaleController::store() (Paso 2), no
// con un fallback silencioso a una sucursal por defecto.
//
// FK real de Postgres: a diferencia de tipo_comprobante_codigo, branch_id
// referencia una tabla tenant (branches), misma base física — sin problema
// de cross-boundary aquí.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->after('role_id');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
        });
    }
};
