<?php

declare(strict_types=1);

namespace App\Services\Creditos\Documentos;

use App\Enums\Creditos\CreditoEstado;
use App\Enums\Creditos\PagoEstado;
use App\Enums\Creditos\TipoDocumentoCredito;
use App\Models\Cash\PaymentMethod;
use App\Models\Company;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoDocumento;
use App\Models\Creditos\CreditoPago;
use App\Models\Creditos\CreditoReprogramacion;
use App\Models\User;
use App\Services\Creditos\AlcanceCartera;
use App\Services\Creditos\AuditoriaCredito;
use App\Services\Creditos\CargadorCredito;
use App\Services\Creditos\ConsultaCreditoService;
use App\Services\Creditos\Dinero;
use App\Services\Creditos\Dto\DetalleCredito;
use App\Services\Creditos\Dto\SaldoCredito;
use App\Services\Creditos\Motor\AplicadorPagos;
use App\Services\Creditos\Motor\Dto\PagoAAplicar;
use App\Services\Creditos\Reloj;
use App\Services\StorageUrl;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Documentos del crédito (Fase 4b, Plan 1.15). Todos se generan a pedido desde los datos, salvo
 * el contrato: se congela la primera vez que se pide (PDF + versión de plantilla + hash) y desde
 * entonces se entrega ese mismo archivo. Montos ya calculados por el motor o la BD.
 */
class DocumentoCreditoService
{
    public const DISCO = 'private';
    public const FORMATOS = ['a4', 'ticket80mm'];
    /** Ancho de un ticket de 80 mm en puntos (mismo valor que el resto de tickets del sistema). */
    private const ANCHO_TICKET = 226.77;
    /** "Interés moratorio" es el nombre que usan los documentos (00 1.3). */
    public const CONCEPTOS = ['capital' => 'Capital', 'interes' => 'Interés', 'cargo' => 'Cargo', 'mora' => 'Interés moratorio'];
    private const ORIGENES = [
        'cobro' => 'Pago', 'liquidacion' => 'Cancelación del crédito', 'renovacion' => 'Cancelación por renovación',
        'venta_prenda' => 'Venta de prenda', 'saldo_a_favor' => 'Pago con saldo a favor', 'saldo_inicial' => 'Pago anterior al sistema',
    ];
    private const ESTADOS_CON_DOCUMENTOS =[CreditoEstado::Activo, CreditoEstado::Castigado, CreditoEstado::Finalizado];

    public function __construct(
        private readonly ConsultaCreditoService $consultas,
        private readonly CargadorCredito $cargador,
        private readonly AplicadorPagos $aplicador,
        private readonly PlantillaContratoService $plantillas,
        private readonly AuditoriaCredito $auditoria,
        private readonly AlcanceCartera $alcance,
    ) {
    }

    // ── Recibo ────────────────────────────────────────────────────────────

    public function recibo(CreditoPago $pago, string $formato, bool $copia, User $usuario): Response
    {
        $pago->load(['aplicaciones' => fn ($q) => $q->where('vigente', true)->with('cuota:id,numero_cuota')->orderBy('id'), 'paymentMethod']);
        $credito = Credito::with('cliente')->findOrFail($pago->credito_id);
        $this->alcance->asegurar($credito, $usuario);
        $despues = $pago->estado === PagoEstado::Valido ? $this->saldoTrasPago($credito, $pago) : null;

        $lineas = $pago->aplicaciones
            ->groupBy(fn ($a) => $a->cuota?->numero_cuota ?? 0)
            ->map(fn ($grupo, $numero) => [
                'numero_cuota' => $numero,
                'conceptos' => $grupo->groupBy(fn ($a) => $a->concepto->value)->map(fn ($g) => FormatoDocumento::soles($g->sum(fn ($a) => Dinero::aCentavos((string) $a->monto))))->all(),
            ])->sortKeys()->values()->all();

        return $this->pdf('recibo', $formato, [
            'pago' => $pago,
            'credito' => $credito,
            'lineas' => $lineas,
            'despues' => $despues,
            'copia' => $copia,
            'anulado' => $pago->estado === PagoEstado::Anulado,
            'origen' => self::ORIGENES[$pago->origen->value] ?? 'Pago',
            'destino' => $pago->destino_excedente?->value,
            'conceptos' => self::CONCEPTOS,
            'metodo' => $pago->paymentMethod ? FormatoDocumento::textoPdf($pago->paymentMethod->name) : null,
            'cajero' => User::find($pago->registrado_por)?->name,
            'renovacion' => $this->desgloseRenovacion($pago),
        ], "recibo-{$pago->numero_recibo}", 330 + count($lineas) * 42);
    }

    /** Saldo y próxima cuota justo después de este pago (pagos válidos hasta él, a su fecha). */
    private function saldoTrasPago(Credito $credito, CreditoPago $pago): SaldoCredito
    {
        $carga = $this->cargador->cargar($credito);
        $fecha = CargadorCredito::fecha($pago->fecha_pago);
        $hasta = array_values(array_filter($carga->pagos, static fn (PagoAAplicar $p): bool => $p->fechaPago->esAnteriorA($fecha)
            || ($p->fechaPago->aTexto() === $fecha->aTexto() && $p->secuencia <= $pago->id)));
        $resultado = $this->aplicador->aplicar($carga->estado, $hasta, $fecha);

        return SaldoCredito::calcular($carga->estado, $resultado, $fecha);
    }

    // ── Cronograma, estado de cuenta, constancia, reprogramación ───────────

    public function cronograma(Credito $credito, string $formato, User $usuario): Response
    {
        $detalle = $this->consultas->detalle($credito, $usuario);
        $this->exigirConDocumentos($credito);

        return $this->pdf('cronograma', $formato, ['credito' => $credito, 'detalle' => $detalle, 'filas' => $this->filasCuotas($credito, $detalle)],
            "cronograma-{$credito->numero_credito}", 300 + $credito->cuotasVigentes->count() * 14);
    }

    public function estadoCuenta(Credito $credito, string $formato, User $usuario): Response
    {
        $detalle = $this->consultas->detalle($credito, $usuario);
        $this->exigirConDocumentos($credito);
        $cuenta = $this->consultas->estadoCuenta(Credito::findOrFail($credito->id), $usuario);
        $metodos = PaymentMethod::whereIn('id', $cuenta->pagos->pluck('payment_method_id')->filter())->pluck('name', 'id')
            ->map(static fn (string $nombre): string => FormatoDocumento::textoPdf($nombre));

        return $this->pdf('estado_cuenta', $formato, ['credito' => $credito, 'detalle' => $detalle, 'cuenta' => $cuenta, 'metodos' => $metodos,
            'filas' => $this->filasCuotas($credito, $detalle), 'conceptos' => self::CONCEPTOS],
            "estado-cuenta-{$credito->numero_credito}", 380 + $credito->cuotasVigentes->count() * 14 + $cuenta->pagos->count() * 14);
    }

    public function constancia(Credito $credito, User $usuario): Response
    {
        $detalle = $this->consultas->detalle($credito, $usuario);
        if ($credito->estado !== CreditoEstado::Finalizado) {
            throw new HttpException(422, 'La constancia de cancelación solo se emite para créditos finalizados.');
        }
        $ultimoPago = $credito->pagos()->where('estado', PagoEstado::Valido)->orderByDesc('fecha_pago')->orderByDesc('id')->first();

        return $this->pdf('constancia', 'a4', ['credito' => $credito, 'detalle' => $detalle, 'ultimoPago' => $ultimoPago],
            "constancia-{$credito->numero_credito}");
    }

    public function reprogramacion(Credito $credito, CreditoReprogramacion $reprogramacion, User $usuario): Response
    {
        $this->consultas->detalle($credito, $usuario);
        if ($reprogramacion->credito_id !== $credito->id) {
            throw new HttpException(404, 'Reprogramación no encontrada.');
        }
        $reprogramacion->load(['cuotas.cuota:id,numero_cuota,monto_total']);

        return $this->pdf('reprogramacion', 'a4', ['credito' => $credito, 'reprogramacion' => $reprogramacion,
            'usuario' => User::find($reprogramacion->registrado_por)?->name], "acuerdo-reprogramacion-{$credito->numero_credito}");
    }

    // ── Contrato ──────────────────────────────────────────────────────────

    /** Contrato congelado del crédito; lo genera y guarda la primera vez (Plan 1.15). */
    public function contrato(Credito $credito, User $usuario): CreditoDocumento
    {
        $this->consultas->detalle($credito, $usuario);
        $this->exigirConDocumentos($credito);

        return DB::transaction(function () use ($credito, $usuario): CreditoDocumento {
            // Lock del crédito: dos pedidos simultáneos no congelan dos contratos distintos.
            Credito::whereKey($credito->id)->lockForUpdate()->first();
            $existente = CreditoDocumento::where('credito_id', $credito->id)->where('tipo', TipoDocumentoCredito::Contrato)->orderBy('id')->first();
            if ($existente !== null) {
                return $existente;
            }

            $plantilla = $this->plantillas->vigente();
            $html = $this->plantillas->renderizar($plantilla->contenido, $this->plantillas->valores($credito));
            $contenido = $this->renderizarContrato($html, $credito)->output();
            $ruta = "creditos/{$credito->id}/contrato-v{$plantilla->version}-" . Str::uuid() . '.pdf';
            Storage::disk(self::DISCO)->put($ruta, $contenido);

            $documento = CreditoDocumento::create([
                'credito_id' => $credito->id,
                'tipo' => TipoDocumentoCredito::Contrato,
                'ruta_archivo' => $ruta,
                'plantilla_version' => $plantilla->version,
                'hash' => hash('sha256', $contenido),
                'registrado_por' => $usuario->id,
            ]);
            $this->auditoria->registrar('documento.contrato', $documento, $credito->id, null, ['plantilla_version' => $plantilla->version], null, $usuario);

            return $documento;
        });
    }

    /** Vista previa de la plantilla con datos de ejemplo (no guarda nada). */
    public function vistaPreviaContrato(string $html, ?Credito $ejemplo): Response
    {
        $limpio = $this->plantillas->sanitizar($html);
        $valores = $ejemplo !== null ? $this->plantillas->valores($ejemplo) : array_map(
            static fn (string $descripcion): string => '[' . e($descripcion) . ']',
            PlantillaContratoService::VARIABLES,
        );
        $pdf = $this->renderizarContrato($this->plantillas->renderizar($limpio, $valores), $ejemplo);

        return $pdf->stream('vista-previa-contrato.pdf');
    }

    public function subirContratoFirmado(Credito $credito, UploadedFile $archivo, User $usuario): CreditoDocumento
    {
        $this->consultas->detalle($credito, $usuario);
        $this->exigirConDocumentos($credito);
        $contenido = (string) file_get_contents($archivo->getRealPath());
        $extension = strtolower($archivo->getClientOriginalExtension() ?: $archivo->extension());
        $ruta = "creditos/{$credito->id}/contrato-firmado-" . Str::uuid() . ".{$extension}";
        Storage::disk(self::DISCO)->put($ruta, $contenido);

        $documento = CreditoDocumento::create([
            'credito_id' => $credito->id,
            'tipo' => TipoDocumentoCredito::ContratoFirmado,
            'ruta_archivo' => $ruta,
            'hash' => hash('sha256', $contenido),
            'registrado_por' => $usuario->id,
        ]);
        $this->auditoria->registrar('documento.contrato_firmado', $documento, $credito->id, null, ['ruta' => $ruta], null, $usuario);

        return $documento;
    }

    public function archivo(CreditoDocumento $documento): Response
    {
        $disco = Storage::disk(self::DISCO);
        if (! $disco->exists($documento->ruta_archivo)) {
            throw new HttpException(404, 'El archivo del documento no está disponible.');
        }
        $credito = Credito::find($documento->credito_id);
        $nombre = ($documento->tipo === TipoDocumentoCredito::Contrato ? 'contrato' : 'contrato-firmado')
            . "-{$credito?->numero_credito}." . pathinfo($documento->ruta_archivo, PATHINFO_EXTENSION);

        return $disco->response($documento->ruta_archivo, $nombre, [], 'inline');
    }

    // ── Internos ──────────────────────────────────────────────────────────

    /**
     * Cuotas vigentes listas para imprimir: estado a hoy y mora del día (del motor).
     *
     * @return list<array<string, mixed>>
     */
    private function filasCuotas(Credito $credito, DetalleCredito $detalle): array
    {
        $hoy = app(Reloj::class)->hoy()->aTexto();

        return $credito->cuotasVigentes->map(function ($c) use ($detalle, $hoy): array {
            $pagada = FormatoDocumento::valor($c->estado) === 'pagada';
            $mora = $detalle->situacion?->mora($c->numero_cuota)->moraPendiente ?? 0;
            $pagado = Dinero::aCentavos((string) $c->capital_pagado) + Dinero::aCentavos((string) $c->interes_pagado)
                + Dinero::aCentavos((string) $c->cargo_pagado);

            return [
                'numero' => $c->numero_cuota,
                'vence' => FormatoDocumento::fecha($c->fecha_vencimiento),
                'capital' => FormatoDocumento::soles((string) $c->monto_capital),
                'interes' => FormatoDocumento::soles((string) $c->monto_interes),
                'cargo' => (string) $c->cargo_monto !== '0.00' ? FormatoDocumento::soles((string) $c->cargo_monto) : null,
                'total' => FormatoDocumento::soles((string) $c->monto_total),
                'pagado' => $pagado > 0 ? FormatoDocumento::soles($pagado) : '—',
                'mora' => $mora > 0 ? FormatoDocumento::soles($mora) : '—',
                'estado' => $pagada ? 'Pagada' : ($c->fecha_vencimiento->format('Y-m-d') < $hoy ? 'Vencida' : 'Pendiente'),
                'vencida' => ! $pagada && $c->fecha_vencimiento->format('Y-m-d') < $hoy,
            ];
        })->values()->all();
    }

    private function renderizarContrato(string $html, ?Credito $credito): \Barryvdh\DomPDF\PDF
    {
        [$empresa, $logo] = $this->empresa('a4');

        return Pdf::loadView('pdf.creditos.contrato_a4', ['cuerpo' => $html, 'credito' => $credito, 'empresa' => $empresa, 'logo' => $logo])
            ->setPaper('a4', 'portrait');
    }

    private function exigirConDocumentos(Credito $credito): void
    {
        if (! in_array($credito->estado, self::ESTADOS_CON_DOCUMENTOS, true)) {
            throw new HttpException(422, 'Este crédito todavía no se activó (o fue anulado): no tiene documentos.');
        }
    }

    /** @return array{0: ?Company, 1: ?string} */
    private function empresa(string $formato): array
    {
        $empresa = Company::first();
        $logo = StorageUrl::resolveParaPdf($formato === 'ticket80mm' ? $empresa?->logo_vertical : $empresa?->logo_horizontal);

        return [$empresa, $logo];
    }

    private function pdf(string $documento, string $formato, array $datos, string $nombre, int $altoTicket = 600): Response
    {
        $formato = in_array($formato, self::FORMATOS, true) ? $formato : 'a4';
        [$empresa, $logo] = $this->empresa($formato);
        $vista = "pdf.creditos.{$documento}_" . ($formato === 'ticket80mm' ? '80mm' : 'a4');
        $pdf = Pdf::loadView($vista, [...$datos, 'empresa' => $empresa, 'logo' => $logo]);
        $formato === 'ticket80mm' ? $pdf->setPaper([0, 0, self::ANCHO_TICKET, $altoTicket], 'portrait') : $pdf->setPaper('a4', 'portrait');

        return $pdf->stream(Str::slug($nombre) . '.pdf');
    }

    /**
     * Pago "renovación" (1.21): cuánto cubrió el crédito nuevo y cuánto pagó el cliente en caja
     * (ej.: debía 600, renovó por 500 → crédito nuevo 500, cliente 100). Null si no es renovación.
     *
     * @return array{credito: string|null, cubierto: string, cliente: string}|null
     */
    private function desgloseRenovacion(CreditoPago $pago): ?array
    {
        if ($pago->origen !== \App\Services\Creditos\Motor\Enums\OrigenPago::Renovacion) {
            return null;
        }
        $nuevo = Credito::where('credito_renovado_id', $pago->credito_id)
            ->where('estado', '!=', \App\Enums\Creditos\CreditoEstado::Anulado)->latest('id')->first()
            ?? Credito::where('credito_renovado_id', $pago->credito_id)->latest('id')->first();
        $cubierto = $nuevo ? Dinero::aCentavos((string) $nuevo->monto_capital) : 0;

        return [
            'credito' => $nuevo?->numero_credito,
            'cubierto' => FormatoDocumento::soles(Dinero::aSoles($cubierto)),
            'cliente' => FormatoDocumento::soles(Dinero::aSoles(\App\Services\Creditos\CobradoAlCliente::centavos($pago))),
        ];
    }
}
