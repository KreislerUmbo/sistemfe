<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creditos;

use App\Enums\Creditos\TipoArchivoCliente;
use App\Http\Requests\Creditos\ArchivoClienteRequest;
use App\Http\Requests\Creditos\AsignarCobradorRequest;
use App\Http\Requests\Creditos\FichaCreditoRequest;
use App\Models\Client\Client;
use App\Models\Creditos\CarteraAsignacion;
use App\Models\Creditos\CreditoClienteArchivo;
use App\Models\Creditos\CreditoClienteFicha;
use App\Models\User;
use App\Services\Creditos\AlcanceCartera;
use App\Services\Creditos\ClienteCreditoService;
use App\Services\Creditos\LimitesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Resumen de crédito, ficha de cobro, archivos y cobrador asignado del cliente. */
class ClienteCreditoController extends ControllerCreditos
{
    public function __construct(
        private readonly ClienteCreditoService $clientes,
        private readonly LimitesService $limites,
    ) {
    }

    public function resumen(int $cliente): JsonResponse
    {
        return response()->json($this->limites->resumenCliente(Client::findOrFail($cliente)));
    }

    public function ficha(int $cliente): JsonResponse
    {
        $modelo = $this->clienteVisible($cliente);

        return response()->json([
            'ficha' => CreditoClienteFicha::where('cliente_id', $modelo->id)->first(),
            'archivos' => CreditoClienteArchivo::where('cliente_id', $modelo->id)->orderByDesc('id')
                ->get(['id', 'tipo', 'created_at']),
            'cobrador_id' => CarteraAsignacion::vigentes()->where('tipo', 'cliente')->where('referencia_id', $modelo->id)->value('cobrador_id'),
        ]);
    }

    public function guardarFicha(FichaCreditoRequest $request, int $cliente): JsonResponse
    {
        return response()->json(['ficha' => $this->clientes->guardarFicha(Client::findOrFail($cliente), $request->validated(), $this->usuario())]);
    }

    public function subirArchivo(ArchivoClienteRequest $request, int $cliente): JsonResponse
    {
        $archivo = $this->clientes->subirArchivo(
            Client::findOrFail($cliente), TipoArchivoCliente::from($request->input('tipo')), $request->file('archivo'), $this->usuario(),
        );

        return response()->json(['archivo' => $archivo->only(['id', 'tipo', 'created_at'])], 201);
    }

    public function verArchivo(int $cliente, int $archivo): StreamedResponse
    {
        $this->clienteVisible($cliente);
        $modelo = CreditoClienteArchivo::where('cliente_id', $cliente)->findOrFail($archivo);

        return Storage::disk(ClienteCreditoService::DISCO)->response($modelo->ruta_archivo);
    }

    public function asignarCobrador(AsignarCobradorRequest $request, int $cliente): JsonResponse
    {
        $cobrador = $request->filled('cobrador_id') ? User::findOrFail((int) $request->input('cobrador_id')) : null;
        $asignacion = $this->clientes->asignarCobrador(Client::findOrFail($cliente), $cobrador, $this->usuario());

        return response()->json(['cobrador_id' => $asignacion?->cobrador_id]);
    }

    /** La ficha es dato personal (Ley 29733): el cobrador solo ve la de clientes de su cartera. */
    private function clienteVisible(int $cliente): Client
    {
        $modelo = Client::findOrFail($cliente);
        $usuario = $this->usuario();
        $asignado = CarteraAsignacion::vigentes()->where('tipo', 'cliente')->where('referencia_id', $modelo->id)
            ->where('cobrador_id', $usuario->id)->exists();
        if (! $usuario->can(AlcanceCartera::PERMISO_VER_TODOS) && ! $asignado) {
            throw new HttpException(404, 'Cliente no encontrado.');
        }

        return $modelo;
    }
}
