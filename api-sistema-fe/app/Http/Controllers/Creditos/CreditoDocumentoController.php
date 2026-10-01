<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creditos;

use App\Enums\Creditos\TipoDocumentoCredito;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoDocumento;
use App\Models\Creditos\CreditoPago;
use App\Models\Creditos\CreditoReprogramacion;
use App\Models\User;
use App\Services\Creditos\AlcanceCartera;
use App\Services\Creditos\Documentos\DocumentoCreditoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Documentos del crédito (Fase 4b). Los endpoints autenticados validan permiso y cartera y
 * devuelven una URL firmada de 10 minutos (mismo patrón que ventas/notas/recibos); la URL
 * lleva el id del usuario dentro de la firma, así el PDF vuelve a validar su cartera.
 */
class CreditoDocumentoController extends ControllerCreditos
{
    public const DOCUMENTOS = ['cronograma', 'estado-cuenta', 'constancia', 'contrato', 'reprogramacion'];
    private const MINUTOS_URL = 10;
    private const MAXIMO_KB = 10_240;

    public function __construct(private readonly DocumentoCreditoService $documentos)
    {
    }

    /** Documentos guardados (contrato congelado y firmados) y reprogramaciones con acuerdo. */
    public function index(int $credito): JsonResponse
    {
        $modelo = $this->credito($credito);

        return response()->json([
            'documentos' => CreditoDocumento::where('credito_id', $modelo->id)->orderBy('id')->get()
                ->map(static fn (CreditoDocumento $d): array => [
                    'id' => $d->id,
                    'tipo' => $d->tipo->value,
                    'plantilla_version' => $d->plantilla_version,
                    'creado' => $d->created_at?->toIso8601String(),
                    'registrado_por' => User::find($d->registrado_por)?->name,
                ]),
            'reprogramaciones' => CreditoReprogramacion::where('credito_id', $modelo->id)->orderBy('id')->get(['id', 'created_at'])
                ->map(static fn (CreditoReprogramacion $r): array => ['id' => $r->id, 'fecha' => $r->created_at?->format('Y-m-d')]),
        ]);
    }

    /** URL de un documento generado a pedido (o del contrato, que se congela aquí la 1ª vez). */
    public function url(Request $request, int $credito): JsonResponse
    {
        $datos = $request->validate([
            'documento' => ['required', Rule::in(self::DOCUMENTOS)],
            'format' => ['nullable', Rule::in(DocumentoCreditoService::FORMATOS)],
            'reprogramacion_id' => ['nullable', 'required_if:documento,reprogramacion', 'integer'],
        ]);
        $modelo = $this->credito($credito);
        $usuario = $this->usuario();

        if ($datos['documento'] === 'contrato') {
            $contrato = $this->documentos->contrato($modelo, $usuario);

            return response()->json(['url' => $this->urlArchivo($contrato, $usuario), 'documento_id' => $contrato->id]);
        }

        return response()->json(['url' => URL::temporarySignedRoute('creditos.pdf', now()->addMinutes(self::MINUTOS_URL), array_filter([
            'credito' => $modelo->id,
            'documento' => $datos['documento'],
            'format' => $datos['format'] ?? $usuario->formato_impresion_default ?? 'a4',
            'reprogramacion' => $datos['reprogramacion_id'] ?? null,
            'u' => $usuario->id,
        ], static fn ($v): bool => $v !== null))]);
    }

    public function reciboUrl(Request $request, int $credito, int $pago): JsonResponse
    {
        $datos = $request->validate([
            'format' => ['nullable', Rule::in(DocumentoCreditoService::FORMATOS)],
            'copia' => ['nullable', 'boolean'],
        ]);
        $this->credito($credito);
        CreditoPago::where('credito_id', $credito)->findOrFail($pago);
        $usuario = $this->usuario();

        return response()->json(['url' => URL::temporarySignedRoute('creditos.recibo.pdf', now()->addMinutes(self::MINUTOS_URL), [
            'pago' => $pago,
            'format' => $datos['format'] ?? $usuario->formato_impresion_default ?? 'a4',
            'copia' => $request->boolean('copia') ? 1 : 0,
            'u' => $usuario->id,
        ])]);
    }

    public function archivoUrl(int $credito, int $documento): JsonResponse
    {
        $this->credito($credito);

        return response()->json(['url' => $this->urlArchivo(CreditoDocumento::where('credito_id', $credito)->findOrFail($documento), $this->usuario())]);
    }

    public function subirFirmado(Request $request, int $credito): JsonResponse
    {
        $request->validate(['archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:' . self::MAXIMO_KB]]);
        $documento = $this->documentos->subirContratoFirmado($this->credito($credito), $request->file('archivo'), $this->usuario());

        return response()->json(['documento' => ['id' => $documento->id, 'tipo' => $documento->tipo->value]], 201);
    }

    // ── Rutas firmadas (sin token; la firma trae el usuario) ─────────────────

    public function pdf(Request $request, int $credito, string $documento): Response
    {
        $usuario = User::findOrFail((int) $request->query('u'));
        $modelo = Credito::findOrFail($credito);
        $formato = (string) $request->query('format', 'a4');

        return match ($documento) {
            'cronograma' => $this->documentos->cronograma($modelo, $formato, $usuario),
            'estado-cuenta' => $this->documentos->estadoCuenta($modelo, $formato, $usuario),
            'constancia' => $this->documentos->constancia($modelo, $usuario),
            'reprogramacion' => $this->documentos->reprogramacion($modelo, CreditoReprogramacion::findOrFail((int) $request->query('reprogramacion')), $usuario),
            default => abort(404),
        };
    }

    public function recibo(Request $request, int $pago): Response
    {
        return $this->documentos->recibo(
            CreditoPago::findOrFail($pago),
            (string) $request->query('format', 'a4'),
            $request->boolean('copia'),
            User::findOrFail((int) $request->query('u')),
        );
    }

    public function archivo(Request $request, int $documento): Response
    {
        $modelo = CreditoDocumento::findOrFail($documento);
        app(AlcanceCartera::class)->asegurar(Credito::findOrFail($modelo->credito_id), User::findOrFail((int) $request->query('u')));

        return $this->documentos->archivo($modelo);
    }

    private function urlArchivo(CreditoDocumento $documento, User $usuario): string
    {
        return URL::temporarySignedRoute('creditos.archivo', now()->addMinutes(self::MINUTOS_URL), [
            'documento' => $documento->id,
            'u' => $usuario->id,
        ]);
    }
}
