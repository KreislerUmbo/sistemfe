<?php

declare(strict_types=1);

// Fase B.2.5 (plan-panel-superadmin.md) — el rechazo de un voucher es un hecho de negocio
// real que debe quedar visible en el propio recurso (para la futura UI de B.2.6), no solo
// en central_audit_logs (tabla interna de auditoría, no pensada para mostrarse tal cual al
// tenant/operador en la sección de facturación).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMotivoRechazoToTenantPaymentVouchersTable extends Migration
{
    // 'central' — consolidada desde B.0.5, ver create_central_users_table.php.
    protected $connection = 'central';

    public function up(): void
    {
        Schema::table('tenant_payment_vouchers', function (Blueprint $table) {
            $table->string('motivo_rechazo', 500)->nullable()->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_payment_vouchers', function (Blueprint $table) {
            $table->dropColumn('motivo_rechazo');
        });
    }
}
