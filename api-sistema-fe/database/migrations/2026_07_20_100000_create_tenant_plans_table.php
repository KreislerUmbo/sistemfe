<?php

declare(strict_types=1);

// Catálogo de planes de suscripción del propio panel superadmin — límites de negocio
// que un tenant contrata (usuarios, comprobantes/mes, storage), sin relación con la
// tabla 'plans' ya existente (esa es de precios de módulos del marketplace SaaS,
// vacía/sin modelo, ver plan-panel-superadmin.md Fase B.0.5).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantPlansTable extends Migration
{
    // 'central' — consolidada desde B.0.5, ver create_central_users_table.php.
    protected $connection = 'central';

    public function up(): void
    {
        Schema::create('tenant_plans', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            // Nullable = sin límite.
            $table->unsignedInteger('limite_usuarios')->nullable();
            $table->unsignedInteger('limite_comprobantes_mes')->nullable();
            $table->unsignedInteger('limite_storage_mb')->nullable();
            $table->decimal('precio_mensual', 8, 2);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_plans');
    }
}
