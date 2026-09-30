<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\CreditoEstado;
use App\Models\Client\Client;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoPago;
use App\Models\User;
use App\Services\Creditos\Dto\CobroDelDia;
use App\Services\Creditos\Dto\DetalleCredito;
use App\Services\Creditos\Dto\SaldoCredito;
use App\Services\Creditos\Motor\AplicadorPagos;
use App\Services\Creditos\Motor\Dto\MoraCuota;
use App\Services\Creditos\Motor\Enums\EstadoCuota;
use App\Services\Creditos\Motor\Enums\OrigenPago;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

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

    /** Columnas por las que se puede ordenar el listado (el saldo no: sale del motor, no de SQL). */
    public const ORDENES = ['reciente', 'numero', 'cliente', 'desembolso', 'monto'];

    /**
     * @param array{estado?: string, cliente_id?: int, con_atraso?: bool, buscar?: string, orden?: string, direccion?: string} $filtros
     */
    public function listar(array $filtros, User $usuario): LengthAwarePaginator
    {
        $hoy = $this->reloj->hoy()->aTexto();
        $consulta = $this->alcance->aplicar(Credito::query()->with('cliente'), $usuario)
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->when($filtros['cliente_id'] ?? null, fn ($q, $id) => $q->where('cliente_id', $id))
            ->when($filtros['buscar'] ?? null, fn ($q, $texto) => $q->where(fn ($w) => $w
                ->where('numero_credito', 'ilike', "%{$texto}%")
                ->orWhereHas('cliente', fn ($c) => $c->where('full_name', 'ilike', "%{$texto}%")->orWhere('n_document', 'ilike', "%{$texto}%"))))
            ->when($filtros['con_atraso'] ?? false, fn ($q) => $this->conDeudaVencida($q, $hoy, '<'));

        $direccion = ($filtros['direccion'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        match ($filtros['orden'] ?? 'reciente') {
            'numero' => $consulta->orderBy('numero_credito', $direccion),
            'cliente' => $consulta->orderBy(Client::select('full_name')->whereColumn('clients.id', 'creditos.cliente_id'), $direccion),
            'desembolso' => $consulta->orderBy('fecha_desembolso', $direccion),
            'monto' => $consulta->orderBy('monto_capital', $direccion),
            default => null,
        };

        return $consulta->orderByDesc('id')->paginate(self::POR_PAGINA);
    }

    /** Página del listado con la situación en vivo de cada crédito (saldo, atraso, próximo pago). */
    public function listarConSituacion(array $filtros, User $usuario): LengthAwarePaginator
    {
        return $this->listar($filtros, $usuario)->through(fn (Credito $c): DetalleCredito => $this->detalle($c, $usuario));
    }

    public function detalle(Credito $credito, User $usuario): DetalleCredito
    {
        $this->alcance->asegurar($credito, $usuario);
        $credito->load('cliente')->cargarCuotasVigentes();
        if (! in_array($credito->estado, self::ESTADOS_CON_SITUACION, true)) {
            return new DetalleCredito($credito, null, 0, Dinero::aCentavos($credito->monto_capital), Dinero::aCentavos($credito->interes_total), 0);
        }

        $hoy = $this->reloj->hoy();
        $carga = $this->cargador->cargar($credito);
        $situacion = $this->aplicador->aplicar($carga->estado, $carga->pagos, $hoy);
        $saldo = SaldoCredito::calcular($carga->estado, $situacion, $hoy);

        return new DetalleCredito(
            $credito,
            $situacion,
            $situacion->finalizado ? 0 : $this->aplicador->montoExigible($carga->estado, $carga->pagos, $hoy),
            $saldo->capital,
            $saldo->interes,
            (int) max(array_map(static fn (MoraCuota $m): int => $m->diasAtraso, $situacion->moraAFecha) ?: [0]),
            $saldo,
        );
    }

    public function estadoCuenta(Credito $credito, User $usuario): Credito
    {
        $this->alcance->asegurar($credito, $usuario);

        return $credito->load([
            'cliente',
            'pagos' => fn ($q) => $q->orderBy('fecha_pago')->orderBy('id'),
            'pagos.aplicaciones' => fn ($q) => $q->where('vigente', true)->with('cuota:id,numero_cuota'),
            'condonaciones.cuota:id,numero_cuota',
            'cargos.cuota:id,numero_cuota',
            'castigos',
            'reprogramaciones.cuotas',
        ])->cargarCuotasVigentes();
    }

    /**
     * Créditos con deuda exigible a la fecha: alguna cuota vigente impaga vencida (o que vence
     * hoy, con '<='), o todas las cuotas pagadas pero todavía activo, que es cuando solo queda
     * mora o un cargo (1.5: no se finaliza con mora pendiente).
     *
     * @param Builder<Credito> $consulta
     * @return Builder<Credito>
     */
    private function conDeudaVencida(Builder $consulta, string $hoy, string $operador): Builder
    {
        $impagas = fn ($c) => $c
            ->whereColumn('credito_cuotas.version_cronograma', 'creditos.version_cronograma_actual')
            ->where('estado', EstadoCuota::Pendiente);

        return $consulta->where(fn ($w) => $w
            ->whereHas('cuotas', fn ($c) => $impagas($c)->where('fecha_vencimiento', $operador, $hoy))
            ->orWhere(fn ($x) => $x->where('estado', CreditoEstado::Activo)->whereDoesntHave('cuotas', $impagas)));
    }

    /**
     * Cobranza del día (03-api): créditos de su alcance con cuotas que vencen hoy o ya vencidas.
     *
     * @return list<DetalleCredito>
     */
    public function cobranzaDelDia(User $usuario): array
    {
        $hoy = $this->reloj->hoy()->aTexto();
        $creditos = $this->conDeudaVencida(
            $this->alcance->aplicar(Credito::query(), $usuario)->where('estado', CreditoEstado::Activo),
            $hoy,
            '<=',
        )->get();

        $items = $creditos->map(fn (Credito $c): DetalleCredito => $this->detalle($c, $usuario))->all();
        usort($items, static fn (DetalleCredito $a, DetalleCredito $b): int => $b->diasAtraso <=> $a->diasAtraso);

        return $items;
    }

    /**
     * Cobrado hoy en su alcance (cobros y liquidaciones válidos), agrupado por crédito:
     * el "Cobrado" de la Cobranza del día (mockup 4).
     *
     * @return list<CobroDelDia>
     */
    public function cobradosHoy(User $usuario): array
    {
        $pagos = CreditoPago::validos()
            ->whereIn('origen', [OrigenPago::Cobro, OrigenPago::Liquidacion])
            ->whereDate('fecha_pago', $this->reloj->hoy()->aTexto())
            ->whereHas('credito', fn (Builder $q) => $this->alcance->aplicar($q, $usuario))
            ->with(['credito.cliente', 'paymentMethod'])
            ->orderBy('fecha_pago')
            ->get();

        return $pagos->groupBy('credito_id')->map(static fn ($delCredito): CobroDelDia => new CobroDelDia(
            $delCredito->first()->credito,
            $delCredito->sum(static fn (CreditoPago $p): int => Dinero::aCentavos($p->monto_aplicado)),
            $delCredito->last()->fecha_pago,
            $delCredito->map(static fn (CreditoPago $p): ?string => $p->paymentMethod?->name)->filter()->unique()->values()->all(),
        ))->values()->all();
    }
}
