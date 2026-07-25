<?php

declare(strict_types=1);

// Fase B.2.1 (plan-panel-superadmin.md) — formaliza el enum de tenants.status con un
// CHECK real de Postgres (antes era un varchar(20) sin ninguna restricción). 'suspendido'
// es el valor nuevo que introduce esta fase (suspensión por falta de pago); 'activo'/
// 'archivado' ya existían. Verificado antes de escribir esta migración: los 4 tenants
// reales solo tenían 'activo'/'archivado' — el CHECK aplica limpio, sin dato inesperado.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddCheckStatusToTenantsTable extends Migration
{
    // 'central' — consolidada desde B.0.5, ver create_central_users_table.php.
    protected $connection = 'central';

    public function up(): void
    {
        DB::statement(
            "ALTER TABLE tenants " .
            "ADD CONSTRAINT chk_tenants_status CHECK (status IN ('activo', 'suspendido', 'archivado'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT chk_tenants_status');
    }
}
