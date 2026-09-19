<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Guardrail (19-sep-2026) — misma causa que
// ampliar_precision_descuento_global_pct (hace unos minutos), columna
// hermana que se me pasó en esa pasada: alternativa_items.descuento_pct
// nació decimal(5,2), pero AlternativaController::aplicarDescuentoGlobal()
// guarda ahí el mismo % EFECTIVO de 4 decimales que ya se corrigió a nivel
// de alternativas.descuento_global_pct (ej. 0.0944, no 0.09). El
// precio_convertido de ESE mismo request se calcula bien (usa la variable
// PHP con precisión completa, antes de guardar), pero cualquier
// recalculo POSTERIOR que lea este campo de vuelta desde la base —ej.
// CotizacionController::recalcularItemsPorPersona() (dispara al agregar/
// quitar pasajeros vía actualizarPasajeros()), que preserva el descuento
// manual ya aplicado al recalcular precio de lista— terminaría leyendo
// el 0.09 truncado y recomputando un precio_convertido distinto (unos
// centavos de más o de menos) del que el vendedor había dejado, sin que
// nadie tocara nada. Mismo ensanche a decimal(9,4), mismo
// motivo por el que no se usa Blueprint::change() (sin doctrine/dbal, ver
// CLAUDE.md).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE alternativa_items ALTER COLUMN descuento_pct TYPE decimal(9,4)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE alternativa_items ALTER COLUMN descuento_pct TYPE decimal(5,2)');
    }
};
