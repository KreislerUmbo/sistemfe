<?php

declare(strict_types=1);

// Fase C.3 (plan-panel-superadmin.md) — el backup de seguridad automático que corre ANTES
// de una restauración real necesita distinguirse de 'manual'/'automatico' (C.1/C.2): nunca
// debe podarlo la retención de C.2 (que ya filtra por tipo='automatico' exclusivamente,
// así que 'pre_restore' queda afuera de esa poda sin más cambios) ni confundirse con un
// backup manual on-demand.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddPreRestoreToTenantBackupsTipo extends Migration
{
    // 'central' — consolidada desde B.0.5, ver create_central_users_table.php.
    protected $connection = 'central';

    public function up(): void
    {
        DB::statement('ALTER TABLE tenant_backups DROP CONSTRAINT tenant_backups_tipo_check');
        DB::statement(
            "ALTER TABLE tenant_backups ADD CONSTRAINT tenant_backups_tipo_check " .
            "CHECK (tipo IN ('manual', 'automatico', 'pre_restore'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenant_backups DROP CONSTRAINT tenant_backups_tipo_check');
        DB::statement(
            "ALTER TABLE tenant_backups ADD CONSTRAINT tenant_backups_tipo_check " .
            "CHECK (tipo IN ('manual', 'automatico'))"
        );
    }
}
