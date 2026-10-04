<?php

declare(strict_types=1);

namespace Tests\Feature\Creditos;

use App\Enums\Creditos\TipoDocumentoCredito;
use App\Enums\Creditos\TipoPlantilla;
use App\Models\Creditos\CreditoDocumento;
use App\Models\Creditos\CreditoPlantilla;
use App\Models\User;
use App\Services\Creditos\CobroService;
use App\Services\Creditos\Documentos\DocumentoCreditoService;
use App\Services\Creditos\Documentos\FormatoDocumento;
use App\Services\Creditos\Documentos\PlantillaContratoService;
use App\Enums\Creditos\AccionMoraReprogramacion;
use App\Services\Creditos\Dto\SolicitudCobro;
use App\Services\Creditos\Dto\SolicitudLiquidacion;
use App\Services\Creditos\Dto\SolicitudReprogramacion;
use App\Services\Creditos\LiquidacionService;
use App\Services\Creditos\ReprogramacionService;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Fase 4b: plantilla de contrato, contrato congelado, recibos y documentos a pedido. */
class DocumentosCreditoTest extends CreditosTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->admin = $this->usuario();
        $this->abrirCaja($this->admin);
    }

    // ── Formato ───────────────────────────────────────────────────────────

    public function test_formato_de_montos_y_letras_sin_coma_flotante(): void
    {
        $this->assertSame('S/ 1,234,567.89', FormatoDocumento::soles(123_456_789));
        $this->assertSame('S/ 0.10', FormatoDocumento::soles('0.10'));
        $this->assertSame('MIL CON 50/100 SOLES', FormatoDocumento::enLetras('1000.50'));
        $this->assertSame('15/02/2026', FormatoDocumento::fechaTexto('2026-02-15'));
    }

    // ── Plantilla ─────────────────────────────────────────────────────────

    public function test_sanitizar_quita_scripts_atributos_y_etiquetas_no_permitidas(): void
    {
        $limpio = app(PlantillaContratoService::class)->sanitizar(
            '<p class="ql-align-center" onclick="x()" style="color:red">Hola <script>alert(1)</script><a href="http://x">{cliente_nombre}</a></p><img src="x" onerror="y">',
        );

        $this->assertSame('<p class="ql-align-center">Hola {cliente_nombre}</p>', $limpio);
    }

    public function test_guardar_crea_version_nueva_y_rechaza_variables_desconocidas(): void
    {
        $servicio = app(PlantillaContratoService::class);
        $antes = $servicio->vigente();

        $nueva = $servicio->guardar('<p>Contrato de {cliente_nombre}</p>', $this->admin);

        $this->assertSame($antes->version + 1, $nueva->version);
        $this->assertFalse($antes->fresh()->activa);
        $this->assertSame($nueva->id, $servicio->vigente()->id);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('{inventada}');
        $servicio->guardar('<p>{inventada}</p>', $this->admin);
    }

    public function test_renderizar_escapa_los_datos_del_cliente(): void
    {
        $cliente = $this->cliente();
        $cliente->update(['full_name' => '<b>Juan</b> & Cía']);
        $credito = $this->activo($this->admin, $cliente);
        $servicio = app(PlantillaContratoService::class);

        $html = $servicio->renderizar('<p>{cliente_nombre}</p><p>{cronograma}</p>', $servicio->valores($credito->fresh()));

        $this->assertStringContainsString('&lt;b&gt;Juan&lt;/b&gt; &amp; Cía', $html);
        $this->assertStringContainsString('<table class="cronograma">', $html);
        $this->assertStringNotContainsString('<p><table', $html);
    }

    // ── Contrato congelado ────────────────────────────────────────────────

    public function test_el_contrato_se_congela_la_primera_vez_y_no_cambia_con_la_plantilla(): void
    {
        $credito = $this->activo($this->admin);
        $servicio = app(DocumentoCreditoService::class);

        $primero = $servicio->contrato($credito->fresh(), $this->admin);
        app(PlantillaContratoService::class)->guardar('<p>Otra versión {numero_credito}</p>', $this->admin);
        $segundo = $servicio->contrato($credito->fresh(), $this->admin);

        $this->assertSame($primero->id, $segundo->id);
        $this->assertSame(1, CreditoDocumento::where('credito_id', $credito->id)->where('tipo', TipoDocumentoCredito::Contrato)->count());
        Storage::disk('private')->assertExists($primero->ruta_archivo);
        $this->assertSame($primero->hash, hash('sha256', Storage::disk('private')->get($primero->ruta_archivo)));
        $this->assertSame(
            CreditoPlantilla::where('tipo', TipoPlantilla::Contrato)->where('activa', false)->orderByDesc('version')->value('version'),
            $primero->plantilla_version,
        );
    }

    public function test_un_borrador_no_tiene_documentos(): void
    {
        $borrador = app(\App\Services\Creditos\CreditoBorradorService::class)->crear($this->datos($this->cliente()->id), $this->admin);

        $this->expectException(HttpException::class);
        app(DocumentoCreditoService::class)->contrato($borrador->fresh(), $this->admin);
    }

    public function test_subir_contrato_firmado_guarda_el_archivo_con_su_hash(): void
    {
        $credito = $this->activo($this->admin);

        $documento = app(DocumentoCreditoService::class)->subirContratoFirmado(
            $credito->fresh(), UploadedFile::fake()->create('firmado.pdf', 120, 'application/pdf'), $this->admin,
        );

        $this->assertSame(TipoDocumentoCredito::ContratoFirmado, $documento->tipo);
        Storage::disk('private')->assertExists($documento->ruta_archivo);
        $this->assertStringEndsWith('.pdf', $documento->ruta_archivo);
    }

    // ── Recibo y documentos a pedido ──────────────────────────────────────

    public function test_el_recibo_se_genera_en_ambos_formatos_y_calcula_el_saldo_a_la_fecha_del_pago(): void
    {
        $credito = $this->activo($this->admin);
        $this->hoy('2026-01-31');
        $cobros = app(CobroService::class);
        $primero = $cobros->cobrar($credito, new SolicitudCobro(60_000, DestinoExcedente::Devolver, $this->efectivo->id, 'doc-1'), $this->admin);
        $this->hoy('2026-03-02');
        $cobros->cobrar($credito->fresh(), new SolicitudCobro(60_000, DestinoExcedente::Devolver, $this->efectivo->id, 'doc-2'), $this->admin);
        $servicio = app(DocumentoCreditoService::class);

        foreach (DocumentoCreditoService::FORMATOS as $formato) {
            $respuesta = $servicio->recibo($primero->fresh(), $formato, false, $this->admin);
            $this->assertSame('application/pdf', $respuesta->headers->get('Content-Type'));
        }

        // El saldo del primer recibo no incluye el segundo pago, registrado después.
        $saldo = (fn () => $this->saldoTrasPago($credito->fresh(), $primero->fresh()))->call($servicio);
        $this->assertSame(540_000, $saldo->porPagar());
        $this->assertSame(2, $saldo->proxima?->numeroCuota);
    }

    public function test_cronograma_estado_de_cuenta_y_reglas_de_la_constancia(): void
    {
        $credito = $this->activo($this->admin);
        $servicio = app(DocumentoCreditoService::class);

        foreach (DocumentoCreditoService::FORMATOS as $formato) {
            $this->assertSame('application/pdf', $servicio->cronograma($credito->fresh(), $formato, $this->admin)->headers->get('Content-Type'));
            $this->assertSame('application/pdf', $servicio->estadoCuenta($credito->fresh(), $formato, $this->admin)->headers->get('Content-Type'));
        }

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('finalizados');
        $servicio->constancia($credito->fresh(), $this->admin);
    }

    public function test_constancia_de_un_credito_liquidado_y_acuerdo_de_reprogramacion(): void
    {
        $servicio = app(DocumentoCreditoService::class);

        $reprogramado = $this->activo($this->admin);
        $acuerdo = app(ReprogramacionService::class)->reprogramar(
            $reprogramado->fresh(), new SolicitudReprogramacion(2, 10, [], AccionMoraReprogramacion::Mantener, 2_000, 'Cliente enfermo', 'doc-r'), $this->admin,
        );
        $this->assertSame('application/pdf', $servicio->reprogramacion($reprogramado->fresh(), $acuerdo, $this->admin)->headers->get('Content-Type'));

        $liquidado = $this->activo($this->admin);
        $this->hoy('2026-01-02');
        $monto = app(LiquidacionService::class)->cotizar($liquidado, null, $this->admin)->montoLiquidacion;
        app(LiquidacionService::class)->liquidar($liquidado, new SolicitudLiquidacion($monto, $this->efectivo->id, 'doc-liq'), $this->admin);
        // Se resuelve de nuevo: el servicio de arriba quedó con el "hoy" anterior del Reloj.
        $this->assertSame('application/pdf', app(DocumentoCreditoService::class)->constancia($liquidado->fresh(), $this->admin)->headers->get('Content-Type'));
    }

    public function test_un_cobrador_no_ve_documentos_fuera_de_su_cartera(): void
    {
        $credito = $this->activo($this->admin);
        $this->hoy('2026-01-31');
        $pago = app(CobroService::class)->cobrar($credito, new SolicitudCobro(60_000, DestinoExcedente::Devolver, $this->efectivo->id, 'doc-3'), $this->admin);
        $ajeno = $this->usuario(['creditos.ver', 'creditos.cobrar']);

        $this->expectException(HttpException::class);
        app(DocumentoCreditoService::class)->recibo($pago->fresh(), 'a4', true, $ajeno);
    }
}
