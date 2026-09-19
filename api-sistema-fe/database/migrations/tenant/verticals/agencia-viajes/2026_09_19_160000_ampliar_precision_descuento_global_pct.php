<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Guardrail (19-sep-2026, hallazgo real del usuario) — alternativas.
// descuento_global_pct nació como decimal(5,2) (2 decimales), suficiente
// para un % tipeado a mano, pero AlternativaController::
// aplicarDescuentoGlobalMonto() lo usa también para GUARDAR el %
// EFECTIVO resuelto a partir de un monto (config 'monto' de
// configuracion_agencia.modo_descuento_global) — ese % efectivo rara vez
// es un número redondo (ej. monto=2 sobre un total de 2118 → 0.0944%).
// Con solo 2 decimales, Postgres truncaba 0.0944 a 0.09 al guardar — y al
// recargar la cotización, el monto "equivalente" reconstruido desde ese
// 0.09 salía visiblemente distinto del que el vendedor había tipeado
// (2 → 1.91), aunque el TOTAL en sí ya calculaba bien. Raw ALTER en vez
// de Blueprint::change() (sin doctrine/dbal instalado en este proyecto,
// ver CLAUDE.md) — ensancha a 9,4 (mismo patrón de precisión que
// alternativas.tipo_cambio_aplicado, ya decimal(x,4) desde antes).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE alternativas ALTER COLUMN descuento_global_pct TYPE decimal(9,4)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE alternativas ALTER COLUMN descuento_global_pct TYPE decimal(5,2)');
    }
};
