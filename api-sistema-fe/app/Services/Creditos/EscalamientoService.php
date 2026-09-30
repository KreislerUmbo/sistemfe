<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\CreditoEstado;
use App\Enums\Creditos\TipoCastigo;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoConfiguracion;
use App\Services\Creditos\Motor\Enums\EstadoCuota;
use App\Services\Creditos\Motor\Fecha;

/**
 * Proceso diario (00 1.11, 03-api decisión 5). Bloqueo por atraso y "cobrar al garante" se
 * calculan en vivo desde los días de atraso; lo único que se escribe es el castigo automático.
 * Idempotente: un crédito ya castigado no vuelve a entrar.
 */
class EscalamientoService
{
    public function __construct(private readonly CastigoService $castigos)
    {
    }

    /** @return int créditos castigados en esta corrida */
    public function ejecutar(Fecha $hoy): int
    {
        $limite = $hoy->sumarDias(-CreditoConfiguracion::actual()->dias_para_castigo);

        $candidatos = Credito::where('estado', CreditoEstado::Activo)
            ->whereHas('cuotas', fn ($q) => $q
                ->whereColumn('credito_cuotas.version_cronograma', 'creditos.version_cronograma_actual')
                ->where('estado', EstadoCuota::Pendiente)
                // Atraso > dias_para_castigo ⇔ vencimiento anterior a hoy − dias_para_castigo.
                ->where('fecha_vencimiento', '<', $limite->aTexto()))
            ->get();

        foreach ($candidatos as $credito) {
            $this->castigos->castigar($credito, TipoCastigo::Automatico, null, null, $hoy);
        }

        return $candidatos->count();
    }
}
