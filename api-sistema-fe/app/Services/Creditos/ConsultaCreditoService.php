<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\CreditoEstado;
use App\Models\Creditos\Credito;
use App\Models\User;
use App\Services\Creditos\Dto\DetalleCredito;
use App\Services\Creditos\Motor\AplicadorPagos;
use App\Services\Creditos\Motor\Dto\MoraCuota;
use App\Services\Creditos\Motor\Enums\EstadoCuota;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Consultas (03-api): listado, detalle con situación en vivo, estado de cuenta y cobranza del
 * día. Mora y exigible salen siempre del motor, nunca de fórmulas en SQL (00 §4).
 */
class ConsultaCreditoService
{
    private const POR_PAGINA = 20;
    private const ESTADOS_CON_SITUACION = [CreditoEstado::Activo, CreditoEstado::Castigado, CreditoEstado::Finalizado];

    public function __construct(
        private readonly CargadorCredito $cargador,
        private readonly AplicadorPagos $aplicador,
        private readonly AlcanceCartera $alcance,
        private readonly Reloj $reloj,
    ) {
    }

    /** @param array{estado?: string, cliente_id?: int, con_atraso?: bool, buscar?: string} $filtros */
    public function listar(array $filtros, User $usuario): LengthAwarePaginator
    {
        $hoy = $this->reloj->hoy()->aTexto();
        $consulta = $this->alcance->aplicar(Credito::query()->with('cliente'), $usuario)
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->when($filtros['cliente_id'] ?? null, fn ($q, $id) => $q->where('cliente_id', $id))
            ->when($filtros['buscar'] ?? null, fn ($q, $texto) => $q->where(fn ($w) => $w
                ->where('numero_credito', 'ilike', "%{$texto}%")
                ->orWhereHas('cliente', fn ($c) => $c->where('full_name', 'ilike', "%{$texto}%")->orWhere('n_document', 'ilike', "%{$texto}%"))))
            ->when($filtros['con_atraso'] ?? false, fn ($q) => $q->whereHas('cuotas', fn ($c) => $c
                ->whereColumn('credito_cuotas.version_cronograma', 'creditos.version_cronograma_actual')
                ->where('estado', EstadoCuota::Pendiente)
                ->where('fecha_vencimiento', '<', $hoy)));

        return $consulta->orderByDesc('id')->paginate(self::POR_PAGINA);
    }

    public function detalle(Credito $credito, User $usuario): DetalleCredito
    {
        $this->alcance->asegurar($credito, $usuario);
        $credito->load(['cliente', 'cuotasVigentes']);
        if (! in_array($credito->estado, self::ESTADOS_CON_SITUACION, true)) {
            return new DetalleCredito($credito, null, 0, Dinero::aCentavos($credito->monto_capital), Dinero::aCentavos($credito->interes_total), 0);
        }

        $hoy = $this->reloj->hoy();
        $carga = $this->cargador->cargar($credito);
        $situacion = $this->aplicador->aplicar($carga->estado, $carga->pagos, $hoy);
        $saldoCapital = 0;
        $saldoInteres = 0;
        foreach ($carga->estado->cuotas as $cuota) {
            $saldada = $situacion->cuota($cuota->numero);
            $saldoCapital += $cuota->montoCapital - $saldada->capitalPagado;
            $saldoInteres += $cuota->montoInteres - $saldada->interesPagado - $saldada->interesCondonado;
        }

        return new DetalleCredito(
            $credito,
            $situacion,
            $situacion->finalizado ? 0 : $this->aplicador->montoExigible($carga->estado, $carga->pagos, $hoy),
            $saldoCapital,
            $saldoInteres,
            (int) max(array_map(static fn (MoraCuota $m): int => $m->diasAtraso, $situacion->moraAFecha) ?: [0]),
        );
    }

    public function estadoCuenta(Credito $credito, User $usuario): Credito
    {
        $this->alcance->asegurar($credito, $usuario);

        return $credito->load([
            'cliente',
            'cuotasVigentes',
            'pagos' => fn ($q) => $q->orderBy('fecha_pago')->orderBy('id'),
            'pagos.aplicaciones' => fn ($q) => $q->where('vigente', true)->with('cuota:id,numero_cuota'),
            'condonaciones.cuota:id,numero_cuota',
            'cargos.cuota:id,numero_cuota',
            'castigos',
            'reprogramaciones.cuotas',
        ]);
    }

    /**
     * Cobranza del día (03-api): créditos de su alcance con cuotas que vencen hoy o ya vencidas.
     *
     * @return list<DetalleCredito>
     */
    public function cobranzaDelDia(User $usuario): array
    {
        $hoy = $this->reloj->hoy()->aTexto();
        $creditos = $this->alcance->aplicar(Credito::query(), $usuario)
            ->where('estado', CreditoEstado::Activo)
            ->whereHas('cuotas', fn ($c) => $c
                ->whereColumn('credito_cuotas.version_cronograma', 'creditos.version_cronograma_actual')
                ->where('estado', EstadoCuota::Pendiente)
                ->where('fecha_vencimiento', '<=', $hoy))
            ->get();

        $items = $creditos->map(fn (Credito $c): DetalleCredito => $this->detalle($c, $usuario))->all();
        usort($items, static fn (DetalleCredito $a, DetalleCredito $b): int => $b->diasAtraso <=> $a->diasAtraso);

        return $items;
    }
}
