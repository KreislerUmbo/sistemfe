<?php

declare(strict_types=1);

namespace App\Services\Creditos\Reportes;

use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoCarteraDiaria;
use App\Services\Creditos\Dinero;
use App\Services\Creditos\Motor\Fecha;
use Illuminate\Support\Facades\DB;

/**
 * Foto diaria de cartera (04d, Plan 1.16): por crédito vivo, la situación del día (mismo cálculo
 * que los reportes en vivo) con su asesor y cobrador. Sirve para tendencias e históricos; los
 * reportes de hoy se calculan en vivo. Idempotente: volver a correrla el mismo día la reemplaza.
 */
class FotoCarteraService
{
    public function __construct(
        private readonly CarteraEnVivo $cartera,
        private readonly AsignacionesVigentes $asignaciones,
    ) {
    }

    public function tomar(Fecha $corte): int
    {
        $situaciones = $this->cartera->calcular(Credito::query()->whereIn('estado', CarteraEnVivo::ESTADOS), $corte);
        $asignaciones = $this->asignaciones->de(array_map(static fn (SituacionCartera $s): int => $s->credito->cliente_id, $situaciones));
        $ahora = now();

        $filas = array_map(static fn (SituacionCartera $s): array => [
            'fecha_corte' => $corte->aTexto(),
            'credito_id' => $s->credito->id,
            'cliente_id' => $s->credito->cliente_id,
            'asesor_id' => $asignaciones[$s->credito->cliente_id]['asesor_id'],
            'cobrador_id' => $asignaciones[$s->credito->cliente_id]['cobrador_id'],
            'saldo_capital' => Dinero::aSoles($s->saldoCapital),
            'saldo_interes' => Dinero::aSoles($s->saldoInteres),
            'mora_pendiente' => Dinero::aSoles($s->mora),
            'dias_atraso' => $s->diasAtraso,
            'rango_atraso' => $s->rangoAtraso(),
            'estado' => $s->credito->estado->value,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ], $situaciones);

        DB::transaction(static function () use ($corte, $filas): void {
            CreditoCarteraDiaria::where('fecha_corte', $corte->aTexto())->delete();
            foreach (array_chunk($filas, 500) as $bloque) {
                CreditoCarteraDiaria::insert($bloque);
            }
        });

        return count($filas);
    }
}
