<?php

declare(strict_types=1);

namespace Tests\Feature\Creditos;

use App\Enums\Creditos\TipoArchivoCliente;
use App\Models\Creditos\CarteraAsignacion;
use App\Models\User;
use App\Services\Creditos\ClienteCreditoService;
use App\Services\Creditos\CobroService;
use App\Services\Creditos\ConsultaCreditoService;
use App\Services\Creditos\Dto\SolicitudCobro;
use App\Services\Creditos\LimitesService;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Detalle en vivo, cobranza del día con alcance de cartera, cobrador con historial, ficha y archivos. */
class ConsultasYClienteTest extends CreditosTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->usuario();
        $this->abrirCaja($this->admin);
    }

    public function test_detalle_calcula_mora_y_exigible_a_hoy(): void
    {
        $credito = $this->activo($this->admin);
        $this->hoy('2026-02-05');   // cuota 1 con 5 días: 100 de mora

        $detalle = app(ConsultaCreditoService::class)->detalle($credito, $this->admin);

        $this->assertSame(10_000, $detalle->situacion->mora(1)->moraPendiente);
        $this->assertSame(5, $detalle->diasAtraso);
        $this->assertSame(130_000, $detalle->exigible);   // cuota 1 + mora + cuota 2 (próxima)
        $this->assertSame(500_000, $detalle->saldoCapital);
    }

    public function test_cobranza_del_dia_respeta_la_cartera_del_cobrador(): void
    {
        $asignado = $this->activo($this->admin);
        $ajeno = $this->activo($this->admin);
        $cobrador = $this->usuario(['creditos.ver', 'creditos.cobrar']);
        app(ClienteCreditoService::class)->asignarCobrador($asignado->cliente, $cobrador, $this->admin);
        $this->hoy('2026-01-31');

        $delAdmin = array_map(fn ($d) => $d->credito->id, app(ConsultaCreditoService::class)->cobranzaDelDia($this->admin));
        $delCobrador = array_map(fn ($d) => $d->credito->id, app(ConsultaCreditoService::class)->cobranzaDelDia($cobrador));

        $this->assertEqualsCanonicalizing([$asignado->id, $ajeno->id], $delAdmin);
        $this->assertSame([$asignado->id], $delCobrador);
        $this->assertSame(1, app(ConsultaCreditoService::class)->listar([], $cobrador)->total());
    }

    public function test_reasignar_cobrador_cierra_la_vigencia_anterior_sin_borrarla(): void
    {
        $cliente = $this->cliente();
        $primero = $this->usuario(['creditos.cobrar']);
        $segundo = $this->usuario(['creditos.cobrar']);
        app(ClienteCreditoService::class)->asignarCobrador($cliente, $primero, $this->admin);
        $this->hoy('2026-02-01');
        app(ClienteCreditoService::class)->asignarCobrador($cliente, $segundo, $this->admin);

        $historial = CarteraAsignacion::where('referencia_id', $cliente->id)->orderBy('id')->get();
        $this->assertCount(2, $historial);
        $this->assertSame('2026-02-01', $historial[0]->vigente_hasta->format('Y-m-d'));
        $this->assertNull($historial[1]->vigente_hasta);
        $this->assertSame($segundo->id, $historial[1]->cobrador_id);
    }

    public function test_no_se_asigna_como_cobrador_a_quien_no_puede_cobrar(): void
    {
        $this->expectException(HttpException::class);
        app(ClienteCreditoService::class)->asignarCobrador($this->cliente(), $this->usuario(['creditos.ver']), $this->admin);
    }

    public function test_archivo_del_cliente_se_comprime_en_el_disco_privado(): void
    {
        Storage::fake(ClienteCreditoService::DISCO);
        $cliente = $this->cliente();
        $foto = UploadedFile::fake()->image('dni.png', 4000, 2500);

        $archivo = app(ClienteCreditoService::class)->subirArchivo($cliente, TipoArchivoCliente::DniAnverso, $foto, $this->admin);

        Storage::disk(ClienteCreditoService::DISCO)->assertExists($archivo->ruta_archivo);
        $this->assertStringEndsWith('.jpg', $archivo->ruta_archivo);
        [$ancho, $alto] = getimagesizefromstring(Storage::disk(ClienteCreditoService::DISCO)->get($archivo->ruta_archivo));
        $this->assertSame(1600, max($ancho, $alto));
    }

    public function test_resumen_del_cliente_para_el_formulario(): void
    {
        $cliente = $this->cliente();
        $credito = $this->activo($this->admin, $cliente);
        $this->hoy('2026-01-31');
        app(CobroService::class)->cobrar($credito, new SolicitudCobro(60_000, DestinoExcedente::Devolver, $this->efectivo->id, 'resumen-0001'), $this->admin);

        $resumen = app(LimitesService::class)->resumenCliente($cliente);

        $this->assertSame(1, $resumen['creditos_activos']);
        $this->assertSame('4500.00', $resumen['deuda_actual']);
        $this->assertSame(1, $resumen['cuotas_pagadas_a_tiempo']);
        $this->assertFalse($resumen['bloqueado']);
    }
}
