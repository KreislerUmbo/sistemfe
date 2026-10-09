<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creditos;

use App\Enums\Creditos\RequisitoFicha;
use App\Enums\Creditos\TipoArchivoCliente;
use App\Http\Requests\Creditos\MotivoRequest;
use App\Http\Requests\Creditos\ArchivoClienteRequest;
use App\Http\Requests\Creditos\AsignarCarteraRequest;
use App\Http\Requests\Creditos\DevolverSaldoRequest;
use App\Http\Requests\Creditos\FichaCreditoRequest;
use App\Http\Requests\Creditos\LimitesClienteRequest;
use App\Http\Requests\Creditos\TraspasarCarteraRequest;
use App\Models\Client\Client;
use App\Models\Creditos\CreditoClienteArchivo;
use App\Models\Creditos\CreditoClienteFicha;
use App\Models\Creditos\CreditoClienteLimite;
use App\Models\User;
use App\Services\Creditos\AlcanceCartera;
use App\Services\Creditos\AuditoriaCredito;
use App\Services\Creditos\ClienteCreditoService;
use App\Services\Creditos\Dinero;
use App\Services\Creditos\SaldoAFavorService;
use App\Services\Creditos\LimitesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Resumen de crédito, ficha de cobro, archivos, cartera y límites del cliente (04c). */
class ClienteCreditoController extends ControllerCreditos
{
    public function __construct(
        private readonly ClienteCreditoService $clientes,
        private readonly LimitesService $limites,
        private readonly AlcanceCartera $alcance,
        private readonly AuditoriaCredito $auditoria,
        private readonly SaldoAFavorService $saldos,
    ) {
    }

    public function resumen(int $cliente): JsonResponse
    {
        return response()->json($this->limites->resumenCliente($this->clienteVisible($cliente)));
    }

    public function ficha(int $cliente): JsonResponse
    {
        $modelo = $this->clienteVisible($cliente);
        $limite = CreditoClienteLimite::where('cliente_id', $modelo->id)->first();

        return response()->json([
            'ficha' => CreditoClienteFicha::where('cliente_id', $modelo->id)->first(),
            'archivos' => CreditoClienteArchivo::where('cliente_id', $modelo->id)->orderByDesc('id')
                ->get(['id', 'tipo', 'created_at']),
            'cartera' => $this->clientes->cartera($modelo),
            'historial_cartera' => $this->clientes->historialCartera($modelo),
            'limites' => $limite?->only(['max_creditos_activos', 'deuda_maxima', 'bloqueado', 'motivo_bloqueo']),
            'ficha_faltante' => $this->faltante($modelo->id),
            'saldo_a_favor' => Dinero::aSoles($this->saldos->saldo($modelo->id)),
        ]);
    }

    /** Saldo a favor y sus movimientos (04c.1). */
    public function saldoAFavor(int $cliente): JsonResponse
    {
        $modelo = $this->clienteVisible($cliente);

        return response()->json([
            'saldo' => Dinero::aSoles($this->saldos->saldo($modelo->id)),
            'movimientos' => $this->saldos->movimientos($modelo->id),
        ]);
    }

    /** Entrega el saldo a favor al cliente: salida de la caja abierta de quien devuelve (04c.1). */
    public function devolverSaldo(DevolverSaldoRequest $request, int $cliente): JsonResponse
    {
        $modelo = $this->clienteVisible($cliente);

        return $this->idempotente($request, 'saldo_favor.devolver', function () use ($request, $modelo): array {
            $this->saldos->devolver($modelo, $request->centavos(), (int) $request->input('payment_method_id'), trim((string) $request->input('motivo')), $this->usuario());

            return [[
                'saldo' => Dinero::aSoles($this->saldos->saldo($modelo->id)),
                'movimientos' => $this->saldos->movimientos($modelo->id),
            ], null];
        }, ['cliente' => $modelo->id]);
    }

    /** Anula una devolución mal registrada: el dinero vuelve a la caja y el saldo al cliente. */
    public function anularDevolucion(MotivoRequest $request, int $cliente, int $movimiento): JsonResponse
    {
        $modelo = $this->clienteVisible($cliente);

        return $this->idempotente($request, 'saldo_favor.anular_devolucion', function () use ($request, $modelo, $movimiento): array {
            $this->saldos->anularDevolucion($modelo, $movimiento, trim((string) $request->input('motivo')), $this->usuario());

            return [[
                'saldo' => Dinero::aSoles($this->saldos->saldo($modelo->id)),
                'movimientos' => $this->saldos->movimientos($modelo->id),
            ], null];
        }, ['cliente' => $modelo->id, 'movimiento' => $movimiento]);
    }

    public function guardarFicha(FichaCreditoRequest $request, int $cliente): JsonResponse
    {
        $modelo = $this->clienteVisible($cliente);

        return response()->json([
            'ficha' => $this->clientes->guardarFicha($modelo, $request->validated(), $this->usuario()),
            'ficha_faltante' => $this->faltante($modelo->id),
        ]);
    }

    public function subirArchivo(ArchivoClienteRequest $request, int $cliente): JsonResponse
    {
        $modelo = $this->clienteVisible($cliente);
        $archivo = $this->clientes->subirArchivo(
            $modelo, TipoArchivoCliente::from($request->input('tipo')), $request->file('archivo'), $this->usuario(),
        );

        return response()->json([
            'archivo' => $archivo->only(['id', 'tipo', 'created_at']),
            'ficha_faltante' => $this->faltante($modelo->id),
        ], 201);
    }

    /** El DNI y la foto son datos personales (Ley 29733): cada vista queda auditada. */
    public function verArchivo(int $cliente, int $archivo): StreamedResponse
    {
        $modelo = $this->clienteVisible($cliente);
        $registro = CreditoClienteArchivo::where('cliente_id', $modelo->id)->findOrFail($archivo);
        $this->auditoria->registrar('cliente.ver_documento', $modelo, null, null, ['archivo_id' => $registro->id, 'tipo' => $registro->tipo->value], null, $this->usuario());

        return Storage::disk(ClienteCreditoService::DISCO)->response($registro->ruta_archivo);
    }

    /** Usuarios con clientes en su cartera, incluidos inactivos y eliminados (04c.1). */
    public function titularesCartera(): JsonResponse
    {
        return response()->json(['data' => $this->clientes->titularesCartera()]);
    }

    /**
     * Traspasa toda la cartera de un usuario a otro (04c.1). Mueve clientes que no son del que
     * opera: exige ver toda la cartera además de poder asignar.
     */
    public function traspasarCartera(TraspasarCarteraRequest $request): JsonResponse
    {
        if (! $this->usuario()->can(AlcanceCartera::PERMISO_VER_TODOS)) {
            throw new HttpException(403, 'Traspasar una cartera requiere ver todos los créditos.');
        }

        return $this->idempotente($request, 'cartera.traspasar', function () use ($request): array {
            $desde = (int) $request->input('desde_usuario_id');
            $hacia = User::findOrFail((int) $request->input('hacia_usuario_id'));
            $funciones = (string) ($request->input('funciones') ?? 'ambas');
            $conCreditos = $request->boolean('con_creditos');
            if ($conCreditos && $funciones === 'cobrador') {
                throw new HttpException(422, 'Los créditos se pasan junto con la función de asesor.');
            }

            // Clientes y créditos en una sola operación: o pasa todo o nada.
            [$clientes, $creditos] = DB::transaction(function () use ($desde, $hacia, $funciones, $conCreditos, $request): array {
                // Quien solo colocó créditos (sin clientes asignados) igual puede traspasarlos.
                $clientes = ! $conCreditos || $this->clientes->clientesEnCartera($desde) > 0
                    ? $this->clientes->traspasarCartera($desde, $hacia, $funciones, $this->usuario())
                    : 0;
                if (! $conCreditos) {
                    return [$clientes, 0];
                }
                if ($desde === $hacia->id) {
                    throw new HttpException(422, 'Elige un usuario distinto al que tiene hoy la cartera.');
                }
                $this->clientes->validarAsesor($hacia);
                $creditos = $this->clientes->cambiarAsesorDeCreditos($desde, $hacia, trim((string) $request->input('motivo_creditos')), $this->usuario());
                if ($clientes === 0 && $creditos === 0) {
                    throw new HttpException(422, 'Ese usuario no tiene clientes ni créditos activos para traspasar.');
                }

                return [$clientes, $creditos];
            });

            return [['clientes' => $clientes, 'creditos' => $creditos, 'titulares' => $this->clientes->titularesCartera()], null];
        });
    }

    /** Selectores de asesor y cobrador. */
    public function usuariosCartera(): JsonResponse
    {
        return response()->json($this->clientes->usuariosCartera());
    }

    public function asignarCartera(AsignarCarteraRequest $request, int $cliente): JsonResponse
    {
        $usuario = static fn (?int $id): ?User => $id === null ? null : User::findOrFail($id);
        $cartera = $this->clientes->asignarCartera(
            $this->clienteVisible($cliente),
            $usuario($request->filled('asesor_id') ? (int) $request->input('asesor_id') : null),
            $usuario($request->filled('cobrador_id') ? (int) $request->input('cobrador_id') : null),
            $this->usuario(),
        );

        return response()->json($cartera);
    }

    public function guardarLimites(LimitesClienteRequest $request, int $cliente): JsonResponse
    {
        $limite = $this->clientes->guardarLimites($this->clienteVisible($cliente), $request->datos(), $this->usuario());

        return response()->json(['limites' => $limite->only(['max_creditos_activos', 'deuda_maxima', 'bloqueado', 'motivo_bloqueo'])]);
    }

    /** @return list<array{requisito: string, etiqueta: string}> */
    private function faltante(int $clienteId): array
    {
        return array_map(
            static fn (RequisitoFicha $r): array => ['requisito' => $r->value, 'etiqueta' => $r->etiqueta()],
            $this->clientes->fichaFaltante($clienteId),
        );
    }

    /** La ficha es dato personal (Ley 29733): fuera de su cartera, el cliente "no existe". */
    private function clienteVisible(int $cliente): Client
    {
        $modelo = Client::findOrFail($cliente);
        if (! $this->alcance->puedeVerCliente($modelo->id, $this->usuario())) {
            throw new HttpException(404, 'Cliente no encontrado.');
        }

        return $modelo;
    }
}
