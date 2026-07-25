<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantInvoicesTable extends Migration
{
    // 'central' — consolidada desde B.0.5, ver create_central_users_table.php.
    protected $connection = 'central';

    public function up(): void
    {
        Schema::create('tenant_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_subscription_id')->constrained('tenant_subscriptions')->restrictOnDelete();

            // Denormalizado a propósito, para consultar directo sin pasar por
            // tenant_subscriptions (ej. reportes) — mismo tenant que ya resuelve
            // tenant_subscription_id, no una fuente de verdad distinta.
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();

            $table->string('periodo', 7); // 'YYYY-MM'
            $table->string('folio_interno')->unique();
            $table->decimal('monto', 8, 2);
            $table->date('fecha_vencimiento');
            $table->enum('estado', ['pendiente', 'pagado', 'vencido'])->default('pendiente');
            $table->date('fecha_pago')->nullable();
            // Nota futura (autofacturación de umbo, sin implementar): id del comprobante
            // SUNAT real que se emitiría por este invoice. Sin FK — 'sales' vive en cada
            // base de tenant, no en 'central', un FK real cross-base no es posible acá
            // (mismo motivo que tenant_id no tenía FK real antes de B.0.5).
            $table->unsignedBigInteger('sunat_document_id')->nullable();
            $table->timestamps();

            // Idempotencia de tenants:generate-monthly-invoices — nunca dos invoices para
            // el mismo período de la misma suscripción.
            $table->unique(['tenant_subscription_id', 'periodo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_invoices');
    }
}
