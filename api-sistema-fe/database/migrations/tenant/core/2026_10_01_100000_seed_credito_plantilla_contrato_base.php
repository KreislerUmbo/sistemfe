<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Módulo Créditos (Fase 4b, Plan 1.15): plantilla de contrato por defecto, marcada "revisar con
 * su abogado". Solo si el tenant todavía no tiene ninguna (no pisa una plantilla propia).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('credito_plantillas')->where('tipo', 'contrato')->exists()) {
            return;
        }

        DB::table('credito_plantillas')->insert([
            'tipo' => 'contrato',
            'version' => 1,
            'contenido' => trim((string) file_get_contents(resource_path('views/pdf/creditos/plantilla_contrato_base.html'))),
            'activa' => true,
            'creado_por' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('credito_plantillas')->where('tipo', 'contrato')->where('version', 1)->whereNull('creado_por')->delete();
    }
};
