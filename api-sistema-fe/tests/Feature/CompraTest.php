<?php

namespace Tests\Feature;

use App\Http\Controllers\AgenciaViajes\ProveedorController as ProveedorAgenciaController;
use App\Http\Controllers\Compras\CompraController;
use App\Http\Controllers\Compras\CompraPeriodoController;
use App\Models\Compras\Compra;
use App\Models\Compras\CompraPeriodo;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\Compras\CompraException;
use App\Services\HoraPeru;
use App\Services\TipoCambio\TipoCambioSunatResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

// T-135 (Parte B) — comprobante de compra, CA2 a CA9, contra Postgres real. El CA1 (módulo
// activable) está en ModulosTenantTest y el CA10 en ComprasMigracionesPorGiroTest.
class CompraTest extends TestCase
{
    private const RUC = '20135000001';

    private User $usuario;

    private Proveedor $proveedor;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => env('DB_HOST', '127.0.0.1'),
            'database.connections.pgsql.port' => env('DB_PORT', '5432'),
            'database.connections.pgsql.database' => 'sistemafe_test_migrations',
            'database.connections.pgsql.username' => env('DB_USERNAME', 'root'),
            'database.connections.pgsql.password' => env('DB_PASSWORD', ''),
            'database.connections.central.database' => 'sistemafe_test_migrations',
        ]);
        DB::purge('pgsql');
        DB::purge('central');
        DB::beginTransaction();
        DB::connection('central')->beginTransaction();

        $this->usuario = User::factory()->create();
        auth('api')->setUser($this->usuario);
        $this->proveedor = $this->crearProveedor(['razon_social' => 'T135 Distribuidora SAC', 'ciudad' => 'Tarapoto']);
    }

    protected function tearDown(): void
    {
        DB::connection('central')->rollBack();
        DB::rollBack();
        parent::tearDown();
    }

    private function crearProveedor(array $datos): Proveedor
    {
        return Proveedor::create($datos + ['razon_social' => 'T135 Proveedor', 'tipo_documento' => 'RUC', 'numero_documento' => self::RUC]);
    }

    private function datos(array $cambios = []): array
    {
        return array_merge([
            'proveedor_id' => $this->proveedor->id,
            'tipo_comprobante_codigo' => '01',
            'serie' => 'F001',
            'numero' => '123',
            'fecha_emision' => '2026-03-10',
            'moneda' => 'PEN',
            'base_gravada' => '100.00',
            'igv' => '18.00',
            'exonerado' => '0.10',
            'inafecto' => '0.20',
            'otros' => '0',
            'total' => '118.30',
            'condicion' => 'contado',
        ], $cambios);
    }

    /** Ejecuta la acción y devuelve la respuesta, también cuando es un rechazo de negocio. */
    private function responder(callable $accion): JsonResponse
    {
        try {
            return $accion();
        } catch (CompraException $e) {
            return $e->render();
        }
    }

    private function registrar(array $cambios = [], array $archivos = []): JsonResponse
    {
        $request = Request::create('/api/compras', 'POST', $this->datos($cambios), [], $archivos);

        return $this->responder(fn () => app(CompraController::class)->store($request));
    }

    private function editar(int $id, array $cambios = []): JsonResponse
    {
        $request = Request::create("/api/compras/{$id}", 'POST', $this->datos($cambios));

        return $this->responder(fn () => app(CompraController::class)->update($request, (string) $id));
    }

    private function anular(int $id, ?string $motivo): JsonResponse
    {
        return $this->responder(fn () => app(CompraController::class)->anular(new Request(['motivo' => $motivo]), (string) $id));
    }

    private function registrada(array $cambios = []): int
    {
        $respuesta = $this->registrar($cambios);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());

        return $respuesta->getData(true)['compra']['id'];
    }

    private function rechazada(array $cambios, string $texto = ''): array
    {
        $respuesta = $this->registrar($cambios);
        $this->assertSame(422, $respuesta->getStatusCode(), $respuesta->getContent());
        if ($texto !== '') {
            $this->assertStringContainsString($texto, $respuesta->getData(true)['message']);
        }

        return $respuesta->getData(true);
    }

    private function listar(array $filtros = []): array
    {
        return app(CompraController::class)->index(new Request($filtros))->getData(true);
    }

    public function test_ca2_guarda_en_soles_y_en_dolares_con_tipo_de_cambio(): void
    {
        $pen = Compra::findOrFail($this->registrada(['serie' => ' f 001 ', 'tipo_cambio' => '3.5']));

        $this->assertSame(['F001', '123', '118.30', null, '2026-03', 'sin_validar'],
            [$pen->serie, $pen->numero, $pen->total, $pen->tipo_cambio, $pen->periodo, $pen->refresh()->estado_sunat]);
        $this->assertSame([self::RUC, self::RUC, $this->usuario->id],
            [$pen->proveedor_documento, $pen->clave_proveedor, $pen->user_id]);

        $usd = Compra::findOrFail($this->registrada(['numero' => '124', 'moneda' => 'USD', 'tipo_cambio' => '3.7515',
            'condicion' => 'credito', 'fecha_vencimiento' => '2026-04-10', 'periodo' => '2026-04']));

        $this->assertSame(['3.7515', '2026-04', '2026-04-10'], [$usd->tipo_cambio, $usd->periodo, $usd->fecha_vencimiento->toDateString()]);

        // Totales del filtro en soles; la nota de crédito resta.
        $this->registrada(['tipo_comprobante_codigo' => '07', 'numero' => '125', 'compra_referencia_id' => $pen->id,
            'base_gravada' => '10', 'igv' => '1.80', 'exonerado' => '0', 'inafecto' => '0', 'total' => '11.80']);
        $lista = $this->listar(['proveedor_id' => $this->proveedor->id]);

        $this->assertSame(3, $lista['total']);
        // 118.30 + round(118.30 × 3.7515) − 11.80
        $this->assertSame('550.30', $lista['totales_pen']['total']);
        $this->assertSame(1, $this->listar(['search' => 'F001-124'])['total']);
        $this->assertSame(3, $this->listar(['search' => 't135 distribuidora'])['total']);
        $this->assertSame(1, $this->listar(['periodo' => '2026-04'])['total']);
    }

    public function test_ca2_rechaza_total_que_no_cuadra_tipo_fecha_y_credito_sin_vencimiento(): void
    {
        $this->rechazada(['total' => '118.31'], 'El total no cuadra');
        $this->rechazada(['total' => '118.29'], 'El total no cuadra');
        foreach (['00', '06', '09', '11', 'NV', '99'] as $tipo) {
            $this->rechazada(['tipo_comprobante_codigo' => $tipo], 'no se usa en compras');
        }
        $manana = HoraPeru::ahora()->addDay()->toDateString();
        $this->rechazada(['fecha_emision' => $manana], 'no puede ser futura');
        $this->rechazada(['condicion' => 'credito'], 'fecha de vencimiento');
        $this->rechazada(['condicion' => 'credito', 'fecha_vencimiento' => '2026-03-09'], 'anterior a la emisión');
        $this->rechazada(['moneda' => 'USD'], 'tipo de cambio');
        $this->rechazada(['periodo' => '2026-02'], 'anterior al mes de emisión');
        $this->rechazada(['igv' => '-18', 'total' => '82.30'], 'negativos');
        $this->rechazada(['serie' => '   ']);

        $this->assertSame(0, Compra::where('proveedor_id', $this->proveedor->id)->count());

        // Hoy de Perú sí vale, y en contado el vencimiento no se guarda.
        $id = $this->registrada(['fecha_emision' => HoraPeru::hoyTexto(), 'fecha_vencimiento' => HoraPeru::hoyTexto()]);
        $this->assertNull(Compra::findOrFail($id)->fecha_vencimiento);
    }

    public function test_ca2_el_formulario_recibe_los_tipos_de_compras_y_el_tipo_de_cambio_de_la_fecha(): void
    {
        DB::connection('central')->table('tipo_cambio_sunat')->where('fecha', '<=', '2026-03-10')->delete();
        DB::connection('central')->table('tipo_cambio_sunat')->insert([
            'fecha' => '2026-03-09', 'compra' => 3.70, 'venta' => 3.72, 'fuente' => 'test', 'consultado_en' => now(),
        ]);

        $datos = app(CompraController::class)
            ->catalogos(new Request(['fecha' => '2026-03-10']), app(TipoCambioSunatResolver::class))->getData(true);

        $this->assertSame(['01', '02', '03', '04', '05', '07', '08', '10', '12', '13', '14', '91'], array_column($datos['tipos'], 'codigo'));
        $this->assertSame('2026-03-09', $datos['tipo_cambio']['fecha']);
        $this->assertSame(3.72, (float) $datos['tipo_cambio']['venta']);
    }

    public function test_ca3_no_se_registra_dos_veces_ni_con_otro_establecimiento_y_la_anulada_no_estorba(): void
    {
        $id = $this->registrada();

        $repetida = $this->registrar(['serie' => 'f001']);
        $this->assertSame(409, $repetida->getStatusCode());
        $this->assertSame($id, $repetida->getData(true)['existente']['id']);

        $sucursal = $this->crearProveedor(['razon_social' => 'T135 Distribuidora SAC', 'ciudad' => 'Cusco']);
        $otra = $this->registrar(['proveedor_id' => $sucursal->id]);
        $this->assertSame(409, $otra->getStatusCode());
        $this->assertSame($id, $otra->getData(true)['existente']['id']);

        // Otro tipo u otro número sí; editar una hacia el número de otra, no.
        $segunda = $this->registrada(['numero' => '999']);
        $this->registrada(['tipo_comprobante_codigo' => '03']);
        $this->assertSame(409, $this->editar($segunda)->getStatusCode());
        $this->assertSame(200, $this->editar($id)->getStatusCode(), 'Editarse a sí misma no es duplicar');

        $this->assertSame(200, $this->anular($id, 'Se registró con montos errados')->getStatusCode());
        $nueva = $this->registrada();
        $this->assertNotSame($id, $nueva);

        // En una carrera lo frena el índice único parcial.
        $this->expectException(UniqueConstraintViolationException::class);
        Compra::findOrFail($nueva)->replicate()->save();
    }

    public function test_ca4_sin_documento_pide_completarlo_y_el_no_domiciliado_entra_con_el_91(): void
    {
        $sinDocumento = $this->crearProveedor(['tipo_documento' => null, 'numero_documento' => null]);
        $error = $this->rechazada(['proveedor_id' => $sinDocumento->id, 'tipo_comprobante_codigo' => '12']);
        $this->assertTrue($error['requiere_documento']);
        $this->assertSame($sinDocumento->id, $error['proveedor_id']);

        // Factura y afines piden RUC; la liquidación de compra acepta DNI.
        $conDni = $this->crearProveedor(['tipo_documento' => 'DNI', 'numero_documento' => '45135000']);
        foreach (['01', '02', '05', '08', '14'] as $tipo) {
            $this->assertTrue($this->rechazada(['proveedor_id' => $conDni->id, 'tipo_comprobante_codigo' => $tipo])['requiere_documento']);
        }
        $this->registrada(['proveedor_id' => $conDni->id, 'tipo_comprobante_codigo' => '04']);
        $this->registrada(['proveedor_id' => $conDni->id, 'tipo_comprobante_codigo' => '03']);

        $extranjero = $this->crearProveedor(['tipo_documento' => null, 'numero_documento' => null, 'es_no_domiciliado' => true, 'pais' => 'Chile']);
        $this->assertTrue($this->rechazada(['proveedor_id' => $extranjero->id, 'tipo_comprobante_codigo' => '12'])['requiere_documento']);
        $compra = Compra::findOrFail($this->registrada(['proveedor_id' => $extranjero->id, 'tipo_comprobante_codigo' => '91']));

        $this->assertNull($compra->proveedor_documento);
        $this->assertSame("id:{$extranjero->id}", $compra->clave_proveedor);
        $this->assertSame(409, $this->registrar(['proveedor_id' => $extranjero->id, 'tipo_comprobante_codigo' => '91'])->getStatusCode());
    }

    public function test_ca5_un_periodo_cerrado_no_cambia_y_reabrirlo_pide_motivo_y_deja_rastro(): void
    {
        $id = $this->registrada();
        $abierta = $this->registrada(['numero' => '200', 'fecha_emision' => '2026-04-05']);
        $periodos = app(CompraPeriodoController::class);

        $this->assertSame(200, $this->responder(fn () => $periodos->cerrar('2026-03'))->getStatusCode());

        foreach ([
            $this->registrar(['numero' => '201']),
            $this->editar($id),
            $this->anular($id, 'motivo'),
            // Mover una compra hacia el periodo cerrado tampoco.
            $this->editar($abierta, ['numero' => '200']),
        ] as $respuesta) {
            $this->assertSame(422, $respuesta->getStatusCode(), $respuesta->getContent());
            $this->assertSame('El periodo 2026-03 está cerrado', $respuesta->getData(true)['message']);
        }
        // En un periodo posterior abierto sí se puede anotar un comprobante de marzo.
        $this->registrada(['numero' => '202', 'periodo' => '2026-04']);
        $this->assertSame(422, $this->responder(fn () => $periodos->cerrar('2026-03'))->getStatusCode(), 'Ya está cerrado');

        $this->assertSame(422, $this->responder(fn () => $periodos->reabrir(new Request(['motivo' => '  ']), '2026-03'))->getStatusCode());
        $this->assertTrue(CompraPeriodo::where('periodo', '2026-03')->first()->estaCerrado());

        $this->assertSame(200, $this->responder(fn () => $periodos->reabrir(new Request(['motivo' => 'Faltó una factura']), '2026-03'))->getStatusCode());
        $this->assertSame(200, $this->editar($id)->getStatusCode());

        // Un segundo cierre no pisa el primero: todo queda en el historial.
        $this->responder(fn () => $periodos->cerrar('2026-03'));
        $fila = collect($periodos->index()->getData(true)['periodos'])->firstWhere('periodo', '2026-03');
        $nombre = trim("{$this->usuario->name} {$this->usuario->surname}");

        $this->assertSame(['cerrado', $nombre, 'Faltó una factura'], [$fila['estado'], $fila['reabierto_por'], $fila['motivo_reapertura']]);
        $this->assertNotNull($fila['reabierto_at']);
        $this->assertSame(['cierre', 'reapertura', 'cierre'], array_column($fila['historial'], 'accion'));
        $this->assertSame('Faltó una factura', $fila['historial'][1]['motivo']);
    }

    public function test_ca6_anular_pide_motivo_deja_rastro_y_no_existe_borrar(): void
    {
        $id = $this->registrada();

        $this->assertSame(422, $this->anular($id, '   ')->getStatusCode());
        $this->assertNull(Compra::findOrFail($id)->anulada_at);

        $this->assertSame(200, $this->anular($id, 'Factura equivocada')->getStatusCode());
        $compra = Compra::findOrFail($id);

        $this->assertNotNull($compra->anulada_at);
        $this->assertSame([$this->usuario->id, 'Factura equivocada'], [$compra->anulada_por, $compra->motivo_anulacion]);
        $this->assertSame(422, $this->anular($id, 'otra vez')->getStatusCode());
        $this->assertSame(422, $this->editar($id)->getStatusCode());
        $this->assertSame(0, $this->listar(['proveedor_id' => $this->proveedor->id])['total']);
        $conAnuladas = $this->listar(['proveedor_id' => $this->proveedor->id, 'incluir_anuladas' => '1']);
        $this->assertSame(1, $conAnuladas['total']);
        $this->assertSame('0.00', $conAnuladas['totales_pen']['total'], 'Una anulada no suma');

        $borrados = collect(Route::getRoutes()->getRoutes())->filter(
            fn ($ruta) => preg_match('#^api/compra(s|-periodos)#', $ruta->uri()) && in_array('DELETE', $ruta->methods(), true)
        );
        $this->assertCount(0, $borrados);
        $this->assertFalse(method_exists(CompraController::class, 'destroy'));

        // El proveedor con compras (aunque anuladas) no se puede eliminar.
        DB::statement('create table if not exists proveedor_servicios (id bigserial primary key, proveedor_id bigint)');
        $this->assertSame(422, app(ProveedorAgenciaController::class)->destroy((string) $this->proveedor->id)->getStatusCode());
    }

    public function test_ca7_la_nota_necesita_una_compra_del_mismo_proveedor_y_vigente(): void
    {
        $factura = $this->registrada();
        $ajeno = $this->crearProveedor(['numero_documento' => '20135000007']);
        $deOtro = $this->registrada(['proveedor_id' => $ajeno->id]);
        $anulada = $this->registrada(['numero' => '300']);
        $this->anular($anulada, 'x');

        foreach (['07', '08'] as $tipo) {
            $nota = ['tipo_comprobante_codigo' => $tipo, 'serie' => 'FC01', 'numero' => '1'];
            $this->rechazada($nota, 'Indica la compra que modifica');
            $this->rechazada($nota + ['compra_referencia_id' => $deOtro], 'de otro proveedor');
            $this->rechazada($nota + ['compra_referencia_id' => $anulada], 'anulada');
            $this->rechazada($nota + ['compra_referencia_id' => 999999999], 'no es válida');
        }

        // Otro establecimiento del mismo RUC es el mismo emisor.
        $sucursal = $this->crearProveedor(['ciudad' => 'Cusco']);
        $id = $this->registrada(['proveedor_id' => $sucursal->id, 'tipo_comprobante_codigo' => '07', 'serie' => 'FC01',
            'numero' => '1', 'compra_referencia_id' => $factura]);
        $this->assertSame($factura, Compra::findOrFail($id)->compra_referencia_id);

        // Una nota no modifica otra nota, aunque llegue directo por la API.
        $this->rechazada(['tipo_comprobante_codigo' => '08', 'serie' => 'FD01', 'numero' => '1',
            'compra_referencia_id' => $id], 'no puede ser otra nota');

        $opciones = $this->listar(['referencia_para' => $sucursal->id]);
        $this->assertSame([$factura], array_column($opciones['compras'], 'id'));
    }

    public function test_ca8_el_adjunto_va_al_disco_private_y_solo_baja_con_la_url_firmada(): void
    {
        Storage::fake('private');
        Storage::fake('public');

        // Un archivo que no es PDF/XML no entra.
        $this->assertSame(422, $this->registrar([], ['adjunto_pdf' => UploadedFile::fake()->image('foto.png')])->getStatusCode());
        $this->assertSame(422, $this->registrar([], ['adjunto_xml' => UploadedFile::fake()->create('x.html', 1, 'text/html')])->getStatusCode());

        $respuesta = $this->registrar([], [
            'adjunto_pdf' => UploadedFile::fake()->create('factura.pdf', 20, 'application/pdf'),
            'adjunto_xml' => UploadedFile::fake()->create('factura.xml', 2, 'text/xml'),
        ]);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
        $compra = Compra::findOrFail($respuesta->getData(true)['compra']['id']);

        Storage::disk('private')->assertExists($compra->adjunto_pdf);
        Storage::disk('private')->assertExists($compra->adjunto_xml);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertTrue($respuesta->getData(true)['compra']['tiene_pdf']);
        $this->assertStringNotContainsString($compra->adjunto_pdf, str_replace('\/', '/', $respuesta->getContent()));

        // Editar sin subir archivos conserva los que había.
        $this->editar($compra->id);
        $this->assertSame($compra->adjunto_pdf, $compra->fresh()->adjunto_pdf);

        $url = app(CompraController::class)->adjuntoUrl((string) $compra->id, 'pdf')->getData(true)['url'];
        $this->assertStringContainsString("compra-archivo/{$compra->id}/pdf", $url);
        $this->assertStringContainsString('signature=', $url);

        $abrir = fn (string $direccion) => (new ValidateSignature)->handle(
            Request::create($direccion), fn () => app(CompraController::class)->descargar((string) $compra->id, 'pdf')
        );
        $this->assertSame(200, $abrir($url)->getStatusCode());

        // Una factura del exterior numerada con «/» también se descarga (antes daba 500).
        $exterior = Compra::findOrFail($compra->id);
        $exterior->forceFill(['serie' => 'INV/2026', 'numero' => '001\\A'])->save();
        $descarga = app(CompraController::class)->descargar((string) $compra->id, 'pdf');
        $this->assertSame(200, $descarga->getStatusCode());
        $this->assertStringContainsString('01-INV-2026-001-A.pdf', $descarga->headers->get('Content-Disposition'));

        $prohibida = function (string $direccion) use ($abrir) {
            try {
                $abrir($direccion);
                $this->fail('Debió responder 403');
            } catch (InvalidSignatureException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        };
        $prohibida(str_replace("/{$compra->id}/pdf", "/{$compra->id}/xml", $url));
        $this->travel(11)->minutes();
        $prohibida($url);

        $ruta = Route::getRoutes()->getByName('compras.archivo');
        $this->assertContains('signed', $ruta->gatherMiddleware());
        $this->assertContains('modulo:compras', $ruta->gatherMiddleware());
        $this->assertTrue(URL::hasValidSignature(Request::create(URL::signedRoute('compras.archivo', ['id' => 1, 'archivo' => 'pdf']))));
    }

    public function test_ca1_y_ca9_cada_ruta_exige_el_modulo_y_su_permiso(): void
    {
        $esperado = [
            'GET api/compras' => 'compras.ver',
            'GET api/compras/{id}' => 'compras.ver',
            'GET api/compras/catalogos' => 'compras.ver|compras.registrar',
            'GET api/compras/{id}/adjunto/{archivo}' => 'compras.ver',
            // T-152
            'GET api/compras/destinos' => 'compras.registrar',
            'PUT api/compras/{id}/asignaciones' => 'compras.registrar',
            'POST api/compras' => 'compras.registrar',
            'POST api/compras/{id}' => 'compras.registrar',
            'POST api/compras/{id}/anular' => 'compras.anular',
            'GET api/compra-periodos' => 'compras.ver',
            // T-136
            'GET api/compras/pagos' => 'compras.ver',
            'GET api/compras/pagos/{id}' => 'compras.ver',
            'POST api/compras/pagos' => 'compras.pagar',
            'POST api/compras/pagos/{id}/aplicaciones' => 'compras.pagar',
            'POST api/compras/aplicaciones/{id}/desaplicar' => 'compras.pagar',
            'POST api/compras/pagos/{id}/anular' => 'compras.anular',
            'POST api/compra-periodos/{periodo}/cerrar' => 'compras.cerrar_periodo',
            'POST api/compra-periodos/{periodo}/reabrir' => 'compras.cerrar_periodo',
            // T-137
            'GET api/compras/cuentas-por-pagar' => 'compras.ver',
            'GET api/compras/cuentas-por-pagar/detalle' => 'compras.ver',
            'GET api/compras/cuentas-por-pagar/excel' => 'compras.ver',
        ];

        $reales = [];
        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            if (! preg_match('#^api/compra(s|-periodos)(/|$)#', $ruta->uri())) {
                continue;
            }
            $middleware = $ruta->gatherMiddleware();
            $clave = $ruta->methods()[0].' '.$ruta->uri();
            $this->assertContains('modulo:compras', $middleware, $clave);
            $this->assertContains('tenant', $middleware, $clave);
            $permisos = array_values(array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'permission:')));
            $this->assertCount(1, $permisos, $clave);
            $reales[$clave] = substr($permisos[0], strlen('permission:'));
        }

        $this->assertEqualsCanonicalizing(array_keys($esperado), array_keys($reales));
        foreach ($esperado as $clave => $permiso) {
            $this->assertSame($permiso, $reales[$clave], $clave);
        }

        // El middleware real: sin el permiso, 403.
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['compras.registrar', 'compras.cerrar_periodo'] as $permiso) {
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => $permiso]);
            $usuario = User::factory()->create();
            auth('api')->setUser($usuario);
            $pasar = fn () => app(PermissionMiddleware::class)->handle(new Request, fn () => response('ok'), $permiso, 'api');

            try {
                $pasar();
                $this->fail("Sin {$permiso} debió responder 403");
            } catch (UnauthorizedException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }

            $usuario->givePermissionTo($permiso);
            $this->assertSame('ok', $pasar()->getContent());
        }
    }
}
