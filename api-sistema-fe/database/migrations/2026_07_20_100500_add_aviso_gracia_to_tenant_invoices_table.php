<?php

declare(strict_types=1);

// Fase B.2.4 (plan-panel-superadmin.md) — checkpoint "aviso a mitad del período de gracia"
// es el único de los 3 checkpoints de tenants:check-overdue-payments sin una transición de
// estado natural que lo vuelva idempotente por sí sola: el recordatorio de vencimiento se
// gatilla en la transición pendiente->vencido (una sola vez, el propio estado lo impide
// repetir) y la suspensión se gatilla en la transición tenants.status activo->suspendido
// (misma lógica). El aviso de mitad de gracia, en cambio, puede quedar "vencido" varios
// días seguidos sin cambiar de estado — sin esta columna, una corrida diaria del cron lo
// reenviaría todos los días durante el resto del período de gracia.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAvisoGraciaToTenantInvoicesTable extends Migration
{
    // 'central' — consolidada desde B.0.5, ver create_central_users_table.php.
    protected $connection = 'central';

    public function up(): void
    {
        Schema::table('tenant_invoices', function (Blueprint $table) {
            $table->timestamp('aviso_gracia_enviado_at')->nullable()->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_invoices', function (Blueprint $table) {
            $table->dropColumn('aviso_gracia_enviado_at');
        });
    }
}
