<?php

namespace App\Services\Compras;

use App\Models\Compras\Compra;
use App\Models\Compras\PagoProveedor;
use App\Models\Compras\ReembolsoProveedor;
use App\Models\CuentaBancaria;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\HoraPeru;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * T-140 — reembolsos del proveedor: devuelve el saldo a favor de una compra (D1, D6) o lo «a cuenta»
 * de un pago (D2). Cada reembolso bloquea solo su destino (una compra o un pago), así que no forma
 * ciclos con el orden de PagoProveedorService. Todo rechazo sale como CompraException.
 */
class ReembolsoProveedorService
{
    public function __construct(
        private PagoProveedorService $pagos,
        private OrigenDineroService $origenDinero,
    ) {}

    /**
     * Lo reembolsado (vigente) por compra. Estático: PagoProveedorService lo usa en saldos(), y
     * este servicio usa a su vez a aquel.
     *
     * @param  array<int, int>  $compraIds
     * @return array<int, BigDecimal>
     */
    public static function totalesPorCompra(array $compraIds): array
    {
        return self::totales('compra_id', $compraIds);
    }

    /**
     * Lo reembolsado (vigente) por pago.
     *
     * @param  array<int, int>  $pagoIds
     * @return array<int, BigDecimal>
     */
    public static function totalesPorPago(array $pagoIds): array
    {
        return self::totales('pago_proveedor_id', $pagoIds);
    }

    private static function totales(string $columna, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return ReembolsoProveedor::vigentes()
            ->whereIn($columna, $ids)
            ->groupBy($columna)
            ->selectRaw("{$columna} as destino, sum(monto) as total")
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($fila) => [(int) $fila->destino => BigDecimal::of((string) $fila->total)])
            ->all();
    }

    public function registrar(array $entrada, User $usuario): ReembolsoProveedor
    {
        $datos = $this->validar($entrada);

        try {
            return DB::transaction(function () use ($datos, $usuario) {
                // El destino se bloquea antes de calcular lo que se puede reembolsar.
                $datos['compra_id'] !== null
                    ? $this->exigirSaldoAFavor($datos)
                    : $this->exigirACuenta($datos);

                $proveedor = Proveedor::findOrFail($datos['proveedor_id']);
                $reembolso = new ReembolsoProveedor($datos + ['user_id' => $usuario->id, 'estado' => ReembolsoProveedor::VIGENTE]);
                if ($reembolso->origen === PagoProveedor::ORIGEN_BANCO) {
                    $this->origenDinero->exigirCuenta($reembolso);
                } else {
                    // Un ingreso no pasa por aprobación: nace confirmado.
                    $this->origenDinero->moverEnCaja(
                        $reembolso, 'in', "Reembolso de proveedor: {$proveedor->razon_social}", $proveedor, $usuario
                    );
                }
                $reembolso->save();

                return $reembolso;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Carrera: dos registros a la vez con la misma operación.
            if ($existente = $this->origenDinero->operacionRegistrada(ReembolsoProveedor::class, $datos)) {
                throw $this->origenDinero->yaRegistrada($existente);
            }

            throw $e;
        }
    }

    /** Anular: Caja se corrige; Banco solo se marca. Nada se borra. */
    public function anular(ReembolsoProveedor $reembolso, ?string $motivo, User $usuario): ReembolsoProveedor
    {
        $motivo = trim((string) $motivo);
        if ($motivo === '') {
            throw new CompraException('Indica el motivo de la anulación.');
        }
        if (mb_strlen($motivo) > 255) {
            throw new CompraException('El motivo no puede pasar de 255 caracteres.');
        }

        return DB::transaction(function () use ($reembolso, $motivo, $usuario) {
            $reembolso->compra_id
                ? Compra::whereKey($reembolso->compra_id)->lockForUpdate()->firstOrFail()
                : PagoProveedor::whereKey($reembolso->pago_proveedor_id)->lockForUpdate()->firstOrFail();

            $actual = ReembolsoProveedor::whereKey($reembolso->id)->lockForUpdate()->firstOrFail();
            if ($actual->estaAnulado()) {
                throw new CompraException('El reembolso ya está anulado.');
            }
            if ($actual->origen === PagoProveedor::ORIGEN_CAJA) {
                $this->origenDinero->revertirEnCaja($actual->cash_movement_id, $usuario);
            }
            $actual->update([
                'estado' => ReembolsoProveedor::ANULADO,
                'anulado_at' => now(),
                'anulado_por' => $usuario->id,
                'motivo_anulacion' => $motivo,
            ]);

            return $actual;
        });
    }

    private function exigirSaldoAFavor(array $datos): void
    {
        $compra = Compra::whereKey($datos['compra_id'])->lockForUpdate()->first();
        if (! $compra) {
            throw new CompraException('La compra no existe.');
        }
        $nombre = "{$compra->serie}-{$compra->numero}";
        if ((int) $compra->proveedor_id !== (int) $datos['proveedor_id']) {
            throw new CompraException("La compra {$nombre} es de otro proveedor.");
        }
        if ($compra->estaAnulada()) {
            throw new CompraException("La compra {$nombre} está anulada.");
        }
        if ($compra->tipo_comprobante_codigo === Compra::TIPO_NOTA_CREDITO) {
            throw new CompraException("{$nombre} es una nota de crédito: el reembolso se registra en la compra que modifica.");
        }
        if ($compra->moneda !== $datos['moneda']) {
            throw new CompraException("La compra {$nombre} está en {$compra->moneda} y el reembolso en {$datos['moneda']}.");
        }

        // El saldo y no el límite: lo que espera aprobación todavía no se pagó.
        $saldo = $this->pagos->saldos([$compra->id])[$compra->id]['saldo'];
        if (! $saldo->isNegative()) {
            throw new CompraException("La compra {$nombre} no tiene saldo a favor.");
        }
        $this->exigirTope((string) $datos['monto'], $saldo->negated(), $compra->moneda);
    }

    private function exigirACuenta(array $datos): void
    {
        $pago = PagoProveedor::whereKey($datos['pago_proveedor_id'])->lockForUpdate()->first();
        if (! $pago) {
            throw new CompraException('El pago no existe.');
        }
        if ((int) $pago->proveedor_id !== (int) $datos['proveedor_id']) {
            throw new CompraException('El pago es de otro proveedor.');
        }
        if ($pago->estado !== PagoProveedor::VIGENTE) {
            throw new CompraException($pago->estaAnulado()
                ? 'El pago está anulado.'
                : 'El pago espera aprobación en Caja: todavía no salió el dinero.');
        }
        if ($pago->moneda !== $datos['moneda']) {
            throw new CompraException("El pago está en {$pago->moneda} y el reembolso en {$datos['moneda']}.");
        }
        $this->exigirTope((string) $datos['monto'], $this->pagos->disponibles([$pago->id])[$pago->id], $pago->moneda);
    }

    private function exigirTope(string $monto, BigDecimal $maximo, string $moneda): void
    {
        if (BigDecimal::of($monto)->isGreaterThan($maximo)) {
            $texto = (string) $maximo->toScale(2);

            throw new CompraException("Solo se pueden reembolsar {$moneda} {$texto}.", 422, ['maximo' => $texto]);
        }
    }

    private function validar(array $entrada): array
    {
        $entrada = OrigenDineroService::normalizarOperacion($entrada);
        $banco = ($entrada['origen'] ?? null) === PagoProveedor::ORIGEN_BANCO;
        $caja = ($entrada['origen'] ?? null) === PagoProveedor::ORIGEN_CAJA;
        $dolares = ($entrada['moneda'] ?? null) === 'USD';

        $validator = Validator::make($entrada, [
            'proveedor_id' => ['required', 'integer', 'exists:proveedores,id'],
            'compra_id' => ['nullable', 'integer'],
            'pago_proveedor_id' => ['nullable', 'integer'],
            'origen' => ['required', 'string', 'in:'.implode(',', PagoProveedor::ORIGENES)],
            'moneda' => ['required', 'string', 'in:'.implode(',', CuentaBancaria::MONEDAS)],
            // El movimiento de Caja es decimal(10,2).
            'monto' => ['required', 'numeric', 'gt:0', 'max:'.($caja ? '99999999.99' : '999999999999.99'), 'decimal:0,2'],
            'tipo_cambio' => $dolares ? ['required', 'numeric', 'gt:0', 'max:999999', 'decimal:0,4'] : ['nullable'],
            'fecha' => $banco ? ['required', 'date_format:Y-m-d', 'before_or_equal:'.HoraPeru::hoyTexto()] : ['nullable'],
            'cuenta_bancaria_id' => $banco ? ['required', 'integer'] : ['nullable'],
            'numero_operacion' => $banco ? ['required', 'string', 'max:30'] : ['nullable'],
            'payment_method_id' => $caja ? ['required', 'integer'] : ['nullable'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ], [
            'tipo_cambio.required' => 'En dólares se necesita el tipo de cambio.',
            'tipo_cambio.gt' => 'El tipo de cambio debe ser mayor a cero.',
            'tipo_cambio.decimal' => 'El tipo de cambio va con cuatro decimales como máximo.',
            'required' => 'Falta :attribute.',
            'integer' => ':Attribute no es válido.',
            'numeric' => ':Attribute debe ser un número.',
            'string' => ':Attribute no es válido.',
            'in' => ':Attribute no es válido.',
            'exists' => ':Attribute no existe.',
            'max' => ':Attribute pasa del máximo.',
            'date_format' => 'La fecha del reembolso no es válida.',
            'origen.in' => 'Ese origen no existe.',
            'monto.gt' => 'El monto debe ser mayor a cero.',
            'monto.decimal' => 'El monto va con dos decimales como máximo.',
            'fecha.before_or_equal' => 'La fecha del reembolso no puede ser futura.',
        ], [
            'proveedor_id' => 'el proveedor',
            'compra_id' => 'la compra',
            'pago_proveedor_id' => 'el pago',
            'origen' => 'el origen',
            'moneda' => 'la moneda',
            'monto' => 'el monto',
            'tipo_cambio' => 'el tipo de cambio',
            'fecha' => 'la fecha del reembolso',
            'cuenta_bancaria_id' => 'la cuenta bancaria',
            'numero_operacion' => 'el número de operación',
            'payment_method_id' => 'el método de pago',
            'observaciones' => 'la observación',
        ]);

        if ($validator->fails()) {
            throw new CompraException($validator->errors()->first(), 422, ['errors' => $validator->errors()->toArray()]);
        }
        $datos = $validator->validated();

        $datos['compra_id'] = $datos['compra_id'] ?? null;
        $datos['pago_proveedor_id'] = $datos['pago_proveedor_id'] ?? null;
        if (($datos['compra_id'] === null) === ($datos['pago_proveedor_id'] === null)) {
            throw new CompraException('Indica qué se reembolsa: la compra o el pago, solo uno.');
        }

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

        return $datos;
    }
}
