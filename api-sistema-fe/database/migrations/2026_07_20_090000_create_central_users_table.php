<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCentralUsersTable extends Migration
{
    // 'central' — consolidada (plan-panel-superadmin.md, "B.0.5") hacia
    // db_tenant_central junto con tenants/domains + los catálogos SUNAT/
    // AdminPortal. Corrió originalmente contra una clave 'db_tenant_central'
    // separada (Fase 0); ya no existe esa separación.
    protected $connection = 'central';

    public function up(): void
    {
        Schema::create('central_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
            // Soft delete en vez de borrado real: un admin central puede
            // desactivarse sin perder el rastro de qué acciones hizo (ver
            // central_audit_logs.central_user_id).
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_users');
    }
}
