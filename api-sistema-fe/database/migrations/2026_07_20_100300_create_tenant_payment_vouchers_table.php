<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantPaymentVouchersTable extends Migration
{
    // 'central' — consolidada desde B.0.5, ver create_central_users_table.php.
    protected $connection = 'central';

    public function up(): void
    {
        Schema::create('tenant_payment_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_invoice_id')->constrained('tenant_invoices')->restrictOnDelete();
            // Ruta en disco 'private' (Fase B.2) — un voucher de transferencia suele
            // traer datos bancarios, mismo criterio que el certificado SUNAT: nunca en
            // local/public.
            $table->string('path', 500);
            $table->enum('estado', ['pendiente_verificacion', 'verificado', 'rechazado'])
                ->default('pendiente_verificacion');
            // Sin columna fecha_subida separada — created_at ya cumple ese rol.
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_payment_vouchers');
    }
}
