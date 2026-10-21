<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaController;
use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\ConfiguracionAgenciaController;
use App\Http\Controllers\AgenciaViajes\CotizacionController;
use App\Http\Controllers\AgenciaViajes\ReporteOperativoController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaFacturacionController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\PaquetePlantillaPrecio;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\AgenciaViajes\Servicio;
use App\Models\AgenciaViajes\Temporada;
use App\Models\AgenciaViajes\TemporadaOcurrencia;
use App\Models\AgenciaViajes\TourItinerarioItem;
use App\Models\Cash\Branch;
use App\Models\Sale\SerieComprobante;
use App\Models\User;
use App\Services\AgenciaViajes\AlternativaPdfService;
use App\Services\AgenciaViajes\TemporadaResolverService;
use App\Services\AgenciaViajes\TourLineaService;
use App\Services\HoraPeru;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

// Fila 13, fase 1b — el tour en la cotización (plan-tours-venta-simple-costo-real.md
// N6, N7, N14, §3.3, §3.9b, §3.12, §5b; brief PEGAR-EN-CLAUDE-CODE-13-fase1b-tour-cotizacion.md
// §6). Mismo patrón que Fila13Fase1aTourCatalogoTest: Postgres real
// (sistemafe_test_migrations), transacción por test revertida. Las temporadas son
// un catálogo central que no existe en esa base: se sustituye el resolver por uno
// en memoria (las reglas de elección ya están probadas en la 1a).
class Fila13Fase1bTourCotizacionTest extends TestCase
{
    private DestinoAtractivo $destino;

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

        $this->destino = DestinoAtractivo::create(['nombre' => 'Laguna Azul '.uniqid(), 'tipo' => 'zona']);
        ConfiguracionAgencia::first()->update([
            'modo_tours' => ConfiguracionAgencia::MODO_TOURS_NUEVO,
            'tip_afe_igv_default' => '20',
            'destino_tributario_default' => 'amazonia',
        ]);
    }

    protected function tearDown(): void
    {
        app(\Stancl\Tenancy\Tenancy::class)->tenant = null;
        DB::rollBack();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════
    // Fixtures
    // ═══════════════════════════════════════════════════════════════

    /**
     * @param  array<int, array{0: string, 1: int, 2?: string}>  $pasajeros  [tipo, edad, residencia]
     */
    private function crearAlternativa(array $pasajeros, ?string $fechaDesde = '2026-11-15', string $moneda = 'PEN'): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10000000, 99999999), 'full_name' => 'Cliente Test 1b',
            'type_client' => 1, 'cod_tipo_doc_sunat' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-F13B-'.uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => $fechaDesde, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($pasajeros as $p) {
            CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => $p[0], 'edad' => $p[1], 'residencia' => $p[2] ?? 'nacional']);
        }

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => $moneda, 'tipo_cambio_aplicado' => 3.75, 'tipo_cambio_origen' => 'dia',
        ]);
        AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_atractivo_id' => $this->destino->id, 'orden' => 1]);

        return $alternativa;
    }

    private function crearTour(array $atributos = [], int $dias = 1): PaquetePlantilla
    {
        $tour = PaquetePlantilla::create($atributos + [
            'categoria' => 'local',
            'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE,
            'nombre' => 'Laguna Azul Full Day',
            'destino_atractivo_id' => $this->destino->id,
            'duracion_horas' => 10,
            'precio_venta_final' => 150,
            'precio_venta_nino' => 130,
            'costo_estimado_persona' => 120,
        ]);
        for ($dia = 1; $dia <= $dias; $dia++) {
            TourItinerarioItem::create([
                'tour_id' => $tour->id, 'dia_relativo' => $dia, 'orden' => 1,
                'destino_atractivo_id' => $this->destino->id, 'descripcion' => "Día {$dia}",
            ]);
        }

        return $tour;
    }

    private function servicio(string $nombre): DestinoServicio
    {
        $servicio = Servicio::create(['nombre' => $nombre.' '.uniqid()]);

        return DestinoServicio::create(['destino_atractivo_id' => $this->destino->id, 'servicio_id' => $servicio->id]);
    }

    private function incluye(PaquetePlantilla $tour, string $servicio, ?float $costo, ?string $igv = null, ?string $destino = null): PaquetePlantillaItem
    {
        return PaquetePlantillaItem::create([
            'paquete_plantilla_id' => $tour->id,
            'destino_servicio_id' => $this->servicio($servicio)->id,
            'costo_estimado_persona' => $costo,
            'tip_afe_igv' => $igv,
            'destino_tributario' => $destino,
            'orden' => PaquetePlantillaItem::where('paquete_plantilla_id', $tour->id)->count() + 1,
        ]);
    }

    private function crearCombo(array $tours, array $atributos = []): PaquetePlantilla
    {
        $combo = PaquetePlantilla::create($atributos + [
            'categoria' => 'local', 'tipo' => PaquetePlantilla::TIPO_PAQUETE_COMBO, 'nombre' => 'Combo San Martín',
            'destino_atractivo_id' => $this->destino->id, 'duracion_horas' => 30,
        ]);
        foreach ($tours as $k => $tour) {
            PaquetePlantillaItem::create(['paquete_plantilla_id' => $combo->id, 'paquete_plantilla_hijo_id' => $tour->id, 'orden' => $k + 1]);
        }

        return $combo;
    }

    private function cargar(Alternativa $alternativa, PaquetePlantilla $paquete, int $dia = 1)
    {
        return app(AlternativaItemController::class)->desdePlantilla(
            new Request(['paquete_plantilla_id' => $paquete->id, 'dia_referencial' => $dia]),
            (string) $alternativa->id
        );
    }

    private function lineas(Alternativa $alternativa)
    {
        return AlternativaItem::where('alternativa_id', $alternativa->id)->where('origen_tipo', 'tour')->orderBy('id')->get();
    }

    // Temporadas en memoria: [temporada_id, nombre, desde, hasta].
    private function temporadas(array $definiciones): void
    {
        $ocurrencias = [];
        foreach ($definiciones as $k => [$temporadaId, $nombre, $desde, $hasta]) {
            $o = new TemporadaOcurrencia(['temporada_id' => $temporadaId, 'anio' => 2026, 'fecha_desde' => $desde, 'fecha_hasta' => $hasta]);
            $o->id = 1000 + $k;
            $o->setRelation('temporada', (new Temporada)->forceFill(['id' => $temporadaId, 'nombre' => $nombre]));
            $ocurrencias[] = $o;
        }

        app()->instance(TemporadaResolverService::class, new class($ocurrencias) extends TemporadaResolverService
        {
            public function __construct(private array $ocurrencias)
            {
            }

            public function resolver(string $fecha, ?array $temporadaIds = null): ?TemporadaOcurrencia
            {
                if ($temporadaIds === []) {
                    return null;
                }

                return $this->elegir(array_filter(
                    $this->ocurrencias,
                    fn (TemporadaOcurrencia $o) => $temporadaIds === null || in_array($o->temporada_id, $temporadaIds, true)
                ), $fecha);
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // Interruptor (§5b)
    // ═══════════════════════════════════════════════════════════════

    public function test_modo_clasico_sigue_desarmando_el_tour(): void
    {
        ConfiguracionAgencia::first()->update(['modo_tours' => 'clasico']);
        $alternativa = $this->crearAlternativa([['adulto', 30]]);
        $tour = $this->crearTour();
        $tarifa = $this->tarifaCompartida(20, 50);
        PaquetePlantillaItem::create(['paquete_plantilla_id' => $tour->id, 'proveedor_tarifa_id' => $tarifa->id, 'orden' => 1]);

        $respuesta = $this->cargar($alternativa, $tour);

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertCount(0, $this->lineas($alternativa));
        $this->assertSame(['proveedor'], AlternativaItem::where('alternativa_id', $alternativa->id)->pluck('origen_tipo')->all());
    }

    public function test_modo_nuevo_una_linea_con_precio_por_pasajero(): void
    {
        $alternativa = $this->crearAlternativa([['adulto', 30], ['adulto', 28], ['nino', 8], ['infante', 1]]);
        $tour = $this->crearTour();

        $respuesta = $this->cargar($alternativa, $tour);

        $this->assertSame(200, $respuesta->getStatusCode());
        $lineas = $this->lineas($alternativa);
        $this->assertCount(1, $lineas);
        $linea = $lineas->first();
        $this->assertSame('por_persona', $linea->modo_precio);
        $this->assertSame($tour->id, $linea->tour_origen_id);
        $this->assertEqualsWithDelta(430.0, (float) $linea->precio_venta_snapshot, 0.001); // 150 × 2 + 130, infante 0
        $this->assertEqualsWithDelta(360.0, (float) $linea->costo_snapshot, 0.001); // 120 × 3, infante no suma
        $this->assertSame('20', $linea->tip_afe_igv); // tour sin IGV propio ni Incluye → default de la agencia
        $this->assertEqualsWithDelta(430.0, (float) $alternativa->fresh()->total, 0.001);
    }

    public function test_modo_nuevo_con_un_adulto_extranjero(): void
    {
        $alternativa = $this->crearAlternativa([['adulto', 30], ['adulto', 28], ['adulto', 40, 'extranjero']]);
        $tour = $this->crearTour(['precio_venta_adulto_ext' => 170, 'costo_estimado_persona_ext' => 140]);

        $this->cargar($alternativa, $tour);

        $linea = $this->lineas($alternativa)->first();
        $this->assertEqualsWithDelta(470.0, (float) $linea->precio_venta_snapshot, 0.001);
        $this->assertEqualsWithDelta(380.0, (float) $linea->costo_snapshot, 0.001);
    }

    public function test_tour_en_dolares_se_convierte_a_la_moneda_de_la_cotizacion(): void
    {
        $alternativa = $this->crearAlternativa([['adulto', 30]], '2026-11-15', 'PEN');
        $tour = $this->crearTour(['moneda' => 'USD', 'precio_venta_final' => 100]);

        $this->cargar($alternativa, $tour);

        $linea = $this->lineas($alternativa)->first();
        $this->assertSame('USD', $linea->moneda_costo);
        $this->assertEqualsWithDelta(375.0, (float) $linea->precio_convertido, 0.001);
    }

    public function test_solo_el_super_admin_cambia_el_interruptor(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $controller = app(ConfiguracionAgenciaController::class);

        $rolDueno = Role::create(['name' => 'Administrador '.uniqid(), 'guard_name' => 'api']);
        $dueno = User::factory()->create(['role_id' => $rolDueno->id]);
        $dueno->assignRole($rolDueno);
        $request = new Request(['modo_tours' => 'clasico']);
        $request->setUserResolver(fn () => $dueno);
        $this->assertSame(403, $controller->actualizarModoTours($request)->getStatusCode());
        $this->assertSame('nuevo', ConfiguracionAgencia::first()->modo_tours);

        $rolSoporte = Role::firstOrCreate(['name' => 'Super-Admin', 'guard_name' => 'api']);
        $soporte = User::factory()->create(['role_id' => $rolSoporte->id]);
        $soporte->assignRole($rolSoporte);
        $request = new Request(['modo_tours' => 'clasico']);
        $request->setUserResolver(fn () => $soporte);
        $this->assertSame(200, $controller->actualizarModoTours($request)->getStatusCode());
        $this->assertSame('clasico', ConfiguracionAgencia::first()->modo_tours);
    }

    // ═══════════════════════════════════════════════════════════════
    // Bloqueos
    // ═══════════════════════════════════════════════════════════════

    public function test_tour_solo_itinerario_no_se_puede_cargar(): void
    {
        $alternativa = $this->crearAlternativa([['adulto', 30]]);
        $tour = $this->crearTour(['uso' => PaquetePlantilla::USO_SOLO_ITINERARIO, 'precio_venta_final' => null]);

        $respuesta = $this->cargar($alternativa, $tour);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertStringContainsString('solo de itinerario', $respuesta->getData(true)['message']);
        $this->assertCount(0, AlternativaItem::where('alternativa_id', $alternativa->id)->get());
    }

    public function test_tour_de_venta_sin_precio_no_se_puede_cargar(): void
    {
        $alternativa = $this->crearAlternativa([['adulto', 30]]);
        $tour = $this->crearTour(['precio_venta_final' => null]);

        $respuesta = $this->cargar($alternativa, $tour);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertStringContainsString('no tiene precio', $respuesta->getData(true)['message']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Temporada (§3.12)
    // ═══════════════════════════════════════════════════════════════

    public function test_temporada_del_dia_del_tour(): void
    {
        $this->temporadas([[77, 'Temporada Alta', '2026-07-01', '2026-08-31']]);
        $tour = $this->crearTour();
        PaquetePlantillaPrecio::create(['paquete_plantilla_id' => $tour->id, 'temporada_id' => 77, 'precio_venta_adulto' => 200]);

        // Día 3 de un viaje que arranca el 2026-06-29 = 2026-07-01: dentro.
        $dentro = $this->crearAlternativa([['adulto', 30], ['nino', 8]], '2026-06-29');
        $this->cargar($dentro, $tour, 3);
        $linea = $this->lineas($dentro)->first();
        $this->assertSame(77, $linea->temporada_id);
        $this->assertSame('Temporada Alta', $linea->tour_linea['temporada_nombre']);
        $this->assertSame('2026-07-01', $linea->tour_linea['fecha_tour']);
        $this->assertEqualsWithDelta(400.0, (float) $linea->precio_venta_snapshot, 0.001); // niño vacío en la temporada = adulto

        // Día 1 del mismo viaje = 2026-06-29: fuera de todas → precio base.
        $fuera = $this->crearAlternativa([['adulto', 30], ['nino', 8]], '2026-06-29');
        $this->cargar($fuera, $tour, 1);
        $linea = $this->lineas($fuera)->first();
        $this->assertNull($linea->temporada_id);
        $this->assertEqualsWithDelta(280.0, (float) $linea->precio_venta_snapshot, 0.001);
    }

    public function test_cotizacion_sin_fecha_usa_el_precio_base_con_aviso(): void
    {
        $this->temporadas([[77, 'Temporada Alta', '2026-01-01', '2026-12-31']]);
        $tour = $this->crearTour();
        PaquetePlantillaPrecio::create(['paquete_plantilla_id' => $tour->id, 'temporada_id' => 77, 'precio_venta_adulto' => 200]);
        $alternativa = $this->crearAlternativa([['adulto', 30]], null);

        $respuesta = $this->cargar($alternativa, $tour);

        $this->assertEqualsWithDelta(150.0, (float) $this->lineas($alternativa)->first()->precio_venta_snapshot, 0.001);
        $this->assertStringContainsString('no tiene fecha', implode(' ', $respuesta->getData(true)['avisos']));
    }

    public function test_combo_cada_tour_con_la_temporada_de_su_dia(): void
    {
        $this->temporadas([[88, 'Fiestas Patrias', '2026-07-28', '2026-07-29']]);
        $lamas = $this->crearTour(['nombre' => 'Lamas', 'precio_venta_final' => 100, 'costo_estimado_persona' => 60]);
        $laguna = $this->crearTour(['nombre' => 'Laguna Azul', 'precio_venta_final' => 150, 'costo_estimado_persona' => 90]);
        PaquetePlantillaPrecio::create(['paquete_plantilla_id' => $lamas->id, 'temporada_id' => 88, 'precio_venta_adulto' => 120]);
        PaquetePlantillaPrecio::create(['paquete_plantilla_id' => $laguna->id, 'temporada_id' => 88, 'precio_venta_adulto' => 180]);
        $combo = $this->crearCombo([$lamas, $laguna]);
        $alternativa = $this->crearAlternativa([['adulto', 30]], '2026-07-27');

        $this->cargar($alternativa, $combo); // Lamas día 1 (27-jul, base), Laguna día 2 (28-jul, Fiestas Patrias)

        $lineas = $this->lineas($alternativa);
        $this->assertCount(2, $lineas);
        $this->assertSame([1, 2], $lineas->pluck('dia_referencial')->all());
        $this->assertNull($lineas[0]->temporada_id);
        $this->assertEqualsWithDelta(100.0, (float) $lineas[0]->precio_venta_snapshot, 0.001);
        $this->assertSame(88, $lineas[1]->temporada_id);
        $this->assertEqualsWithDelta(180.0, (float) $lineas[1]->precio_venta_snapshot, 0.001);
        $this->assertNotSame($lineas[0]->carga_tour, $lineas[1]->carga_tour);
        $this->assertSame($lineas[0]->carga_combo, $lineas[1]->carga_combo);
        $this->assertSame($combo->id, $lineas[0]->combo_origen_id);
    }

    public function test_cambio_de_fecha_en_borrador_avisa_y_no_recalcula_solo(): void
    {
        $this->temporadas([[77, 'Temporada Alta', '2026-07-01', '2026-08-31']]);
        $tour = $this->crearTour();
        PaquetePlantillaPrecio::create(['paquete_plantilla_id' => $tour->id, 'temporada_id' => 77, 'precio_venta_adulto' => 200]);
        $alternativa = $this->crearAlternativa([['adulto', 30]], '2026-06-10');
        $this->cargar($alternativa, $tour);
        $this->assertEqualsWithDelta(150.0, (float) $this->lineas($alternativa)->first()->precio_venta_snapshot, 0.001);

        $rolSoporte = Role::firstOrCreate(['name' => 'Super-Admin', 'guard_name' => 'api']);
        $soporte = User::factory()->create(['role_id' => $rolSoporte->id]);
        $soporte->assignRole($rolSoporte);
        $this->actingAs($soporte, 'api');
        $cotizacion = $alternativa->cotizacion;
        $respuesta = app(CotizacionController::class)->update(new Request([
            'cliente_id' => $cotizacion->cliente_id, 'destino' => $cotizacion->destino, 'fecha_viaje_desde' => '2026-07-10',
        ]), (string) $cotizacion->id);

        $avisos = $respuesta->getData(true)['temporadas_desactualizadas'];
        $this->assertCount(1, $avisos);
        $this->assertSame('Temporada Alta', $avisos[0]['temporada_nueva']);
        $this->assertEqualsWithDelta(150.0, (float) $this->lineas($alternativa)->first()->precio_venta_snapshot, 0.001); // no se recalculó

        // "Recalcular precios por temporada", a pedido del vendedor.
        $recalculo = app(AlternativaItemController::class)->recalcularTemporadas((string) $alternativa->id);
        $this->assertSame(1, $recalculo->getData(true)['recalculadas']);
        $linea = $this->lineas($alternativa)->first();
        $this->assertSame(77, $linea->temporada_id);
        $this->assertEqualsWithDelta(200.0, (float) $linea->precio_venta_snapshot, 0.001);
        $this->assertSame([], app(TourLineaService::class)->temporadasDesactualizadas($alternativa->fresh()));
    }

    // ═══════════════════════════════════════════════════════════════
    // IGV mezclado (N14)
    // ═══════════════════════════════════════════════════════════════

    public function test_igv_mezclado_una_linea_por_grupo_con_reparto_exacto(): void
    {
        $tour = $this->crearTour(['precio_venta_final' => 100, 'precio_venta_nino' => null, 'costo_estimado_persona' => null]);
        $this->incluye($tour, 'Bote', 40, '20', 'amazonia');
        $this->incluye($tour, 'Almuerzo', 20, '20', 'amazonia');
        $this->incluye($tour, 'Transporte', 20, '10', 'nacional');
        $alternativa = $this->crearAlternativa([['adulto', 30]]);

        $this->cargar($alternativa, $tour);

        $lineas = $this->lineas($alternativa);
        $this->assertCount(2, $lineas);
        $exonerada = $lineas->firstWhere('tip_afe_igv', '20');
        $gravada = $lineas->firstWhere('tip_afe_igv', '10');
        $this->assertEqualsWithDelta(75.0, (float) $exonerada->precio_venta_snapshot, 0.001);
        $this->assertEqualsWithDelta(25.0, (float) $gravada->precio_venta_snapshot, 0.001);
        $this->assertEqualsWithDelta(60.0, (float) $exonerada->costo_snapshot, 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $gravada->costo_snapshot, 0.001);
        $this->assertSame($exonerada->carga_tour, $gravada->carga_tour);
        $this->assertSame('amazonia', $exonerada->destino_tributario);
        $this->assertCount(2, $exonerada->desglose_costo_snapshot);
        $this->assertSame(1, $exonerada->desglose_costo_snapshot[0]['pax_nacional']);
        $this->assertStringEndsWith('(exonerado)', ReservaController::resolverNombreItem($exonerada->fresh()));
    }

    public function test_reparto_con_centavos_impares_suma_exacto(): void
    {
        // 3 grupos de costo igual: 100.01 → el centavo sobrante va al primero de mayor costo.
        $this->assertSame([33.35, 33.33, 33.33], TourLineaService::repartir(100.01, [10, 10, 10]));
        $this->assertSame([75.0, 25.0], TourLineaService::repartir(100, [60, 20]));
        $partes = TourLineaService::repartir(99.99, [7, 13, 5]);
        $this->assertEqualsWithDelta(99.99, array_sum($partes), 0.0001);
        $this->assertSame([0.0, 0.0], TourLineaService::repartir(0, [1, 2]));
    }

    public function test_igv_mezclado_sin_costo_en_un_servicio_bloquea(): void
    {
        $tour = $this->crearTour();
        $this->incluye($tour, 'Bote', 40, '20', 'amazonia');
        $this->incluye($tour, 'Entrada', null, '10', 'nacional');
        $alternativa = $this->crearAlternativa([['adulto', 30]]);

        $respuesta = $this->cargar($alternativa, $tour);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertStringContainsString('Entrada', $respuesta->getData(true)['message']);
        $this->assertCount(0, AlternativaItem::where('alternativa_id', $alternativa->id)->get());
    }

    // ═══════════════════════════════════════════════════════════════
    // Pasajeros (§3.3) y E2
    // ═══════════════════════════════════════════════════════════════

    private function actualizarPasajeros(Alternativa $alternativa, array $nuevos)
    {
        $pasajeros = CotizacionPasajero::where('cotizacion_id', $alternativa->cotizacion_id)->orderBy('id')->get()
            ->map(fn ($p) => ['id' => $p->id, 'edad' => $p->edad])->all();
        foreach ($nuevos as [$edad, $residencia]) {
            $pasajeros[] = ['edad' => $edad, 'residencia' => $residencia];
        }

        return app(CotizacionController::class)->actualizarPasajeros(new Request(['pasajeros' => $pasajeros]), (string) $alternativa->cotizacion_id);
    }

    public function test_agregar_pasajeros_reprecia_la_linea_de_tour(): void
    {
        $tour = $this->crearTour(['precio_venta_final' => 100, 'precio_venta_nino' => null, 'costo_estimado_persona' => null]);
        $this->incluye($tour, 'Bote', 60, '20', 'amazonia');
        $this->incluye($tour, 'Transporte', 20, '10', 'nacional');
        $alternativa = $this->crearAlternativa([['adulto', 30]]);
        $this->cargar($alternativa, $tour);

        $respuesta = $this->actualizarPasajeros($alternativa, [[35, 'nacional']]);

        $this->assertSame(200, $respuesta->getStatusCode());
        $lineas = $this->lineas($alternativa);
        $this->assertEqualsWithDelta(150.0, (float) $lineas->firstWhere('tip_afe_igv', '20')->precio_venta_snapshot, 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $lineas->firstWhere('tip_afe_igv', '10')->precio_venta_snapshot, 0.001);
        $this->assertEqualsWithDelta(120.0, (float) $lineas->firstWhere('tip_afe_igv', '20')->costo_snapshot, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $alternativa->fresh()->total, 0.001);
    }

    public function test_precio_cambiado_a_mano_no_se_pisa_al_cambiar_pasajeros(): void
    {
        $alternativa = $this->crearAlternativa([['adulto', 30]]);
        $tour = $this->crearTour();
        $this->cargar($alternativa, $tour);
        $carga = $this->lineas($alternativa)->first()->carga_tour;

        app(AlternativaItemController::class)->precioCarga(new Request(['precio' => 140]), (string) $alternativa->id, $carga);
        $respuesta = $this->actualizarPasajeros($alternativa, [[35, 'nacional']]);

        $linea = $this->lineas($alternativa)->first();
        $this->assertTrue($linea->precio_manual);
        $this->assertEqualsWithDelta(140.0, (float) $linea->precio_convertido, 0.001); // precio del vendedor intacto
        $this->assertEqualsWithDelta(240.0, (float) $linea->costo_snapshot, 0.001); // el costo sí sigue a los pasajeros
        $this->assertNotEmpty($respuesta->getData(true)['items_para_revisar']);
    }

    public function test_e2_el_costo_de_una_linea_por_persona_de_proveedor_sigue_a_los_pasajeros(): void
    {
        $alternativa = $this->crearAlternativa([['adulto', 30], ['infante', 1]]);
        $tarifa = $this->tarifaCompartida(20, 50);

        $respuesta = app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'proveedor', 'proveedor_tarifa_id' => $tarifa->id, 'modo_precio' => 'por_persona',
        ]), (string) $alternativa->id);
        $item = AlternativaItem::find($respuesta->getData(true)['alternativa_item']['id']);
        $this->assertEqualsWithDelta(20.0, (float) $item->costo_snapshot, 0.001); // total de la línea, sin infante

        $this->actualizarPasajeros($alternativa, [[40, 'nacional'], [45, 'nacional']]);

        $item->refresh();
        $this->assertEqualsWithDelta(150.0, (float) $item->precio_venta_snapshot, 0.001);
        $this->assertEqualsWithDelta(60.0, (float) $item->costo_snapshot, 0.001);
    }

    // ═══════════════════════════════════════════════════════════════
    // Combos (N7, E3)
    // ═══════════════════════════════════════════════════════════════

    public function test_descuento_del_combo_llega_a_la_cotizacion_y_el_reparto_suma_exacto(): void
    {
        $lamas = $this->crearTour(['nombre' => 'Lamas', 'precio_venta_final' => 100, 'precio_venta_nino' => null]);
        $laguna = $this->crearTour(['nombre' => 'Laguna Azul', 'precio_venta_final' => 200, 'precio_venta_nino' => null]);
        $combo = $this->crearCombo([$lamas, $laguna], ['descuento_tipo' => 'porcentaje', 'descuento_valor' => 10]);
        $alternativa = $this->crearAlternativa([['adulto', 30], ['adulto', 28]]);

        $this->cargar($alternativa, $combo);

        $lineas = $this->lineas($alternativa);
        // Lista 200 + 400 = 600; −10 % = 540, repartido 1:2 → 180 / 360.
        $this->assertEqualsWithDelta(180.0, (float) $lineas[0]->precio_venta_snapshot, 0.001);
        $this->assertEqualsWithDelta(360.0, (float) $lineas[1]->precio_venta_snapshot, 0.001);
        $this->assertEqualsWithDelta(540.0, (float) $alternativa->fresh()->total, 0.001);
    }

    public function test_descuento_fijo_del_combo_es_por_persona_que_paga(): void
    {
        $lamas = $this->crearTour(['nombre' => 'Lamas', 'precio_venta_final' => 100, 'precio_venta_nino' => null]);
        $laguna = $this->crearTour(['nombre' => 'Laguna Azul', 'precio_venta_final' => 50, 'precio_venta_nino' => null]);
        $combo = $this->crearCombo([$lamas, $laguna], ['descuento_tipo' => 'monto', 'descuento_valor' => 10]);
        $alternativa = $this->crearAlternativa([['adulto', 30], ['adulto', 28], ['infante', 1]]);

        $this->cargar($alternativa, $combo);

        // Lista 200 + 100 = 300; − 10 × 2 que pagan = 280, repartido 2:1.
        $lineas = $this->lineas($alternativa);
        $this->assertEqualsWithDelta(280.0, $lineas->sum(fn ($l) => (float) $l->precio_venta_snapshot), 0.001);
        $this->assertEqualsWithDelta(186.67, (float) $lineas[0]->precio_venta_snapshot, 0.001);
        $this->assertEqualsWithDelta(93.33, (float) $lineas[1]->precio_venta_snapshot, 0.001);
    }

    // ═══════════════════════════════════════════════════════════════
    // Mover, quitar, duplicar
    // ═══════════════════════════════════════════════════════════════

    public function test_las_lineas_de_una_carga_se_mueven_y_se_quitan_juntas(): void
    {
        $tour = $this->crearTour(['costo_estimado_persona' => null]);
        $this->incluye($tour, 'Bote', 60, '20', 'amazonia');
        $this->incluye($tour, 'Transporte', 20, '10', 'nacional');
        $alternativa = $this->crearAlternativa([['adulto', 30]]);
        $this->cargar($alternativa, $tour);
        $lineas = $this->lineas($alternativa);

        app(AlternativaItemController::class)->moverCarga(new Request(['carga' => $lineas[0]->carga_tour, 'dia_referencial' => 3]), (string) $alternativa->id);
        $this->assertSame([3, 3], $this->lineas($alternativa)->pluck('dia_referencial')->all());

        app(AlternativaItemController::class)->destroy((string) $lineas[1]->id);
        $this->assertCount(0, $this->lineas($alternativa));
        $this->assertEqualsWithDelta(0.0, (float) $alternativa->fresh()->total, 0.001);
    }

    public function test_duplicar_alternativa_copia_la_linea_con_cargas_propias(): void
    {
        $alternativa = $this->crearAlternativa([['adulto', 30]]);
        $this->cargar($alternativa, $this->crearTour());
        $original = $this->lineas($alternativa)->first();

        $respuesta = app(AlternativaController::class)->duplicar((string) $alternativa->id);
        $nuevaId = $respuesta->getData(true)['alternativa']['id'];
        $copia = AlternativaItem::where('alternativa_id', $nuevaId)->where('origen_tipo', 'tour')->first();

        $this->assertNotNull($copia);
        $this->assertNotSame($original->carga_tour, $copia->carga_tour);
        $this->assertSame($original->tour_linea, $copia->tour_linea);
        $this->assertSame($original->desglose_costo_snapshot, $copia->desglose_costo_snapshot);
    }

    // ═══════════════════════════════════════════════════════════════
    // Reserva, lectura de los dos formatos y facturación
    // ═══════════════════════════════════════════════════════════════

    private function alternativaConLosDosFormatos(): array
    {
        $tour = $this->crearTour(['costo_estimado_persona' => null]);
        $this->incluye($tour, 'Bote', 60, '20', 'amazonia');
        $this->incluye($tour, 'Transporte', 20, '10', 'nacional');
        $alternativa = $this->crearAlternativa([['adulto', 30]]);
        $this->cargar($alternativa, $tour);

        // Línea vieja (desarmada) en la misma alternativa.
        $tarifa = $this->tarifaCompartida(20, 50);
        AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor', 'proveedor_tarifa_id' => $tarifa->id,
            'dia_referencial' => 2, 'modo_precio' => 'por_persona', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 20, 'precio_venta_snapshot' => 50, 'precio_convertido' => 50,
            'tip_afe_igv' => '20', 'destino_tributario' => 'amazonia',
        ]);

        return [$alternativa, $tour];
    }

    public function test_al_aceptar_se_copian_carga_desglose_e_igv(): void
    {
        [$alternativa] = $this->alternativaConLosDosFormatos();

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        $items = ReservaItem::where('reserva_id', $reserva->id)->with('alternativaItem')->get();
        $deTour = $items->filter(fn ($i) => $i->alternativaItem->origen_tipo === 'tour');
        $this->assertCount(2, $deTour);
        foreach ($deTour as $item) {
            $this->assertSame($item->alternativaItem->carga_tour, $item->carga_tour);
            $this->assertSame($item->alternativaItem->tip_afe_igv, $item->tip_afe_igv);
            $this->assertSame($item->alternativaItem->desglose_costo_snapshot, $item->desglose_costo_snapshot);
            $this->assertSame('2026-11-15', $item->fecha->toDateString());
        }
        // Desde la fase 2a la línea de tour se engancha a su salida; las dos
        // líneas de IGV de la carga caen en la misma.
        $this->assertNotNull($deTour->first()->salida_operativa_id);
        $this->assertCount(1, $deTour->pluck('salida_operativa_id')->unique());
    }

    public function test_reporte_operativo_y_pdf_leen_los_dos_formatos(): void
    {
        [$alternativa, $tour] = $this->alternativaConLosDosFormatos();
        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        $reporte = app(ReporteOperativoController::class)->index(new Request(['fecha_desde' => '2026-11-15', 'fecha_hasta' => '2026-11-16']))->getData(true);
        $filas = collect($reporte['filas'] ?? $reporte['data']['filas'] ?? [])->where('reserva_id', $reserva->id);
        $filaTour = $filas->firstWhere('origen_tipo', 'tour');
        $this->assertNotNull($filaTour);
        $this->assertSame($tour->nombre, $filaTour['servicio']); // sin "(gravado)": una sola fila por tour
        $this->assertSame(1, $filas->where('origen_tipo', 'tour')->count());
        $this->assertFalse($filaTour['sin_guia']);
        $this->assertSame(1, $filas->where('origen_tipo', 'proveedor')->count());

        // "Incluye" del PDF (C5): sale de la lista del tour, no del nombre de la línea.
        $incluye = new \ReflectionMethod(AlternativaPdfService::class, 'incluyePorDestino');
        $bloques = $incluye->invoke(app(AlternativaPdfService::class), Alternativa::with(['destinos', 'items.tourOrigen', 'items.proveedorTarifa.proveedorServicio.destinoServicio.servicio'])->find($alternativa->id));
        $nombres = collect($bloques)->flatMap(fn ($b) => collect($b['nombres']))->all();
        $this->assertTrue(collect($nombres)->contains(fn ($n) => str_starts_with($n, 'Bote')), json_encode($nombres));
        $this->assertTrue(collect($nombres)->contains(fn ($n) => str_starts_with($n, 'Transporte')));
        $this->assertNotContains($tour->nombre, $nombres);
    }

    public function test_facturar_una_linea_de_tour_sin_mezcla_la_clasifica_tour(): void
    {
        $tenant = new \App\Models\Tenant();
        $tenant->id = 'test-tenant-'.uniqid();
        $tenant->facturacion_habilitada = true;
        app(\Stancl\Tenancy\Tenancy::class)->tenant = $tenant;
        $this->usuarioQueFactura();

        $alternativa = $this->crearAlternativa([['adulto', 30], ['adulto', 28]]);
        // Gravado nacional: un tour exonerado (Amazonía) exige además confirmar
        // cada ítem a mano antes de facturar (regla existente, no de la 1b).
        $this->cargar($alternativa, $this->crearTour(['tip_afe_igv' => '10', 'destino_tributario' => 'nacional']));
        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        // Desde la 1c la línea de tour entra sola con la parte de los pasajeros
        // elegidos (no se agrega como ítem sin asignar).
        $preview = app(ReservaFacturacionController::class)->prepararFactura(new Request([
            'pasajero_ids' => ReservaPasajero::where('reserva_id', $reserva->id)->pluck('id')->all(),
        ]), (string) $reserva->id);

        $this->assertSame(200, $preview->getStatusCode(), json_encode($preview->getData(true)));
        $cuerpo = $preview->getData(true);
        $this->assertFalse($cuerpo['bloqueado_tributario']);
        $this->assertSame(['TOUR'], collect($cuerpo['grupos_propuestos'])->pluck('categoria')->all());
        $this->assertEqualsWithDelta(300.0, (float) $cuerpo['total'], 0.001);
    }

    // ═══════════════════════════════════════════════════════════════
    // Apoyo
    // ═══════════════════════════════════════════════════════════════

    private function tarifaCompartida(float $costo, float $venta): ProveedorTarifa
    {
        $proveedor = Proveedor::create(['razon_social' => 'Proveedor Test '.uniqid(), 'estado' => true]);
        $proveedorServicio = ProveedorServicio::create(['proveedor_id' => $proveedor->id, 'destino_servicio_id' => $this->servicio('Transporte')->id]);

        return ProveedorTarifa::create([
            'proveedor_servicio_id' => $proveedorServicio->id, 'tipo_tarifa' => 'publica', 'modalidad' => 'compartido',
            'moneda' => 'PEN', 'precio_costo' => $costo, 'margen_tipo' => 'porcentaje', 'margen_valor' => 20,
            'precio_venta_adulto' => $venta, 'vigente_desde' => HoraPeru::hoyTexto(),
            'tip_afe_igv' => '20', 'destino_tributario' => 'amazonia',
        ]);
    }

    private function usuarioQueFactura(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $branch = Branch::create(['name' => 'Sede Test 1b', 'is_active' => true]);
        SerieComprobante::create([
            'branch_id' => $branch->id, 'tipo_comprobante_codigo' => '01', 'moneda' => 'PEN', 'serie' => 'F1B1',
            'correlativo_actual' => 0, 'correlativo_inicial' => 1, 'fecha_inicio' => HoraPeru::hoyTexto(), 'activo' => true,
        ]);
        $role = Role::create(['name' => 'rol-1b-'.uniqid(), 'guard_name' => 'api']);
        $user = User::factory()->create(['branch_id' => $branch->id, 'role_id' => $role->id]);
        $role->givePermissionTo(Permission::firstOrCreate(['name' => 'emitir_factura', 'guard_name' => 'api']));
        $user->assignRole($role);
        Auth::guard('api')->setUser($user->fresh());
    }
}
