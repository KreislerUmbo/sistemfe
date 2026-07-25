<?php

declare(strict_types=1);

// Fase C.4 (plan-panel-superadmin.md) — sin esto, un backup corrupto al crearse (disco
// lleno durante pg_dump, corte de luz a mitad de camino) no se descubre hasta que hace
// falta restaurarlo, potencialmente meses después. `integridad_verificada` queda NULL
// (no `false`) para backups nunca verificados — distingue "nunca chequeado" de
// "chequeado y corrupto", relevante para el futuro dashboard de Fase D.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIntegridadToTenantBackupsTable extends Migration
{
    // 'central' — consolidada desde B.0.5, ver create_central_users_table.php.
    protected $connection = 'central';

    public function up(): void
    {
        Schema::table('tenant_backups', function (Blueprint $table) {
            $table->boolean('integridad_verificada')->nullable()->after('estado');
            $table->timestamp('verificado_at')->nullable()->after('integridad_verificada');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_backups', function (Blueprint $table) {
            $table->dropColumn(['integridad_verificada', 'verificado_at']);
        });
    }
}
