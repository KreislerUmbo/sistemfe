<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantBackupsTable extends Migration
{
    // 'central' — consolidada desde B.0.5, ver create_central_users_table.php.
    protected $connection = 'central';

    public function up(): void
    {
        Schema::create('tenant_backups', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();

            // Ruta en disco 'private', sin partición por tenant (recurso de operación de
            // la plataforma, no dato de negocio del tenant) — mismo criterio que
            // tenant_payment_vouchers (Fase B.2.5). Nullable: queda null mientras
            // estado='en_proceso' o si termina 'fallido' antes de escribir nada.
            $table->string('path', 500)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->enum('tipo', ['manual', 'automatico'])->default('manual');
            $table->enum('estado', ['en_proceso', 'completado', 'fallido'])->default('en_proceso');
            $table->text('error_message')->nullable();

            // Sin columna 'fecha' separada — created_at ya cumple ese rol (mismo criterio
            // que tenant_payment_vouchers).
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_backups');
    }
}
