<?php

// Fase 4c — la ficha incompleta es una regla de otorgamiento autorizable más
// (ReglaLimite::FichaIncompleta). credito_autorizaciones.regla es un enum de Laravel (CHECK en
// Postgres) con la lista cerrada de reglas: sin esto la autorización falla al guardarse.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CHECK = 'credito_autorizaciones_regla_check';

    public function up(): void
    {
        $this->reglas(['max_creditos', 'deuda_maxima', 'moroso', 'bloqueado', 'ficha_incompleta']);
    }

    public function down(): void
    {
        $this->reglas(['max_creditos', 'deuda_maxima', 'moroso', 'bloqueado']);
    }

    /** @param list<string> $reglas */
    private function reglas(array $reglas): void
    {
        $lista = implode(', ', array_map(static fn (string $r): string => "'{$r}'::character varying", $reglas));
        DB::statement('ALTER TABLE credito_autorizaciones DROP CONSTRAINT IF EXISTS ' . self::CHECK);
        DB::statement('ALTER TABLE credito_autorizaciones ADD CONSTRAINT ' . self::CHECK . " CHECK (regla::text = ANY (ARRAY[{$lista}]::text[]))");
    }
};
