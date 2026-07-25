<?php

declare(strict_types=1);

// Key-value simple de configuración global de la plataforma (plan-panel-superadmin.md,
// principio "Configuración como datos, no como código"). Sin seeder de valores todavía
// en esta fase — quedan documentados aquí los keys que se van a usar pronto (Fase B.2):
//
//   dia_corte_default              → día del mes por defecto para el corte de facturación
//                                     (override opcional por tenant en tenant_subscriptions)
//   dias_gracia_suspension_default → días de gracia por defecto antes de suspender un
//                                     tenant por falta de pago (override opcional por tenant)
//   backup_retention_days          → días de retención de backups automáticos (Fase C)

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformSettingsTable extends Migration
{
    // 'central' consolidada — ver comentario en create_central_users_table.php.
    protected $connection = 'central';

    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
}
