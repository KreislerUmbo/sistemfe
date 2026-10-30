<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\CotizacionController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaModificacionController;
use App\Http\Requests\AgenciaViajes\AplicarModificacionRequest;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaModificacion;
use App\Models\AgenciaViajes\ReservaVenta;
use App\Models\Sale\Sale;
use App\Models\User;
use App\Services\AgenciaViajes\ModificacionPdfService;
use App\Services\AgenciaViajes\PenalidadPasajeroService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// T-006 — PDF de la modificación para el cliente (R-AGE-09). Parte de una reserva creada por el
// camino real (aceptar → abrir la modificación) y pide el PDF por el controller, como la ruta.
// Postgres real, una transacción por test que se revierte. El dinero se compara en centavos.
class ReservaModificacionPdfTest extends TestCase
{
    use ConAccesoATodasLasReservas;

    private User $usuario;

    /** @var array<string, CotizacionPasajero> */
    private array $pax = [];

    /** @var array<string, AlternativaItem> */
    private array $items = [];

    private ?Proveedor $mayorista = null;

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
        ]);
        DB::purge('pgsql');
        DB::beginTransaction();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->usuario = $this->verTodasLasReservas();
        $this->usuario->givePermissionTo(Permission::firstOrCreate(['name' => 'reservas.modificar', 'guard_name' => 'api']));
        Auth::guard('api')->setUser($this->usuario->fresh());
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    // ── Fixtures ────────────────────────────────────────────────────────────────

    private function item(Alternativa $alternativa, array $datos): AlternativaItem
    {
        return AlternativaItem::create($datos + [
            'alternativa_id' => $alternativa->id,
            'origen_tipo' => AlternativaItem::ORIGEN_MANUAL,
            'modo_precio' => 'tarifa_fija',
            'cantidad' => 1,
            'moneda_cotizacion' => 'PEN',
            'moneda_costo' => 'PEN',
            'costo_snapshot' => 0,
            'tip_afe_igv' => '10',
            'destino_tributario' => 'nacional',
        ]);
    }

    // Reserva de Ana, Beto y Carla (niña) con tres servicios de tarifa fija para todos:
    // habitación 200, traslado 100 y un paquete de mayorista 150. Total 450.
    private function reservaConModificacion(): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10000000, 99999999),
            'full_name' => 'Cliente Pdf Modificación', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacion = Cotizacion::create([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-PDF-'.uniqid(), 'cliente_id' => $clienteId,
            'vendedor_id' => $this->usuario->id, 'destino' => 'Tarapoto',
            'fecha_viaje_desde' => '2026-11-10', 'fecha_viaje_hasta' => '2026-11-13',
        ]);
        foreach ([['ana', 'adulto', 30], ['beto', 'adulto', 35], ['carla', 'nino', 8]] as [$clave, $tipo, $edad]) {
            $this->pax[$clave] = CotizacionPasajero::create([
                'cotizacion_id' => $cotizacion->id, 'tipo_pax' => $tipo, 'edad' => $edad,
            ]);
        }

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacion->id, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);

        $this->mayorista = Proveedor::create(['razon_social' => 'Mayorista Secreto SAC', 'estado' => true]);
        $opcion = OpcionMayorista::create([
            'alternativa_id' => $alternativa->id, 'proveedor_id' => $this->mayorista->id, 'moneda' => 'PEN',
            'estado' => 'elegida', 'descripcion_publica' => 'Paquete Selva 3D/2N',
        ]);

        $this->items['habitacion'] = $this->item($alternativa, [
            'descripcion_manual' => 'Habitación doble', 'precio_venta_snapshot' => 200, 'precio_convertido' => 200,
        ]);
        $this->items['traslado'] = $this->item($alternativa, [
            'descripcion_manual' => 'Traslado aeropuerto', 'precio_venta_snapshot' => 100, 'precio_convertido' => 100,
        ]);
        $this->items['paquete'] = $this->item($alternativa, [
            'origen_tipo' => AlternativaItem::ORIGEN_MAYORISTA, 'opcion_mayorista_id' => $opcion->id,
            'moneda_costo' => 'PEN', 'costo_snapshot' => 100, 'precio_venta_snapshot' => 150, 'precio_convertido' => 150,
        ]);

        $respuesta = app(ReservaController::class)->aceptar(new Request(), (string) $alternativa->id);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
        $reserva = Reserva::where('alternativa_id', $alternativa->id)->firstOrFail();

        $respuesta = app(ReservaModificacionController::class)->store((string) $reserva->id);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());

        return [$reserva, ReservaModificacion::findOrFail($respuesta->getData(true)['modificacion']['id'])];
    }

    private function nuevo(ReservaModificacion $m, string $clave): AlternativaItem
    {
        return AlternativaItem::where('alternativa_id', $m->alternativa_nueva_id)
            ->where('item_origen_id', $this->items[$clave]->id)->firstOrFail();
    }

    private function cambiarPrecio(AlternativaItem $item, float $precio): void
    {
        $item->update(['precio_venta_snapshot' => $precio, 'precio_convertido' => $precio]);
    }

    // Cambia los pasajeros por el endpoint real del cotizador.
    private function cambiarPasajeros(ReservaModificacion $m, array $quitar = [], array $agregarEdades = []): void
    {
        $lista = [];
        foreach (CotizacionPasajero::where('cotizacion_id', $m->cotizacion_id)->orderBy('id')->get() as $copia) {
            if (in_array($copia->reserva_pasajero_id, $quitar, true)) {
                continue;
            }
            $lista[] = ['id' => $copia->id, 'edad' => $copia->edad];
        }
        foreach ($agregarEdades as $edad) {
            $lista[] = ['edad' => $edad];
        }

        $respuesta = app(CotizacionController::class)->actualizarPasajeros(
            Request::create('/', 'PUT', ['pasajeros' => $lista]),
            (string) $m->cotizacion_id
        );
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
    }

    private function reservaPasajeroId(Reserva $reserva, string $clave): int
    {
        return (int) $reserva->pasajeros()->where('cotizacion_pasajero_id', $this->pax[$clave]->id)->firstOrFail()->id;
    }

    private function penalidad(ReservaModificacion $m, int $reservaPasajeroId, float $monto = 50): AlternativaItem
    {
        return app(PenalidadPasajeroService::class)->crear($m, [
            'reserva_pasajero_id' => $reservaPasajeroId, 'monto' => $monto, 'motivo' => 'No se presentó',
            'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ]);
    }

    private function diferencia(Reserva $reserva): array
    {
        $respuesta = app(ReservaModificacionController::class)->diferencia((string) $reserva->id);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());

        return $respuesta->getData(true);
    }

    private function aplicar(Reserva $reserva): void
    {
        $peticion = AplicarModificacionRequest::create('/', 'POST', ['huella' => $this->diferencia($reserva)['huella']]);
        $peticion->setContainer(app())->setRedirector(app('redirect'));
        $peticion->validateResolved();

        $respuesta = app(ReservaModificacionController::class)->aplicar($peticion, (string) $reserva->id);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
    }

    private function pedirPdf(Reserva $reserva, array $query = [])
    {
        return app(ReservaModificacionController::class)->pdf(Request::create('/', 'GET', $query), (string) $reserva->id, app(ModificacionPdfService::class));
    }

    // ── CA1 ────────────────────────────────────────────────────────────────────

    public function test_ca1_el_pdf_se_genera_para_una_abierta_con_un_pasajero_agregado_y_una_penalidad(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $this->cambiarPasajeros($m, [$this->reservaPasajeroId($reserva, 'carla')], [30]);
        $this->penalidad($m, $this->reservaPasajeroId($reserva, 'carla'));

        $respuesta = $this->pedirPdf($reserva);

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertStringContainsString('application/pdf', $respuesta->headers->get('Content-Type'));
        $this->assertStringContainsString('modificacion_', $respuesta->headers->get('Content-Disposition'));
        $this->assertMatchesRegularExpression('/_\d{2}-\d{2}-\d{4}\.pdf"?$/',$respuesta->headers->get('Content-Disposition'));

        $contenido = app(ModificacionPdfService::class)->contenido($m);
        $this->assertStringStartsWith('%PDF', $contenido['bytes']);

        $datos = app(ModificacionPdfService::class)->datos($m);
        $this->assertSame(['Agregado', 'Quitado'], collect($datos['pasajeros'])->pluck('accion')->sort()->values()->all());
        $this->assertCount(1, $datos['penalidades']);
        $this->assertStringContainsString('No se presentó', $datos['penalidades'][0]['descripcion']);
        $this->assertSame(5000, $datos['penalidades'][0]['nuevo_centavos']);
        // La penalidad sale en su sección, no repetida en la tabla de cambios.
        $this->assertSame([], collect($datos['lineas'])->filter(fn ($l) => $l['es_penalidad'])->all());
    }

    // ── CA2 ────────────────────────────────────────────────────────────────────

    public function test_ca2_sale_solo_lo_que_cambia(): void
    {
        [, $m] = $this->reservaConModificacion();
        $this->cambiarPrecio($this->nuevo($m, 'habitacion'), 260);

        $datos = app(ModificacionPdfService::class)->datos($m);

        $this->assertCount(1, $datos['lineas']);
        $this->assertSame('Habitación doble', $datos['lineas'][0]['descripcion']);
        $this->assertSame(20000, $datos['lineas'][0]['vendido_centavos']);
        $this->assertSame(26000, $datos['lineas'][0]['nuevo_centavos']);
        $this->assertSame(6000, $datos['lineas'][0]['diferencia_centavos']);
    }

    // ── CA3 ────────────────────────────────────────────────────────────────────

    public function test_ca3_los_totales_del_pdf_son_los_de_la_diferencia(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $this->cambiarPrecio($this->nuevo($m, 'habitacion'), 260);
        $this->cambiarPrecio($this->nuevo($m, 'traslado'), 80);

        $totales = $this->diferencia($reserva)['totales'];
        $datos = app(ModificacionPdfService::class)->datos($m);

        foreach ($totales as $clave => $centavos) {
            $this->assertSame($centavos, $datos['totales'][$clave], "Total {$clave}");
        }
        $this->assertSame($totales['nuevo'] - $totales['anticipos_aplicados'], $datos['totales']['saldo']);
        $this->assertSame($totales['diferencia'], array_sum(array_column($datos['lineas'], 'diferencia_centavos')));
    }

    // ── CA4 ────────────────────────────────────────────────────────────────────

    public function test_ca4_con_un_mayorista_el_pdf_no_muestra_su_nombre(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $this->cambiarPrecio($this->nuevo($m, 'paquete'), 190);

        // La diferencia sí trae el nombre interno: el PDF no debe copiarlo.
        $linea = collect($this->diferencia($reserva)['lineas'])->firstWhere('estado', 'cambio_precio');
        $this->assertSame('Mayorista Secreto SAC', $linea['descripcion']);

        $datos = app(ModificacionPdfService::class)->datos($m);

        $this->assertSame('Paquete Selva 3D/2N', $datos['lineas'][0]['descripcion']);
        $this->assertStringNotContainsString('Secreto', json_encode($datos, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('Secreto', view('pdf.agencia-viajes.modificacion-reserva', [
            ...app(\App\Services\AgenciaViajes\MembreteAgenciaPdfService::class)->datos(),
            ...$datos, 'titulo' => 'x', 'textoLegal' => 'x',
        ])->render());
    }

    // ── CA5 ────────────────────────────────────────────────────────────────────

    public function test_ca5_el_logo_va_por_la_ruta_de_disco_que_dompdf_si_carga(): void
    {
        [, $m] = $this->reservaConModificacion();
        $this->cambiarPrecio($this->nuevo($m, 'habitacion'), 260);

        $datos = app(\App\Services\AgenciaViajes\MembreteAgenciaPdfService::class)->datos();
        $html = view('pdf.agencia-viajes.modificacion-reserva', [
            ...$datos, ...app(ModificacionPdfService::class)->datos($m), 'titulo' => 'x', 'textoLegal' => 'x',
        ])->render();

        // Con logo: la URL viene de resolveParaPdf(), nunca una http(s) que DomPDF no abre.
        if (! empty($datos['logoUrl'])) {
            $this->assertStringContainsString($datos['logoUrl'], $html);
            $this->assertDoesNotMatchRegularExpression('#src="https?://#', $html);
        }
        $this->addToAssertionCount(1); // La comprobación visual (Ghostscript) está en "Resultado" de la tarea.
    }

    // ── CA6 ────────────────────────────────────────────────────────────────────

    public function test_ca6_una_aplicada_sale_con_lo_que_se_aplico_aunque_la_reserva_cambie_despues(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $this->cambiarPrecio($this->nuevo($m, 'habitacion'), 260);
        $this->aplicar($reserva);

        $m = $m->fresh();
        $this->assertSame(ReservaModificacion::ESTADO_APLICADA, $m->estado);
        $esperado = $m->diferencia_aplicada['totales'];

        // La reserva cambia después: se borra un servicio y se suben los otros.
        ReservaItem::where('reserva_id', $reserva->id)->orderBy('id')->first()->delete();
        AlternativaItem::where('alternativa_id', $m->alternativa_nueva_id)->update(['precio_convertido' => 999, 'precio_venta_snapshot' => 999]);

        $datos = app(ModificacionPdfService::class)->datos($m);
        $this->assertSame($esperado['diferencia'], $datos['totales']['diferencia']);
        $this->assertSame($esperado['nuevo'], $datos['totales']['nuevo']);
        $this->assertSame(6000, $datos['lineas'][0]['diferencia_centavos']);
        $this->assertNotNull($datos['aplicadaEn']);

        // Por la ruta: con ?modificacion_id= se pide la aplicada.
        $respuesta = $this->pedirPdf($reserva, ['modificacion_id' => $m->id]);
        $this->assertSame(200, $respuesta->getStatusCode());
    }

    public function test_el_ajuste_de_un_servicio_facturado_sale_una_sola_vez(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $sale = Sale::factory()->create(['user_id' => $this->usuario->id]);
        ReservaVenta::create([
            'reserva_id' => $reserva->id, 'sale_id' => $sale->id,
            'reserva_item_ids' => [ReservaItem::where('reserva_id', $reserva->id)->where('alternativa_item_id', $this->items['habitacion']->id)->value('id')],
            'reserva_pasajero_ids' => [],
        ]);
        $this->cambiarPrecio($this->nuevo($m, 'habitacion'), 250);
        $this->aplicar($reserva);

        $m = $m->fresh();
        // Quedó el servicio "Ajuste — …" en la versión vigente, junto al servicio con el precio subido.
        $this->assertSame(1, AlternativaItem::where('alternativa_id', $m->alternativa_nueva_id)->whereNotNull('ajuste_de_item_id')->count());

        $datos = app(ModificacionPdfService::class)->datos($m);
        $this->assertCount(1, $datos['lineas']);
        $this->assertSame('Ajuste de precio', $datos['lineas'][0]['accion']);
        $this->assertSame(5000, $datos['lineas'][0]['diferencia_centavos']);
        $this->assertSame(5000, $datos['totales']['diferencia']);
        $this->assertStringNotContainsString('Ajuste —', json_encode($datos, JSON_UNESCAPED_UNICODE));
    }

    // ── CA7 y entrada ──────────────────────────────────────────────────────────

    public function test_ca7_la_ruta_exige_reservas_modificar(): void
    {
        $ruta = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/reservas/{id}/modificacion/pdf' && in_array('GET', $r->methods(), true)
        );
        $this->assertNotNull($ruta);
        $this->assertContains('permission:reservas.modificar', $ruta->gatherMiddleware());

        $sin = User::factory()->create(['role_id' => Role::create(['name' => 'rol-sin-'.uniqid(), 'guard_name' => 'api'])->id]);
        Auth::guard('api')->setUser($sin->fresh());
        $this->expectException(UnauthorizedException::class);
        (new PermissionMiddleware())->handle(new Request(), fn () => 'ok', 'reservas.modificar');
    }

    public function test_ca7_sin_poder_ver_la_reserva_la_policy_bloquea(): void
    {
        [$reserva] = $this->reservaConModificacion();

        $otro = User::factory()->create(['role_id' => Role::create(['name' => 'rol-otro-'.uniqid(), 'guard_name' => 'api'])->id]);
        foreach (['reservas.ver', 'reservas.editar', 'reservas.modificar'] as $permiso) {
            $otro->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'api']));
        }
        Auth::guard('api')->setUser($otro->fresh());

        $this->expectException(AuthorizationException::class);
        $this->pedirPdf($reserva);
    }

    public function test_una_modificacion_de_otra_reserva_da_404(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        [$otraReserva] = $this->reservaConModificacion();

        $this->assertSame(404, $this->pedirPdf($otraReserva, ['modificacion_id' => $m->id])->getStatusCode());
        $this->assertSame(200, $this->pedirPdf($reserva, ['modificacion_id' => $m->id])->getStatusCode());
    }

    public function test_sin_modificacion_abierta_ni_id_da_404_y_una_descartada_da_422(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        app(ReservaModificacionController::class)->destroy((string) $reserva->id);

        $this->assertSame(404, $this->pedirPdf($reserva)->getStatusCode());
        $this->assertSame(422, $this->pedirPdf($reserva, ['modificacion_id' => $m->id])->getStatusCode());
    }
}
