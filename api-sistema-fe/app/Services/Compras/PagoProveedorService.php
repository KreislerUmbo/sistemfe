<?php

namespace App\Services\Compras;

use App\Models\Cash\CashMovement;
use App\Models\Compras\AplicacionPagoProveedor;
use App\Models\Compras\Compra;
use App\Models\Compras\PagoProveedor;
use App\Models\Compras\ReembolsoProveedor;
use App\Models\CuentaBancaria;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\HoraPeru;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * T-136 — pagos a proveedor y sus aplicaciones. Todo rechazo sale como CompraException.
 *
 * Orden de bloqueo, siempre: primero el pago y después las compras, en orden de id.
 */
class PagoProveedorService
{
    private const MAX_APLICACIONES = 100;

    public function __construct(private OrigenDineroService $origenDinero) {}

    /**
     * El único cálculo de saldo, en lote:
     * saldo = total − notas de crédito vigentes que la modifican − aplicado de pagos vigentes
     * (`monto_compra`, en la moneda de la compra) + reembolsos vigentes de la compra.
     * `por_aprobar` es lo aplicado desde pagos pendientes de aprobación: no cambia el saldo
     * mostrado, pero no se puede volver a aplicar (`limite`).
     *
     * @param  array<int, int>  $compraIds
     * @return array<int, array{total: BigDecimal, notas: BigDecimal, aplicado: BigDecimal, por_aprobar: BigDecimal, saldo: BigDecimal, limite: BigDecimal}>
     */
    public function saldos(array $compraIds): array
    {
        if ($compraIds === []) {
            return [];
        }

        // Solo las notas en la moneda de la compra: nunca se convierte (desde T-162 no entra otra).
        $notas = DB::table('compras as n')
            ->join('compras as c', fn ($j) => $j->on('c.id', '=', 'n.compra_referencia_id')->on('c.moneda', '=', 'n.moneda'))
            ->whereIn('c.id', $compraIds)
            ->whereNull('n.anulada_at')
            ->where('n.tipo_comprobante_codigo', Compra::TIPO_NOTA_CREDITO)
            ->groupBy('c.id')
            ->selectRaw('c.id, sum(n.total) as monto')
            ->pluck('monto', 'id');

        $aplicado = DB::table('aplicaciones_pago_proveedor as a')
            ->join('pagos_proveedor as p', 'p.id', '=', 'a.pago_proveedor_id')
            ->whereIn('a.compra_id', $compraIds)
            ->whereNull('a.desaplicada_at')
            ->where('p.estado', '<>', PagoProveedor::ANULADO)
            ->groupBy('a.compra_id', 'p.estado')
            ->selectRaw('a.compra_id, p.estado, sum(a.monto_compra) as monto')
            ->get()
            ->groupBy('compra_id');

        $reembolsado = ReembolsoProveedorService::totalesPorCompra($compraIds);

        $cero = BigDecimal::of('0.00');
        $monto = fn ($valor) => $valor === null ? $cero : BigDecimal::of((string) $valor);
        $saldos = [];
        foreach (Compra::whereIn('id', $compraIds)->pluck('total', 'id') as $id => $total) {
            $porEstado = collect($aplicado->get($id, []))->pluck('monto', 'estado');
            $total = $monto($total);
            $acreditado = $monto($notas->get($id));
            $pagado = $monto($porEstado->get(PagoProveedor::VIGENTE));
            $porAprobar = $monto($porEstado->get(PagoProveedor::PENDIENTE));
            $saldo = $total->minus($acreditado)->minus($pagado)->plus($reembolsado[$id] ?? $cero);

            $saldos[$id] = [
                'total' => $total,
                'notas' => $acreditado,
                'aplicado' => $pagado,
                'por_aprobar' => $porAprobar,
                'saldo' => $saldo,
                'limite' => $saldo->minus($porAprobar),
            ];
        }

        return $saldos;
    }

    /**
     * Saldo y estado de pago de cada compra, para el listado y la ficha. Las anuladas y las notas
     * de crédito no tienen estado de pago.
     *
     * @param  Collection<int, Compra>  $compras
     * @return array<int, array{saldo: ?string, estado_pago: ?string, por_aprobar: ?string}>
     */
    public function estados(Collection $compras): array
    {
        $conPago = $compras->filter(fn (Compra $c) => $this->sePaga($c));
        $saldos = $this->saldos($conPago->pluck('id')->all());
        $hoy = HoraPeru::hoyTexto();

        $estados = [];
        foreach ($compras as $compra) {
            if (! isset($saldos[$compra->id])) {
                $estados[$compra->id] = ['saldo' => null, 'estado_pago' => null, 'por_aprobar' => null];

                continue;
            }

            $fila = $saldos[$compra->id];
            $estado = match (true) {
                ! $fila['saldo']->isPositive() => 'pagado', // saldo negativo: a favor, se reembolsa
                $this->vencimiento($compra) < $hoy => 'vencido',
                $fila['aplicado']->isPositive() => 'parcial',
                default => 'pendiente',
            };

            $estados[$compra->id] = [
                'saldo' => (string) $fila['saldo']->toScale(2),
                'estado_pago' => $estado,
                'por_aprobar' => (string) $fila['por_aprobar']->toScale(2),
            ];
        }

        return $estados;
    }

    /** Cuándo vence una compra (`Y-m-d`): su fecha de vencimiento, o la emisión si es al contado. */
    public function vencimiento(Compra $compra): string
    {
        return ($compra->fecha_vencimiento ?? $compra->fecha_emision)->toDateString();
    }

    public function sePaga(Compra $compra): bool
    {
        return ! $compra->estaAnulada() && $compra->tipo_comprobante_codigo !== Compra::TIPO_NOTA_CREDITO;
    }

    /** Monto aplicado y no desaplicado de un pago, en la moneda del pago. */
    public function aplicadoDe(PagoProveedor $pago): BigDecimal
    {
        return $this->sumar($pago->aplicaciones()->activas()->pluck('monto'));
    }

    /**
     * T-140 — lo que cada pago todavía tiene sin usar, en su moneda: monto − aplicado activo −
     * reembolsos vigentes. No mira el estado: un pago anulado o por aprobar lo decide quien llama.
     *
     * @param  array<int, int>  $pagoIds
     * @return array<int, BigDecimal>
     */
    public function disponibles(array $pagoIds): array
    {
        $disponibles = [];
        // Por bloques: no pasar el límite de parámetros de PDO.
        foreach (array_chunk($pagoIds, 1000) as $bloque) {
            $aplicado = DB::table('aplicaciones_pago_proveedor')
                ->whereIn('pago_proveedor_id', $bloque)
                ->whereNull('desaplicada_at')
                ->groupBy('pago_proveedor_id')
                ->selectRaw('pago_proveedor_id, sum(monto) as monto')
                ->pluck('monto', 'pago_proveedor_id');
            $reembolsado = ReembolsoProveedorService::totalesPorPago($bloque);

            foreach (PagoProveedor::whereIn('id', $bloque)->pluck('monto', 'id') as $id => $monto) {
                $disponibles[$id] = BigDecimal::of((string) $monto)
                    ->minus((string) ($aplicado[$id] ?? '0'))
                    ->minus($reembolsado[$id] ?? BigDecimal::of('0.00'))
                    ->toScale(2);
            }
        }

        return $disponibles;
    }

    /** T-140 (D7) — el único punto de conversión entre soles y dólares: $tc = soles por dólar. */
    public function convertir(BigDecimal $monto, string $de, string $a, BigDecimal $tc): BigDecimal
    {
        return match (true) {
            $de === $a => $monto->toScale(2, RoundingMode::HALF_UP),
            $de === 'USD' => $monto->multipliedBy($tc)->toScale(2, RoundingMode::HALF_UP),
            default => $monto->dividedBy($tc, 2, RoundingMode::HALF_UP),
        };
    }

    /**
     * T-140 (D9) — diferencia de cambio en soles de una aplicación activa de un pago vigente a una
     * compra en USD: positiva = pérdida. null si no corresponde o si falta el T.C. del pago.
     */
    public function diferenciaDeCambio(AplicacionPagoProveedor $aplicacion, Compra $compra, PagoProveedor $pago): ?BigDecimal
    {
        if ($aplicacion->desaplicada_at !== null || $pago->estado !== PagoProveedor::VIGENTE
            || $compra->moneda !== 'USD' || $compra->tipo_cambio === null) {
            return null;
        }
        $registrado = BigDecimal::of((string) $aplicacion->monto_compra)->multipliedBy((string) $compra->tipo_cambio);

        // Pago en soles: los soles que de verdad salieron (`monto`, ya redondeado al convertir) menos
        // lo registrado. Con monto_compra × (tc − tc compra) podía salir un céntimo distinto.
        if ($pago->moneda !== $compra->moneda) {
            return BigDecimal::of((string) $aplicacion->monto)->minus($registrado)->toScale(2, RoundingMode::HALF_UP);
        }
        if ($pago->tipo_cambio === null) {
            return null;
        }

        return BigDecimal::of((string) $aplicacion->monto_compra)
            ->multipliedBy((string) $pago->tipo_cambio)
            ->minus($registrado)
            ->toScale(2, RoundingMode::HALF_UP);
    }

    /**
     * T-137 — por proveedor y moneda, en una sola consulta: lo «a cuenta» (monto de los pagos
     * vigentes menos lo aplicado y lo reembolsado) y lo que espera aprobación en Caja (aplicado o
     * no). Es lo mismo que disponibles(), agregado.
     *
     * @param  array<int, int>|null  $proveedorIds  null = todos
     * @return array<int, array<string, array{a_cuenta: BigDecimal, por_aprobar: BigDecimal}>>
     */
    public function sinAplicarPorProveedor(?array $proveedorIds = null): array
    {
        $aplicado = DB::table('aplicaciones_pago_proveedor')
            ->whereNull('desaplicada_at')
            ->groupBy('pago_proveedor_id')
            ->selectRaw('pago_proveedor_id, sum(monto) as monto');
        $reembolsado = DB::table('reembolsos_proveedor')
            ->where('estado', ReembolsoProveedor::VIGENTE)
            ->whereNotNull('pago_proveedor_id')
            ->groupBy('pago_proveedor_id')
            ->selectRaw('pago_proveedor_id, sum(monto) as monto');

        $filas = DB::table('pagos_proveedor as p')
            ->leftJoinSub($aplicado, 'a', 'a.pago_proveedor_id', '=', 'p.id')
            ->leftJoinSub($reembolsado, 'r', 'r.pago_proveedor_id', '=', 'p.id')
            ->where('p.estado', '<>', PagoProveedor::ANULADO)
            ->when($proveedorIds !== null, fn ($q) => $q->whereIn('p.proveedor_id', $proveedorIds))
            ->groupBy('p.proveedor_id', 'p.moneda', 'p.estado')
            ->selectRaw('p.proveedor_id, p.moneda, p.estado, sum(p.monto) as monto, sum(coalesce(a.monto, 0) + coalesce(r.monto, 0)) as usado')
            ->get();

        $cero = BigDecimal::of('0.00');
        $resultado = [];
        foreach ($filas as $fila) {
            $resultado[$fila->proveedor_id][$fila->moneda] ??= ['a_cuenta' => $cero, 'por_aprobar' => $cero];
            $monto = BigDecimal::of((string) $fila->monto);
            $parte = &$resultado[$fila->proveedor_id][$fila->moneda];
            if ($fila->estado === PagoProveedor::VIGENTE) {
                $parte['a_cuenta'] = $monto->minus((string) $fila->usado);
            } else {
                $parte['por_aprobar'] = $monto;
            }
            unset($parte);
        }

        return $resultado;
    }

    /** Registra el pago y, si vienen, sus aplicaciones, todo en una transacción. */
    public function registrar(array $entrada, User $usuario): PagoProveedor
    {
        $datos = $this->validar($entrada);

        try {
            return DB::transaction(function () use ($datos, $usuario) {
                $aplicaciones = $datos['aplicaciones'];
                unset($datos['aplicaciones']);

                $proveedor = Proveedor::findOrFail($datos['proveedor_id']);
                $pago = new PagoProveedor($datos + ['user_id' => $usuario->id, 'estado' => PagoProveedor::VIGENTE]);
                $this->registrarSalida($pago, $proveedor, $usuario);
                $pago->save();

                if ($aplicaciones) {
                    $this->crearAplicaciones($pago, $aplicaciones, $usuario);
                }

                return $pago;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Carrera: dos registros a la vez con la misma operación.
            if ($existente = $this->origenDinero->operacionRegistrada(PagoProveedor::class, $datos)) {
                throw $this->origenDinero->yaRegistrada($existente);
            }

            throw $e;
        }
    }

    /** Aplica a compras lo que un pago ya registrado tiene «a cuenta». */
    public function aplicar(PagoProveedor $pago, array $aplicaciones, User $usuario): PagoProveedor
    {
        $aplicaciones = $this->validarAplicaciones(['aplicaciones' => $aplicaciones], true);

        return DB::transaction(function () use ($pago, $aplicaciones, $usuario) {
            $actual = $this->bloquear($pago->id);
            if ($actual->estaAnulado()) {
                throw new CompraException('El pago está anulado.');
            }
            $this->crearAplicaciones($actual, $aplicaciones, $usuario);

            return $actual;
        });
    }

    /** El monto vuelve a quedar «a cuenta» del pago. */
    public function desaplicar(AplicacionPagoProveedor $aplicacion, ?string $motivo, User $usuario): PagoProveedor
    {
        $motivo = $this->exigirMotivo($motivo, 'Indica el motivo para desaplicar.');

        return DB::transaction(function () use ($aplicacion, $motivo, $usuario) {
            $pago = $this->bloquear($aplicacion->pago_proveedor_id);
            $this->bloquearCompras([$aplicacion->compra_id]);
            $actual = AplicacionPagoProveedor::findOrFail($aplicacion->id);
            if ($actual->desaplicada_at !== null) {
                throw new CompraException('Ese pago ya está desaplicado de la compra.');
            }
            if ($pago->estado === PagoProveedor::VIGENTE) {
                $this->exigirComprasSinReembolsos([$actual->compra_id]);
            }
            $this->marcarDesaplicada($actual, $motivo, $usuario);

            return $pago;
        });
    }

    /** Anular: desaplica todo y corrige la salida del dinero. Nunca se borra nada. */
    public function anular(PagoProveedor $pago, ?string $motivo, User $usuario): PagoProveedor
    {
        $motivo = $this->exigirMotivo($motivo, 'Indica el motivo de la anulación.');

        return DB::transaction(function () use ($pago, $motivo, $usuario) {
            $actual = $this->bloquear($pago->id);
            if ($actual->estaAnulado()) {
                throw new CompraException('El pago ya está anulado.');
            }
            // T-140: ni lo que se devolvió de este pago ni el saldo a favor que ayudó a crear.
            if ($actual->reembolsos()->vigentes()->exists()) {
                throw new CompraException('Este pago tiene reembolsos: anúlalos primero.');
            }
            if ($actual->estado === PagoProveedor::VIGENTE) {
                $compraIds = $actual->aplicaciones()->activas()->pluck('compra_id')->all();
                $this->bloquearCompras($compraIds);
                $this->exigirComprasSinReembolsos($compraIds);
            }
            // Solo Caja movió dinero que haya que devolver: Banco solo se marca.
            if ($actual->origen === PagoProveedor::ORIGEN_CAJA) {
                $this->origenDinero->revertirEnCaja($actual->cash_movement_id, $usuario);
            }
            $this->marcarAnulado($actual, $motivo, $usuario);

            return $actual;
        });
    }

    /** El egreso de Caja de un pago se aprobó (dentro de la transacción de Caja). */
    public function aprobado(CashMovement $movimiento): void
    {
        $this->delMovimiento($movimiento)->update(['estado' => PagoProveedor::VIGENTE]);
    }

    /** El egreso de Caja se rechazó: el pago queda anulado y sus aplicaciones, desaplicadas. */
    public function rechazado(CashMovement $movimiento, User $usuario): void
    {
        $this->marcarAnulado($this->delMovimiento($movimiento), 'Egreso rechazado en Caja', $usuario);
    }

    private function delMovimiento(CashMovement $movimiento): PagoProveedor
    {
        return PagoProveedor::where('cash_movement_id', $movimiento->id)->lockForUpdate()->firstOrFail();
    }

    /** Solo dentro de una transacción. */
    private function bloquear(int $pagoId): PagoProveedor
    {
        return PagoProveedor::whereKey($pagoId)->lockForUpdate()->firstOrFail();
    }

    /** @return Collection<int, Compra> por id, bloqueadas en orden de id */
    private function bloquearCompras(array $ids): Collection
    {
        return Compra::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    // T-140: con un reembolso vigente en la compra, lo pagado que lo sostiene no se mueve.
    // Con las compras ya bloqueadas.
    private function exigirComprasSinReembolsos(array $compraIds): void
    {
        $reembolso = ReembolsoProveedor::vigentes()->whereIn('compra_id', $compraIds)->with('compra')->first();
        if ($reembolso) {
            throw new CompraException("La compra {$reembolso->compra->serie}-{$reembolso->compra->numero} tiene reembolsos: anúlalos primero.");
        }
    }

    // Marca anulado y desaplica, con las compras bloqueadas.
    private function marcarAnulado(PagoProveedor $pago, string $motivo, User $usuario): void
    {
        $activas = $pago->aplicaciones()->activas()->get();
        $this->bloquearCompras($activas->pluck('compra_id')->all());
        foreach ($activas as $aplicacion) {
            $this->marcarDesaplicada($aplicacion, mb_substr("Pago anulado: {$motivo}", 0, 255), $usuario);
        }

        $pago->update([
            'estado' => PagoProveedor::ANULADO,
            'anulado_at' => now(),
            'anulado_por' => $usuario->id,
            'motivo_anulacion' => $motivo,
        ]);
    }

    private function marcarDesaplicada(AplicacionPagoProveedor $aplicacion, string $motivo, User $usuario): void
    {
        $aplicacion->update([
            'desaplicada_at' => now(),
            'desaplicada_por' => $usuario->id,
            'motivo_desaplicacion' => $motivo,
        ]);
    }

    // Con el pago ya bloqueado (o recién creado en esta transacción). El `monto` que llega es lo que
    // se cancela de la compra, en su moneda.
    private function crearAplicaciones(PagoProveedor $pago, array $aplicaciones, User $usuario): void
    {
        $compras = $this->bloquearCompras(array_column($aplicaciones, 'compra_id'));
        $saldos = $this->saldos($compras->keys()->all());
        $disponible = $this->disponibles([$pago->id])[$pago->id];

        foreach ($aplicaciones as $aplicacion) {
            $compra = $compras->get((int) $aplicacion['compra_id']);
            if (! $compra) {
                throw new CompraException('Una de las compras no existe.');
            }
            $nombre = "{$compra->serie}-{$compra->numero}";
            $montoCompra = BigDecimal::of((string) $aplicacion['monto'])->toScale(2);

            if ((int) $compra->proveedor_id !== (int) $pago->proveedor_id) {
                throw new CompraException("La compra {$nombre} es de otro proveedor.");
            }
            if ($compra->estaAnulada()) {
                throw new CompraException("La compra {$nombre} está anulada.");
            }
            if ($compra->tipo_comprobante_codigo === Compra::TIPO_NOTA_CREDITO) {
                throw new CompraException("{$nombre} es una nota de crédito: no se paga.");
            }

            // Otra moneda (D3): la conversión es explícita y queda guardada.
            $tipoCambio = null;
            $monto = $montoCompra;
            if ($compra->moneda !== $pago->moneda) {
                $tipoCambio = (string) ($aplicacion['tipo_cambio'] ?? '');
                if ($tipoCambio === '') {
                    throw new CompraException("La compra {$nombre} está en {$compra->moneda}: indica el tipo de cambio.");
                }
                $monto = $this->convertir($montoCompra, $compra->moneda, $pago->moneda, BigDecimal::of($tipoCambio));
                if ($monto->isZero()) {
                    throw new CompraException("Con ese tipo de cambio, lo aplicado a la compra {$nombre} no llega a 0.01 {$pago->moneda}.");
                }
            }

            $limite = $saldos[$compra->id]['limite'];
            if ($montoCompra->isGreaterThan($limite)) {
                $queda = $limite->isNegative() ? BigDecimal::of('0.00') : $limite;
                throw new CompraException("A la compra {$nombre} solo se le pueden aplicar {$queda->toScale(2)}.", 422, [
                    'compra_id' => $compra->id, 'maximo' => (string) $queda->toScale(2),
                ]);
            }
            if ($monto->isGreaterThan($disponible)) {
                throw new CompraException("Al pago solo le quedan {$disponible->toScale(2)} sin aplicar.");
            }
            $disponible = $disponible->minus($monto);

            $pago->aplicaciones()->create([
                'compra_id' => $compra->id,
                'monto' => (string) $monto,
                'monto_compra' => (string) $montoCompra,
                'tipo_cambio' => $tipoCambio,
                'user_id' => $usuario->id,
            ]);
        }
    }

    // Origen del dinero: valida y deja en el pago lo que ese origen necesita, antes de guardarlo.
    private function registrarSalida(PagoProveedor $pago, Proveedor $proveedor, User $usuario): void
    {
        if ($pago->origen === PagoProveedor::ORIGEN_BANCO) {
            $this->origenDinero->exigirCuenta($pago);

            return;
        }

        // Caja: las reglas del egreso manual, con la aprobación (D2 de T-136).
        $estado = $this->origenDinero->moverEnCaja($pago, 'out', "Pago a proveedor: {$proveedor->razon_social}", $proveedor, $usuario);
        if ($estado === 'pending_approval') {
            $pago->estado = PagoProveedor::PENDIENTE;
        }
    }

    private function validar(array $entrada): array
    {
        $entrada = OrigenDineroService::normalizarOperacion($entrada);
        $dolares = ($entrada['moneda'] ?? null) === 'USD';
        $banco = ($entrada['origen'] ?? null) === PagoProveedor::ORIGEN_BANCO;
        $caja = ($entrada['origen'] ?? null) === PagoProveedor::ORIGEN_CAJA;

        $datos = $this->comprobar($entrada, [
            'proveedor_id' => ['required', 'integer', 'exists:proveedores,id'],
            'origen' => ['required', 'string', 'in:'.implode(',', PagoProveedor::ORIGENES)],
            'moneda' => ['required', 'string', 'in:'.implode(',', CuentaBancaria::MONEDAS)],
            // El egreso de Caja es decimal(10,2).
            'monto' => ['required', 'numeric', 'gt:0', 'max:'.($caja ? '99999999.99' : '999999999999.99'), 'decimal:0,2'],
            'tipo_cambio' => $dolares ? ['required', 'numeric', 'gt:0', 'max:999999', 'decimal:0,4'] : ['nullable'],
            'fecha' => $banco ? ['required', 'date_format:Y-m-d', 'before_or_equal:'.HoraPeru::hoyTexto()] : ['nullable'],
            'cuenta_bancaria_id' => $banco ? ['required', 'integer'] : ['nullable'],
            'numero_operacion' => $banco ? ['required', 'string', 'max:30'] : ['nullable'],
            'payment_method_id' => $caja ? ['required', 'integer'] : ['nullable'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);

        // Cada origen guarda solo lo suyo; en Caja la fecha es la de hoy en Perú.
        if (! $banco) {
            $datos['cuenta_bancaria_id'] = $datos['numero_operacion'] = null;
        }
        if (! $caja) {
            $datos['payment_method_id'] = null;
        } else {
            $datos['fecha'] = HoraPeru::hoyTexto();
        }
        if (! $dolares) {
            $datos['tipo_cambio'] = null;
        }
        $datos['observaciones'] = $datos['observaciones'] ?? null;
        $datos['aplicaciones'] = $this->validarAplicaciones($entrada, false);

        return $datos;
    }

    private function validarAplicaciones(array $entrada, bool $obligatorias): array
    {
        $datos = $this->comprobar($entrada, [
            'aplicaciones' => [$obligatorias ? 'required' : 'nullable', 'array', 'max:'.self::MAX_APLICACIONES],
            'aplicaciones.*.compra_id' => ['required', 'integer', 'distinct'],
            'aplicaciones.*.monto' => ['required', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
            // Solo se usa si la compra está en otra moneda que el pago. 6 decimales (no 4 como el del
            // pago): con 4, un pago en soles no siempre cancela exacto una factura en dólares.
            'aplicaciones.*.tipo_cambio' => ['nullable', 'numeric', 'gt:0', 'max:999999', 'decimal:0,6'],
        ]);

        return array_values($datos['aplicaciones'] ?? []);
    }

    private function comprobar(array $entrada, array $reglas): array
    {
        $validator = Validator::make($entrada, $reglas, [
            // Antes que los genéricos: gana el primero que coincide.
            'tipo_cambio.required' => 'En dólares se necesita el tipo de cambio.',
            'tipo_cambio.gt' => 'El tipo de cambio debe ser mayor a cero.',
            'tipo_cambio.decimal' => 'El tipo de cambio va con cuatro decimales como máximo.',
            'aplicaciones.*.tipo_cambio.gt' => 'El tipo de cambio debe ser mayor a cero.',
            'aplicaciones.*.tipo_cambio.decimal' => 'El tipo de cambio de la compra va con seis decimales como máximo.',
            'required' => 'Falta :attribute.',
            'integer' => ':Attribute no es válido.',
            'numeric' => ':Attribute debe ser un número.',
            'string' => ':Attribute no es válido.',
            'array' => 'Las aplicaciones no se pudieron leer.',
            'in' => ':Attribute no es válido.',
            'exists' => ':Attribute no existe.',
            'max' => ':Attribute pasa del máximo.',
            'date_format' => 'La fecha del pago no es válida.',
            'origen.in' => 'Ese origen de pago no existe.',
            'monto.gt' => 'El monto debe ser mayor a cero.',
            'fecha.before_or_equal' => 'La fecha del pago no puede ser futura.',
            'aplicaciones.required' => 'Indica a qué compra aplicar el pago.',
            'aplicaciones.*.compra_id.distinct' => 'Hay una compra repetida.',
            'aplicaciones.*.monto.gt' => 'Cada monto aplicado debe ser mayor a cero.',
            '*.decimal' => 'El monto va con dos decimales como máximo.',
            'aplicaciones.*.monto.decimal' => 'El monto va con dos decimales como máximo.',
        ], [
            'proveedor_id' => 'el proveedor',
            'origen' => 'el origen',
            'moneda' => 'la moneda',
            'monto' => 'el monto',
            'tipo_cambio' => 'el tipo de cambio',
            'aplicaciones.*.tipo_cambio' => 'el tipo de cambio',
            'fecha' => 'la fecha del pago',
            'cuenta_bancaria_id' => 'la cuenta bancaria',
            'numero_operacion' => 'el número de operación',
            'payment_method_id' => 'el método de pago',
            'observaciones' => 'la observación',
            'aplicaciones.*.compra_id' => 'la compra',
            'aplicaciones.*.monto' => 'el monto aplicado',
        ]);

        if ($validator->fails()) {
            throw new CompraException($validator->errors()->first(), 422, ['errors' => $validator->errors()->toArray()]);
        }

        return $validator->validated();
    }

    private function exigirMotivo(?string $motivo, string $falta): string
    {
        $motivo = trim((string) $motivo);
        if ($motivo === '') {
            throw new CompraException($falta);
        }
        if (mb_strlen($motivo) > 255) {
            throw new CompraException('El motivo no puede pasar de 255 caracteres.');
        }

        return $motivo;
    }

    private function sumar(iterable $montos): BigDecimal
    {
        return collect($montos)->reduce(fn (BigDecimal $s, $m) => $s->plus((string) $m), BigDecimal::of('0.00'));
    }
}
