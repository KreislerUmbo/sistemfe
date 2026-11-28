<?php

namespace App\Services\Compras;

use App\Models\Cash\CashMovement;
use App\Models\Cash\CashSession;
use App\Models\Compras\PagoProveedor;
use App\Models\Compras\ReembolsoProveedor;
use App\Models\CuentaBancaria;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\Cash\EgresoCajaService;
use App\Services\CashCorrectionService;
use Illuminate\Support\Facades\DB;

/**
 * T-140 — de dónde sale (pago) o a dónde entra (reembolso) el dinero de un proveedor: Caja o Banco.
 * Extraído de PagoProveedorService. El registro es un PagoProveedor o un ReembolsoProveedor: los dos
 * tienen origen, moneda, monto, método, cuenta, número de operación y movimiento. Solo dentro de una
 * transacción.
 */
class OrigenDineroService
{
    public function __construct(
        private EgresoCajaService $egresoCaja,
        private CashCorrectionService $correccion,
    ) {}

    /** Mayúsculas y sin espacios, para que «op 123» y «OP123» sean la misma operación. «0» vale. */
    public static function normalizarOperacion(array $entrada): array
    {
        // Solo si es texto: otra cosa la rechaza la validación (string), sin romperse aquí.
        if (isset($entrada['numero_operacion']) && is_string($entrada['numero_operacion'])) {
            $normalizado = mb_strtoupper(preg_replace('/\s+/u', '', $entrada['numero_operacion']));
            $entrada['numero_operacion'] = $normalizado === '' ? null : $normalizado;
        }

        return $entrada;
    }

    public function exigirCuenta(PagoProveedor|ReembolsoProveedor $registro): void
    {
        $esPago = $registro instanceof PagoProveedor;
        $cuenta = CuentaBancaria::whereKey($registro->cuenta_bancaria_id)->lockForUpdate()->first();
        if (! $cuenta || ! $cuenta->activo) {
            throw new CompraException('La cuenta bancaria no existe o está inactiva.');
        }
        if ($cuenta->moneda === null) {
            $para = $esPago ? 'pagar con ella' : 'registrar el reembolso';
            throw new CompraException("Completa la moneda de la cuenta {$cuenta->banco} {$cuenta->numero_cuenta} antes de {$para}.", 422, [
                'cuenta_sin_moneda' => $cuenta->id,
            ]);
        }
        if ($cuenta->moneda !== $registro->moneda) {
            $nombre = $esPago ? 'el pago' : 'el reembolso';
            throw new CompraException("La cuenta es en {$cuenta->moneda} y {$nombre} en {$registro->moneda}.");
        }
        if ($existente = $this->operacionRegistrada($registro::class, $registro->only(['cuenta_bancaria_id', 'numero_operacion']))) {
            throw $this->yaRegistrada($existente);
        }
    }

    /**
     * La operación vigente (o por aprobar) con esa cuenta y número, en la tabla de esa clase.
     *
     * @param  class-string<PagoProveedor|ReembolsoProveedor>  $clase
     */
    public function operacionRegistrada(string $clase, array $datos): PagoProveedor|ReembolsoProveedor|null
    {
        if (empty($datos['cuenta_bancaria_id']) || empty($datos['numero_operacion'])) {
            return null;
        }

        return $clase::with('proveedor')
            ->where('cuenta_bancaria_id', $datos['cuenta_bancaria_id'])
            ->where('numero_operacion', $datos['numero_operacion'])
            ->where('estado', '<>', $clase::ANULADO)
            ->first();
    }

    public function yaRegistrada(PagoProveedor|ReembolsoProveedor $existente): CompraException
    {
        return new CompraException('Ese número de operación ya está registrado en la cuenta.', 409, ['existente' => [
            'id' => $existente->id,
            'fecha' => $existente->fecha->toDateString(),
            'monto' => $existente->monto,
            'moneda' => $existente->moneda,
            'proveedor' => $existente->proveedor?->razon_social,
        ]]);
    }

    /**
     * El movimiento sale de la sesión abierta de quien registra (`out`) o entra en ella (`in`), con
     * las reglas del movimiento manual. Reserva el id del registro, le pone `cash_movement_id` (sin
     * guardarlo) y devuelve el estado con el que nació el movimiento.
     */
    public function moverEnCaja(
        PagoProveedor|ReembolsoProveedor $registro,
        string $direccion,
        string $descripcion,
        Proveedor $proveedor,
        User $usuario,
    ): string {
        $salida = $direccion === 'out';
        // Sesión bloqueada: dos salidas a la vez no validan contra el mismo efectivo.
        $sesion = CashSession::whereKey($this->egresoCaja->sesionAbierta($usuario)->id)->lockForUpdate()->firstOrFail();
        // Pudo cerrarse entre la lectura y el bloqueo: nada entra a una sesión ya cuadrada.
        if ($sesion->status !== 'open') {
            $para = $salida ? 'pagar desde Caja' : 'registrar el ingreso en Caja';
            throw new CompraException("Tu sesión de caja se acaba de cerrar: abre una nueva para {$para}.");
        }
        $metodo = $this->egresoCaja->metodoPago((int) $registro->payment_method_id);
        $estado = $this->egresoCaja->estadoInicial(
            $sesion, $metodo, $direccion, (float) $registro->monto, $registro->moneda, $registro->tipo_cambio
        );

        // El movimiento apunta al registro y el registro exige su movimiento: se reserva el id antes,
        // para no tener que modificar después un movimiento de dinero.
        $registro->id = (int) DB::selectOne(
            "select nextval(pg_get_serial_sequence('{$registro->getTable()}', 'id')) as id"
        )->id;

        $registro->cash_movement_id = CashMovement::create([
            'cash_session_id' => $sesion->id,
            'type' => $registro::TIPO_MOVIMIENTO,
            'payment_method_id' => $metodo->id,
            'direction' => $direccion,
            'amount' => $registro->monto,
            'currency' => $registro->moneda,
            'reference_type' => $registro::TIPO_MOVIMIENTO,
            'reference_id' => $registro->id,
            'description' => $descripcion,
            'counterparty_type' => 'proveedor',
            'counterparty_id' => $proveedor->id,
            'counterparty_name' => $proveedor->razon_social,
            'counterparty_document' => $proveedor->numero_documento,
            'status' => $estado,
            'created_by' => $usuario->id,
        ])->id;

        return $estado;
    }

    /** Deshace el movimiento de Caja: el que espera aprobación se rechaza; el confirmado se corrige. */
    public function revertirEnCaja(int $movimientoId, User $usuario): void
    {
        $movimiento = CashMovement::with(['cashSession', 'paymentMethod'])->lockForUpdate()->findOrFail($movimientoId);
        if ($movimiento->status === 'pending_approval') {
            $movimiento->update(['status' => 'rejected']);

            return;
        }
        if ($movimiento->status !== 'confirmed') {
            return;
        }
        // Revertir un ingreso saca dinero: la sesión de quien anula no puede quedar en negativo.
        if ($movimiento->direction === 'in') {
            $sesion = CashSession::where('opened_by', $usuario->id)->where('status', 'open')->lockForUpdate()->first();
            if ($sesion) {
                $this->egresoCaja->validarNoNegativo($sesion, [[
                    'metodo' => $movimiento->paymentMethod,
                    'direction' => 'out',
                    'amount' => (float) $movimiento->amount,
                    'currency' => $movimiento->currency,
                ]]);
            }
        }
        $this->correccion->revertirMovimiento($movimiento, $usuario);
    }
}
