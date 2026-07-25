<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCentralAuditLogsTable extends Migration
{
    // 'central' consolidada — ver comentario en create_central_users_table.php.
    protected $connection = 'central';

    public function up(): void
    {
        Schema::create('central_audit_logs', function (Blueprint $table) {
            $table->id();
            // Nullable: una acción puede originarse del propio sistema (scheduled
            // command, ej. tenants:check-overdue-payments) sin un central_user detrás.
            $table->foreignId('central_user_id')->nullable()
                ->constrained('central_users')->nullOnDelete();
            // Ej: 'tenant.created', 'tenant.suspended'.
            $table->string('action');
            // Sujeto de la acción (polimórfico manual, mismo criterio ya usado en
            // cash_movements.reference_type/reference_id — sin morphs() de Eloquent,
            // sin precedente de morphTo en el proyecto).
            $table->string('auditable_type')->nullable();
            $table->string('auditable_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_audit_logs');
    }
}
