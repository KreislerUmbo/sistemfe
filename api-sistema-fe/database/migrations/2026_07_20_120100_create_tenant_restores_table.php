<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase C.3 (plan-panel-superadmin.md) — restauración con fricción intencional: recurso
// propio (no se mezcla con tenant_backups, que representa ARTEFACTOS de backup, no
// OPERACIONES de restauración — mismo criterio "un recurso, una tabla" ya usado en el
// proyecto, ej. tenant_payment_vouchers separado de tenant_invoices).
class CreateTenantRestoresTable extends Migration
{
    // 'central' — consolidada desde B.0.5, ver create_central_users_table.php.
    protected $connection = 'central';

    public function up(): void
    {
        Schema::create('tenant_restores', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();

            // Backup del que se restaura.
            $table->foreignId('backup_id')->constrained('tenant_backups')->restrictOnDelete();

            // Backup de seguridad generado automáticamente ANTES de restaurar (regla no
            // negociable del diseño) — nullable: si el paso de preview nunca llega a
            // confirmarse, o si el backup de seguridad falla y el flujo aborta antes de
            // completarlo, no hay nada que referenciar todavía.
            $table->foreignId('pre_restore_backup_id')->nullable()
                ->constrained('tenant_backups')->restrictOnDelete();

            $table->enum('estado', ['pendiente_confirmacion', 'en_proceso', 'completado', 'fallido'])
                ->default('pendiente_confirmacion');

            // Token de confirmación de un solo uso — el paso 2 del flujo (POST .../confirm)
            // lo exige en la URL. unique() real: dos previews nunca deberían poder colisionar
            // en el mismo token (probabilidad astronómica con 40 chars aleatorios, pero el
            // índice no cuesta nada y cierra la duda).
            $table->string('confirm_token', 64)->unique();
            $table->timestamp('confirm_token_expires_at');
            $table->timestamp('confirmed_at')->nullable();

            $table->text('error_message')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_restores');
    }
}
