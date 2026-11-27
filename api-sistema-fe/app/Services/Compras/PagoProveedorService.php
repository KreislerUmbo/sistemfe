<?php

namespace App\Services\Compras;

use App\Models\Cash\CashMovement;
use App\Models\Cash\CashSession;
use App\Models\Compras\AplicacionPagoProveedor;
use App\Models\Compras\Compra;
use App\Models\Compras\PagoProveedor;
use App\Models\CuentaBancaria;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\Cash\EgresoCajaService;
use App\Services\CashCorrectionService;
use App\Services\HoraPeru;
use Brick\Math\BigDecimal;
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

    public function __construct(
        private EgresoCajaService $egresoCaja,
        private CashCorrectionService $correccion,
    ) {}

    /**
     * El único cálculo de saldo, en lote:
     * saldo = total − notas de crédito vigentes que la modifican − aplicado de pagos vigentes.
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
            ->selectRaw('a.compra_id, p.estado, sum(a.monto) as monto')
            ->get()
            ->groupBy('compra_id');

        $cero = BigDecimal::of('0.00');
        $monto = fn ($valor) => $valor === null ? $cero : BigDecimal::of((string) $valor);
        $saldos = [];
        foreach (Compra::whereIn('id', $compraIds)->pluck('total', 'id') as $id => $total) {
            $porEstado = collect($aplicado->get($id, []))->pluck('monto', 'estado');
            $total = $monto($total);
            $acreditado = $monto($notas->get($id));
            $pagado = $monto($porEstado->get(PagoProveedor::VIGENTE));
            $porAprobar = $monto($porEstado->get(PagoProveedor::PENDIENTE));
            $saldo = $total->minus($acreditado)->minus($pagado);

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
            $vencimiento = ($compra->fecha_vencimiento ?? $compra->fecha_emision)->toDateString();
            $estado = match (true) {
                ! $fila['saldo']->isPositive() => 'pagado', // saldo negativo: a favor (T-140)
                $vencimiento < $hoy => 'vencido',
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

    public function sePaga(Compra $compra): bool
    {
        return ! $compra->estaAnulada() && $compra->tipo_comprobante_codigo !== Compra::TIPO_NOTA_CREDITO;
    }

    /** Monto aplicado y no desaplicado de un pago: lo demás queda «a cuenta». */
    public function aplicadoDe(PagoProveedor $pago): BigDecimal
    {
        return $this->sumar($pago->aplicaciones()->activas()->pluck('monto'));
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
            if ($existente = $this->operacionRegistrada($datos)) {
                throw $this->yaRegistrada($existente);
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
            $this->revertirSalida($actual, $usuario);
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

    // Con el pago ya bloqueado (o recién creado en esta transacción).
    private function crearAplicaciones(PagoProveedor $pago, array $aplicaciones, User $usuario): void
    {
        $compras = $this->bloquearCompras(array_column($aplicaciones, 'compra_id'));
        $saldos = $this->saldos($compras->keys()->all());
        $disponible = BigDecimal::of($pago->monto)->minus($this->aplicadoDe($pago));

        foreach ($aplicaciones as $aplicacion) {
            $compra = $compras->get((int) $aplicacion['compra_id']);
            if (! $compra) {
                throw new CompraException('Una de las compras no existe.');
            }
            $nombre = "{$compra->serie}-{$compra->numero}";
            $monto = BigDecimal::of((string) $aplicacion['monto'])->toScale(2);

            if ((int) $compra->proveedor_id !== (int) $pago->proveedor_id) {
                throw new CompraException("La compra {$nombre} es de otro proveedor.");
            }
            if ($compra->estaAnulada()) {
                throw new CompraException("La compra {$nombre} está anulada.");
            }
            if ($compra->tipo_comprobante_codigo === Compra::TIPO_NOTA_CREDITO) {
                throw new CompraException("{$nombre} es una nota de crédito: no se paga.");
            }
            if ($compra->moneda !== $pago->moneda) {
                throw new CompraException("La compra {$nombre} está en {$compra->moneda} y el pago en {$pago->moneda}: todavía no se puede pagar en otra moneda.");
            }
            $limite = $saldos[$compra->id]['limite'];
            if ($monto->isGreaterThan($limite)) {
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
                'user_id' => $usuario->id,
            ]);
        }
    }

    // Origen del dinero: valida y deja en el pago lo que ese origen necesita, antes de guardarlo.
    private function registrarSalida(PagoProveedor $pago, Proveedor $proveedor, User $usuario): void
    {
        match ($pago->origen) {
            PagoProveedor::ORIGEN_BANCO => $this->exigirCuenta($pago),
            PagoProveedor::ORIGEN_CAJA => $this->egresarDeCaja($pago, $proveedor, $usuario),
        };
    }

    // El egreso sale de la sesión abierta de quien paga, con las reglas del egreso manual (D2).
    private function egresarDeCaja(PagoProveedor $pago, Proveedor $proveedor, User $usuario): void
    {
        // Sesión bloqueada: dos salidas a la vez no validan contra el mismo efectivo.
        $sesion = CashSession::whereKey($this->egresoCaja->sesionAbierta($usuario)->id)->lockForUpdate()->firstOrFail();
        $metodo = $this->egresoCaja->metodoPago((int) $pago->payment_method_id);
        $estado = $this->egresoCaja->estadoInicial($sesion, $metodo, 'out', (float) $pago->monto, $pago->moneda);

        // El movimiento apunta al pago y el pago exige su movimiento: se reserva el id antes, para
        // no tener que modificar después un movimiento de dinero.
        $pago->id = (int) DB::selectOne("select nextval(pg_get_serial_sequence('pagos_proveedor', 'id')) as id")->id;

        $pago->cash_movement_id = CashMovement::create([
            'cash_session_id' => $sesion->id,
            'type' => PagoProveedor::TIPO_MOVIMIENTO,
            'payment_method_id' => $metodo->id,
            'direction' => 'out',
            'amount' => $pago->monto,
            'currency' => $pago->moneda,
            'reference_type' => PagoProveedor::TIPO_MOVIMIENTO,
            'reference_id' => $pago->id,
            'description' => "Pago a proveedor: {$proveedor->razon_social}",
            'counterparty_type' => 'proveedor',
            'counterparty_id' => $proveedor->id,
            'counterparty_name' => $proveedor->razon_social,
            'counterparty_document' => $proveedor->numero_documento,
            'status' => $estado,
            'created_by' => $usuario->id,
        ])->id;

        if ($estado === 'pending_approval') {
            $pago->estado = PagoProveedor::PENDIENTE;
        }
    }

    // Solo Caja movió dinero que haya que devolver: Banco solo se marca.
    private function revertirSalida(PagoProveedor $pago, User $usuario): void
    {
        if ($pago->origen !== PagoProveedor::ORIGEN_CAJA) {
            return;
        }

        $movimiento = CashMovement::with('cashSession')->lockForUpdate()->findOrFail($pago->cash_movement_id);
        if ($movimiento->status === 'pending_approval') {
            $movimiento->update(['status' => 'rejected']);

            return;
        }
        if ($movimiento->status === 'confirmed') {
            $this->correccion->revertirMovimiento($movimiento, $usuario);
        }
    }

    private function exigirCuenta(PagoProveedor $pago): void
    {
        $cuenta = CuentaBancaria::whereKey($pago->cuenta_bancaria_id)->lockForUpdate()->first();
        if (! $cuenta || ! $cuenta->activo) {
            throw new CompraException('La cuenta bancaria no existe o está inactiva.');
        }
        if ($cuenta->moneda === null) {
            throw new CompraException("Completa la moneda de la cuenta {$cuenta->banco} {$cuenta->numero_cuenta} antes de pagar con ella.", 422, [
                'cuenta_sin_moneda' => $cuenta->id,
            ]);
        }
        if ($cuenta->moneda !== $pago->moneda) {
            throw new CompraException("La cuenta es en {$cuenta->moneda} y el pago en {$pago->moneda}.");
        }
        if ($existente = $this->operacionRegistrada($pago->only(['cuenta_bancaria_id', 'numero_operacion']))) {
            throw $this->yaRegistrada($existente);
        }
    }

    private function operacionRegistrada(array $datos): ?PagoProveedor
    {
        if (empty($datos['cuenta_bancaria_id']) || empty($datos['numero_operacion'])) {
            return null;
        }

        return PagoProveedor::with('proveedor')
            ->where('cuenta_bancaria_id', $datos['cuenta_bancaria_id'])
            ->where('numero_operacion', $datos['numero_operacion'])
            ->where('estado', '<>', PagoProveedor::ANULADO)
            ->first();
    }

    private function yaRegistrada(PagoProveedor $existente): CompraException
    {
        return new CompraException('Ese número de operación ya está registrado en la cuenta.', 409, ['existente' => [
            'id' => $existente->id,
            'fecha' => $existente->fecha->toDateString(),
            'monto' => $existente->monto,
            'moneda' => $existente->moneda,
            'proveedor' => $existente->proveedor?->razon_social,
        ]]);
    }

    private function validar(array $entrada): array
    {
        // Mayúsculas y sin espacios, para que «op 123» y «OP123» sean la misma operación.
        if (isset($entrada['numero_operacion'])) {
            $entrada['numero_operacion'] = mb_strtoupper(preg_replace('/\s+/u', '', (string) $entrada['numero_operacion'])) ?: null;
        }
        $banco = ($entrada['origen'] ?? null) === PagoProveedor::ORIGEN_BANCO;
        $caja = ($entrada['origen'] ?? null) === PagoProveedor::ORIGEN_CAJA;

        $datos = $this->comprobar($entrada, [
            'proveedor_id' => ['required', 'integer', 'exists:proveedores,id'],
            'origen' => ['required', 'string', 'in:'.implode(',', PagoProveedor::ORIGENES)],
            'moneda' => ['required', 'string', 'in:'.implode(',', CuentaBancaria::MONEDAS)],
            // El egreso de Caja es decimal(10,2).
            'monto' => ['required', 'numeric', 'gt:0', 'max:'.($caja ? '99999999.99' : '999999999999.99'), 'decimal:0,2'],
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
        ]);

        return array_values($datos['aplicaciones'] ?? []);
    }

    private function comprobar(array $entrada, array $reglas): array
    {
        $validator = Validator::make($entrada, $reglas, [
            'origen.in' => 'Ese origen de pago no existe.',
            'monto.gt' => 'El monto debe ser mayor a cero.',
            'fecha.before_or_equal' => 'La fecha del pago no puede ser futura.',
            'aplicaciones.required' => 'Indica a qué compra aplicar el pago.',
            'aplicaciones.*.compra_id.distinct' => 'Hay una compra repetida.',
            'aplicaciones.*.monto.gt' => 'Cada monto aplicado debe ser mayor a cero.',
            '*.decimal' => 'El monto va con dos decimales como máximo.',
            'aplicaciones.*.monto.decimal' => 'El monto va con dos decimales como máximo.',
        ], [
            'proveedor_id' => 'proveedor',
            'cuenta_bancaria_id' => 'cuenta bancaria',
            'numero_operacion' => 'número de operación',
            'payment_method_id' => 'método de pago',
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
