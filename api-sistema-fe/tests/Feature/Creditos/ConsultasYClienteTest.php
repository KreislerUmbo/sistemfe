<?php

declare(strict_types=1);

namespace Tests\Feature\Creditos;

use App\Enums\Creditos\TipoArchivoCliente;
use App\Models\Client\Client;
use App\Models\Creditos\CarteraAsignacion;
use App\Models\User;
use App\Services\Creditos\AlcanceCartera;
use App\Services\Creditos\ClienteCreditoService;
use App\Services\Creditos\CobroService;
use App\Http\Controllers\Creditos\CobranzaDelDiaController;
use App\Http\Resources\Creditos\FilaCreditoResource;
use App\Services\Creditos\ConsultaCreditoService;
use App\Services\Creditos\CreditoBorradorService;
use App\Services\Creditos\Dto\SolicitudCobro;
use App\Services\Creditos\LimitesService;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        // Cabecera del detalle (mockup 2): 6,000 por cronograma + 100 de mora.
        $this->assertSame(610_000, $detalle->saldo->porPagar());
        $this->assertSame(0, $detalle->saldo->totalPagado);
        $this->assertSame(1, $detalle->saldo->cuotasVencidas);
        $this->assertSame(2, $detalle->saldo->proxima?->numeroCuota);
        $this->assertSame(60_000, $detalle->saldo->proxima?->pendiente);
    }

    public function test_la_cabecera_cuenta_lo_pagado_y_las_cuotas_pagadas(): void
    {
        $credito = $this->activo($this->admin);
        $this->hoy('2026-01-31');
        app(CobroService::class)->cobrar($credito, new SolicitudCobro(60_000, DestinoExcedente::Devolver, $this->efectivo->id, 'cab-1'), $this->admin);

        $detalle = app(ConsultaCreditoService::class)->detalle($credito->fresh(), $this->admin);

        $this->assertSame(60_000, $detalle->saldo->totalPagado);
        $this->assertSame(1, $detalle->saldo->cuotasPagadas);
        $this->assertSame(540_000, $detalle->saldo->porPagar());
        $this->assertSame(2, $detalle->saldo->proxima?->numeroCuota);
    }

    public function test_la_deuda_de_hoy_desglosa_exactamente_el_exigible(): void
    {
        $credito = $this->activo($this->admin);
        $this->hoy('2026-02-05');   // cuota 1 vencida con 100 de mora, cuota 2 próxima

        $detalle = app(ConsultaCreditoService::class)->detalle($credito, $this->admin);
        $lineas = $detalle->saldo->deudaHoy;

        $this->assertSame([1, 2], array_map(fn ($l) => $l->numeroCuota, $lineas));
        $this->assertTrue($lineas[0]->vencida);
        $this->assertSame([60_000, 10_000, 5], [$lineas[0]->pendiente, $lineas[0]->mora, $lineas[0]->diasAtraso]);
        $this->assertFalse($lineas[1]->vencida);
        $this->assertSame([60_000, 0], [$lineas[1]->pendiente, $lineas[1]->mora]);
        $this->assertSame($detalle->exigible, array_sum(array_map(fn ($l) => $l->pendiente + $l->mora, $lineas)));
    }

    public function test_la_cotizacion_informa_el_saldo_y_la_proxima_cuota_despues_del_pago(): void
    {
        $credito = $this->activo($this->admin);
        $this->hoy('2026-02-05');

        // Cubre la cuota 1 + su mora (70) y abona 30 a la cuota 2.
        $cotizacion = app(CobroService::class)->cotizar($credito, 100_000, DestinoExcedente::Devolver, $this->admin);

        $this->assertSame(510_000, $cotizacion->despues->porPagar());   // 6,100 - 1,000
        $this->assertSame(2, $cotizacion->despues->proxima?->numeroCuota);
        $this->assertSame(30_000, $cotizacion->despues->proxima?->pendiente);
        $this->assertSame(0, $cotizacion->despues->mora);
    }

    public function test_cobranza_del_dia_respeta_la_cartera_del_cobrador(): void
    {
        $asignado = $this->activo($this->admin);
        $ajeno = $this->activo($this->admin);
        $cobrador = $this->usuario(['creditos.ver', 'creditos.cobrar']);
        $this->asignarSoloCobrador($asignado->cliente, $cobrador);
        $this->hoy('2026-01-31');

        $delAdmin = array_map(fn ($d) => $d->credito->id, app(ConsultaCreditoService::class)->cobranzaDelDia($this->admin));
        $delCobrador = array_map(fn ($d) => $d->credito->id, app(ConsultaCreditoService::class)->cobranzaDelDia($cobrador));

        $this->assertEqualsCanonicalizing([$asignado->id, $ajeno->id], $delAdmin);
        $this->assertSame([$asignado->id], $delCobrador);
        $this->assertSame(1, app(ConsultaCreditoService::class)->listar([], $cobrador)->total());
    }

    public function test_el_listado_trae_la_situacion_de_cada_fila(): void
    {
        $activo = $this->activo($this->admin);
        $cliente = $this->cliente();
        $borrador = app(CreditoBorradorService::class)->crear($this->datos($cliente->id), $this->admin);
        $this->hoy('2026-02-05');

        $filas = collect(app(ConsultaCreditoService::class)->listarConSituacion([], $this->admin)->items())->keyBy(fn ($d) => $d->credito->id);
        $json = (new FilaCreditoResource($filas[$activo->id]))->toArray(new Request());

        $this->assertSame('6100.00', $json['situacion']['saldo_por_pagar']);
        $this->assertSame(5, $json['situacion']['dias_atraso']);
        $this->assertSame(2, $json['situacion']['proxima']['numero_cuota']);
        $this->assertSame('6000.00', $json['monto_total']);
        $this->assertNull((new FilaCreditoResource($filas[$borrador->id]))->toArray(new Request())['situacion']);
    }

    public function test_el_listado_ordena_por_cliente_y_por_monto(): void
    {
        $zeta = $this->cliente();
        $zeta->update(['full_name' => 'Zeta Zúñiga']);
        $alfa = $this->cliente();
        $alfa->update(['full_name' => 'Alfa Arias']);
        $deZeta = $this->activo($this->admin, $zeta, $this->datos($zeta->id, capital: 100_000));
        $deAlfa = $this->activo($this->admin, $alfa, $this->datos($alfa->id, capital: 300_000));

        $ids = fn (array $filtros) => app(ConsultaCreditoService::class)->listar($filtros, $this->admin)->pluck('id')->all();

        $this->assertSame([$deAlfa->id, $deZeta->id], $ids(['orden' => 'cliente', 'direccion' => 'asc']));
        $this->assertSame([$deAlfa->id, $deZeta->id], $ids(['orden' => 'monto', 'direccion' => 'desc']));
        $this->assertSame([$deZeta->id, $deAlfa->id], $ids(['orden' => 'monto', 'direccion' => 'asc']));
    }

    public function test_la_cobranza_del_dia_resume_pendientes_y_cobrados_y_filtra_por_cobrador(): void
    {
        $atrasado = $this->activo($this->admin);
        $cobrado = $this->activo($this->admin);
        $cobrador = $this->usuario(['creditos.ver', 'creditos.cobrar']);
        $this->asignarSoloCobrador($cobrado->cliente, $cobrador);
        Auth::guard('api')->setUser($this->admin);
        $this->hoy('2026-02-05');   // cuota 1 vencida hace 5 días: 600 + 100 de mora en cada crédito
        app(CobroService::class)->cobrar($cobrado, new SolicitudCobro(70_000, DestinoExcedente::Devolver, $this->efectivo->id, 'cdd-1'), $this->admin);

        $respuesta = fn (array $query = []) => app(CobranzaDelDiaController::class)->index(new Request($query))->getData(true);
        $todo = $respuesta();

        $this->assertSame(['por_cobrar' => '1300.00', 'cobrado' => '700.00', 'clientes_al_dia' => 1, 'clientes_total' => 2], $todo['resumen']);
        $this->assertSame([$atrasado->id], array_column($todo['data'], 'credito_id'));
        $this->assertSame('vencido', $todo['data'][0]['estado']);
        $this->assertSame([$cobrado->id], array_column($todo['cobrados'], 'credito_id'));
        $this->assertSame($cobrador->id, $todo['cobrados'][0]['cobrador']['id']);

        $delCobrador = $respuesta(['cobrador_id' => $cobrador->id]);
        $this->assertSame([], $delCobrador['data']);
        $this->assertSame('0.00', $delCobrador['resumen']['por_cobrar']);
        $this->assertSame([$atrasado->id], array_column($respuesta(['cobrador_id' => 0])['data'], 'credito_id'));

        // Un abono parcial suma a "cobrado" pero el cliente sigue pendiente: no está al día.
        app(CobroService::class)->cobrar($atrasado, new SolicitudCobro(10_000, DestinoExcedente::Devolver, $this->efectivo->id, 'cdd-2'), $this->admin);
        $conAbono = $respuesta();
        $this->assertSame(['por_cobrar' => '1200.00', 'cobrado' => '800.00', 'clientes_al_dia' => 1, 'clientes_total' => 2], $conAbono['resumen']);
        $this->assertSame([$atrasado->id], array_column($conAbono['data'], 'credito_id'));
    }

    public function test_asesores_y_cobradores_disponibles_segun_permisos(): void
    {
        $asesor = $this->usuario(['creditos.ver', 'creditos.crear', 'creditos.cobrar']);
        $soloColoca = $this->usuario(['creditos.ver', 'creditos.crear']);
        $cobrador = $this->usuario(['creditos.ver', 'creditos.cobrar']);
        $soloVer = $this->usuario(['creditos.ver']);
        $ids = fn (string $lista) => array_column(app(ClienteCreditoService::class)->usuariosCartera()[$lista], 'id');

        // El asesor también cobra (default): necesita colocar y cobrar.
        $this->assertContains($asesor->id, $ids('asesores'));
        $this->assertContains($this->admin->id, $ids('asesores'));
        $this->assertNotContains($soloColoca->id, $ids('asesores'));
        $this->assertNotContains($cobrador->id, $ids('asesores'));
        $this->assertContains($cobrador->id, $ids('cobradores'));
        $this->assertNotContains($soloVer->id, $ids('cobradores'));

        DB::table('credito_configuracion')->update(['asesor_cobra' => false]);
        $this->assertContains($soloColoca->id, $ids('asesores'));
    }

    public function test_reasignar_asesor_cierra_la_vigencia_anterior_sin_borrarla_y_lo_audita(): void
    {
        $cliente = $this->cliente();
        $primero = $this->usuario(['creditos.crear', 'creditos.cobrar']);
        $segundo = $this->usuario(['creditos.crear', 'creditos.cobrar']);
        app(ClienteCreditoService::class)->asignarCartera($cliente, $primero, null, $this->admin);
        $this->hoy('2026-02-01');
        $cartera = app(ClienteCreditoService::class)->asignarCartera($cliente, $segundo, null, $this->admin);

        // El asesor también cobra: cada cambio mueve las dos funciones.
        $this->assertSame(['asesor_id' => $segundo->id, 'cobrador_id' => $segundo->id, 'asesor_cobra' => true], $cartera);
        $historial = CarteraAsignacion::where('referencia_id', $cliente->id)->orderBy('id')->get();
        $this->assertCount(4, $historial);
        $this->assertSame(['2026-02-01', '2026-02-01'], $historial->take(2)->map(fn ($a) => $a->vigente_hasta->format('Y-m-d'))->all());
        $this->assertSame([$segundo->id, $segundo->id], $historial->skip(2)->pluck('usuario_id')->values()->all());
        $this->assertSame(2, DB::table('credito_auditoria')->where('accion', 'cartera.asignar')->where('auditable_id', $cliente->id)->count());
    }

    public function test_asesor_y_cobrador_separados(): void
    {
        DB::table('credito_configuracion')->update(['asesor_cobra' => false]);
        $cliente = $this->cliente();
        $asesor = $this->usuario(['creditos.crear']);
        $cobrador = $this->usuario(['creditos.cobrar']);

        $cartera = app(ClienteCreditoService::class)->asignarCartera($cliente, $asesor, $cobrador, $this->admin);

        $this->assertSame(['asesor_id' => $asesor->id, 'cobrador_id' => $cobrador->id, 'asesor_cobra' => false], $cartera);
        // Los dos ven al cliente.
        $alcance = app(AlcanceCartera::class);
        $this->assertTrue($alcance->puedeVerCliente($cliente->id, $asesor));
        $this->assertTrue($alcance->puedeVerCliente($cliente->id, $cobrador));
    }

    public function test_la_ruta_de_cobro_es_solo_de_los_clientes_que_cobra(): void
    {
        DB::table('credito_configuracion')->update(['asesor_cobra' => false]);
        $asesor = $this->usuario(['creditos.ver', 'creditos.crear', 'creditos.cobrar']);
        $cobrador = $this->usuario(['creditos.ver', 'creditos.cobrar']);
        $credito = $this->activo($this->admin);
        app(ClienteCreditoService::class)->asignarCartera($credito->cliente, $asesor, $cobrador, $this->admin);
        $this->hoy('2026-01-31');

        $ruta = fn (User $u) => array_map(fn ($d) => $d->credito->id, app(ConsultaCreditoService::class)->cobranzaDelDia($u));

        $this->assertSame([$credito->id], $ruta($cobrador));
        $this->assertSame([], $ruta($asesor));   // lo asesora, pero no lo cobra
        $this->assertTrue(app(AlcanceCartera::class)->puedeVer($credito, $asesor));
    }

    public function test_no_se_asigna_a_quien_no_tiene_permiso_para_esa_funcion(): void
    {
        DB::table('credito_configuracion')->update(['asesor_cobra' => false]);
        try {
            app(ClienteCreditoService::class)->asignarCartera($this->cliente(), null, $this->usuario(['creditos.ver']), $this->admin);
            $this->fail('Debía rechazar un cobrador sin permiso de cobrar.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->expectException(HttpException::class);
        app(ClienteCreditoService::class)->asignarCartera($this->cliente(), $this->usuario(['creditos.cobrar']), null, $this->admin);
    }

    /** Solo cobrador (sin asesor), con las funciones separadas. */
    private function asignarSoloCobrador(Client $cliente, User $cobrador): void
    {
        DB::table('credito_configuracion')->update(['asesor_cobra' => false]);
        app(ClienteCreditoService::class)->asignarCartera($cliente, null, $cobrador, $this->admin);
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
