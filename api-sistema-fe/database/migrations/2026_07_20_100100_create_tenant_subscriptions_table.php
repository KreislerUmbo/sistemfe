<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateTenantSubscriptionsTable extends Migration
{
    // 'central' — consolidada desde B.0.5, ver create_central_users_table.php. FK real
    // hacia tenants.id posible recién desde B.0.5 (misma base física ahora).
    protected $connection = 'central';

    public function up(): void
    {
        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            // tenants.id es string (subdominio), no autoincremental — ver Tenant::create()
            // en TenantProvisioningService.
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();

            $table->foreignId('tenant_plan_id')->constrained('tenant_plans')->restrictOnDelete();

            // null = usa el precio del plan tal cual (tenant_plans.precio_mensual).
            $table->decimal('monto_mensual_override', 8, 2)->nullable();
            // null = usa platform_settings.dia_corte_default.
            $table->unsignedTinyInteger('dia_corte')->nullable();
            // null = usa platform_settings.dias_gracia_suspension_default.
            $table->unsignedInteger('dias_gracia_suspension')->nullable();
            $table->boolean('facturacion_automatica')->default(false);
            // La suspensión por falta de pago vive ENTERAMENTE en tenants.status
            // (activo/suspendido/archivado) — 'estado' acá es solo el ciclo de vida del
            // contrato de facturación en sí, no se duplica el concepto de suspensión.
            $table->enum('estado', ['activa', 'cancelada'])->default('activa');
            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE tenant_subscriptions ' .
            'ADD CONSTRAINT chk_dia_corte_rango CHECK (dia_corte IS NULL OR dia_corte BETWEEN 1 AND 31)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_subscriptions');
    }
}
