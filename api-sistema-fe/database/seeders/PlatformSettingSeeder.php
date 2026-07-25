<?php

namespace Database\Seeders;

use App\Models\Central\PlatformSetting;
use Illuminate\Database\Seeder;

class PlatformSettingSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            [
                'key' => 'dia_corte_default',
                'value' => '28',
                'description' => 'Día del mes por defecto para el corte de facturación de suscripciones (override opcional por tenant en tenant_subscriptions.dia_corte).',
            ],
            [
                'key' => 'dias_gracia_suspension_default',
                'value' => '60',
                'description' => 'Días de gracia por defecto antes de suspender un tenant por falta de pago (2 meses estándar — override opcional por tenant en tenant_subscriptions.dias_gracia_suspension).',
            ],
            [
                'key' => 'pg_dump_path',
                'value' => 'pg_dump',
                'description' => 'Ruta al ejecutable pg_dump usado por Fase C (Backups) — específico de cada entorno/servidor, nunca hardcodeado en código. Default "pg_dump" asume que está en el PATH; en este entorno de desarrollo (Windows/XAMPP) el valor real configurado es la ruta completa al binario de PostgreSQL instalado.',
            ],
            [
                'key' => 'dias_retencion_backups_default',
                'value' => '30',
                'description' => 'Días de retención por defecto para backups AUTOMÁTICOS (tenant_backups.tipo=automatico) — los más viejos que este umbral se borran (fila + archivo) en cada corrida de tenants:run-automatic-backups. Nunca aplica a backups manuales (tipo=manual) — esos solo los borra quien los creó, a propósito.',
            ],
            [
                'key' => 'pg_restore_path',
                'value' => 'pg_restore',
                'description' => 'Ruta al ejecutable pg_restore usado por Fase C.3 (restauración) — específico de cada entorno/servidor, igual criterio que pg_dump_path. Default "pg_restore" asume PATH; en este entorno de desarrollo el valor real es la ruta completa al binario instalado.',
            ],
        ];

        foreach ($defaults as $setting) {
            PlatformSetting::firstOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
