<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReporteComercialController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\CrmMotivoResultado;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaModificacion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\GiroActual;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Stancl\Tenancy\Tenancy;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// T-062 (CRM C6b) — «Reportes comerciales». Postgres real (sistemafe_test_migrations), transacción
// por test revertida; mismo armado que SeguimientoCotizacionTest. «Hoy» es el martes 10/11/2026 en
// Perú (12:00); el rango por defecto es el mes en curso.
class ReportesComercialesTest extends TestCase
{
    private int $origenWhatsapp;

    private int $origenFacebook;

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

        Carbon::setTestNow(Carbon::parse('2026-11-10 17:00:00', 'UTC'));

        DB::table('roles')->insert([
            'id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");

        $tenant = new Tenant();
        $tenant->id = 'test-tenant-' . uniqid();
        app(Tenancy::class)->tenant = $tenant;
        $this->app->instance(GiroActual::class, new GiroActual('agencia_viajes'));

        $this->origenWhatsapp = DB::table('crm_origenes_contacto')->insertGetId(['nombre' => 'T062 WhatsApp', 'activo' => true, 'orden' => 90, 'created_at' => now(), 'updated_at' => now()]);
        $this->origenFacebook = DB::table('crm_origenes_contacto')->insertGetId(['nombre' => 'T062 Facebook', 'activo' => false, 'orden' => 91, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        app(Tenancy::class)->tenant = null;
        Carbon::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private function usuario(array $permisos = ['reportes_comerciales.ver', 'cotizaciones.ver_todas'], bool $sesion = true, string $nombre = 'Ana', array $extra = []): User
    {
        $user = User::factory()->create(array_merge(['email' => 'vendedor-' . uniqid() . '@agencia.test', 'name' => $nombre], $extra));
        foreach ($permisos as $permiso) {
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => $permiso]);
            $user->givePermissionTo($permiso);
        }
        if ($sesion) {
            Auth::guard('api')->setUser($user->fresh());
        }

        return $user;
    }

    private function cotizacion(array $extra = []): Cotizacion
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10_000_000, 99_999_999),
            'full_name' => 'T062 Cliente', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = DB::table('cotizaciones')->insertGetId(array_merge([
            'codigo_prefijo' => 'TEST', 'codigo' => 'T062-' . uniqid(), 'cliente_id' => $clienteId,
            'vendedor_id' => auth('api')->id(), 'destino' => 'Tarapoto',
            'origen' => Cotizacion::ORIGEN_COTIZADOR, 'origen_contacto_id' => $this->origenWhatsapp,
            'created_at' => '2026-11-05 15:00:00', 'updated_at' => now(),
        ], $extra));

        return Cotizacion::findOrFail($id);
    }

    private function alternativa(Cotizacion $c, string $estado, array $extra = []): Alternativa
    {
        return Alternativa::create(array_merge([
            'cotizacion_id' => $c->id, 'nombre' => 'Alternativa ' . uniqid(), 'estado' => $estado,
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia', 'total' => 0,
        ], $extra));
    }

    private function reserva(Alternativa $a, string $estado = 'activa'): Reserva
    {
        return Reserva::create([
            'alternativa_id' => $a->id, 'codigo' => 'R-' . uniqid(), 'estado' => $estado,
            'fecha_viaje_desde' => '2026-12-01', 'fecha_viaje_hasta' => '2026-12-05',
        ]);
    }

    private function motivo(string $tipo, string $nombre): int
    {
        return CrmMotivoResultado::where('tipo', $tipo)->where('nombre', $nombre)->value('id');
    }

    private function ganada(array $extra = [], float $total = 1000, string $moneda = 'PEN', ?int $motivo = null, bool $enviada = true): Cotizacion
    {
        $c = $this->cotizacion($extra + ['resultado_motivo_id' => $motivo]);
        $a = $this->alternativa($c, 'aceptada', ['total' => $total, 'moneda_cotizacion' => $moneda]
            + ($enviada ? ['fecha_envio' => '2026-11-05 19:00:00'] : []));
        $this->reserva($a);

        return $c;
    }

    private function perdida(array $extra = [], ?string $motivo = 'Precio', bool $enviada = false): Cotizacion
    {
        $c = $this->cotizacion($extra + [
            'perdida_en' => '2026-11-08 15:00:00',
            'resultado_motivo_id' => $motivo ? $this->motivo('perdida', $motivo) : null,
        ]);
        $this->alternativa($c, 'descartada', $enviada ? ['fecha_envio' => '2026-11-06 15:00:00'] : []);

        return $c;
    }

    private function abierta(array $extra = [], string $estado = 'borrador', array $alt = []): Cotizacion
    {
        $c = $this->cotizacion($extra);
        $this->alternativa($c, $estado, $alt);

        return $c;
    }

    private function reporte(array $params = []): array
    {
        return app(ReporteComercialController::class)->index(new Request($params))->getData(true)['data'];
    }

    /** 4 ganadas, 3 perdidas y 3 abiertas: el caso de CA1. */
    private function casoBase(): void
    {
        $this->ganada();
        $this->ganada();
        $this->ganada(enviada: false); // aceptada desde borrador: cuenta como enviada
        $this->ganada(['origen_contacto_id' => $this->origenFacebook]);
        $this->perdida();
        $this->perdida(enviada: true);
        $this->perdida(['origen_contacto_id' => null]);
        $this->abierta();
        $this->abierta([], 'enviada', ['fecha_envio' => '2026-11-05 17:00:00', 'fecha_vencimiento' => '2026-11-20 05:00:00']);
        $this->abierta(['origen_contacto_id' => null], 'enviada', ['fecha_envio' => '2026-11-05 16:00:00', 'fecha_vencimiento' => '2026-11-06 05:00:00']); // vencida
    }

    // ── CA1: embudo ──────────────────────────────────────────────────

    public function test_ca1_embudo_con_4_ganadas_3_perdidas_y_3_abiertas(): void
    {
        $this->usuario();
        $this->casoBase();

        $e = $this->reporte()['embudo'];

        $this->assertSame(10, $e['consultas']);
        $this->assertSame(4, $e['ganadas']);
        $this->assertSame(3, $e['perdidas']);
        $this->assertSame(3, $e['abiertas']);
        $this->assertSame(0, $e['anuladas']);
        // 4 ganadas (una sin fecha de envío) + 1 perdida enviada + 2 abiertas enviadas.
        $this->assertSame(7, $e['enviadas']);
        $this->assertEquals(40.0, $e['conversion_pct']);
        $this->assertEquals(['enviadas' => 70.0, 'ganadas' => 40.0, 'perdidas' => 30.0, 'abiertas' => 30.0, 'anuladas' => 0.0], $e['pct']);
    }

    public function test_venta_directa_y_modificacion_no_cuentan_y_el_rango_es_por_dia_de_peru(): void
    {
        $this->usuario();
        $this->abierta();
        $this->abierta(['origen' => Cotizacion::ORIGEN_VENTA_DIRECTA]);
        $this->abierta(['origen' => Cotizacion::ORIGEN_MODIFICACION]);
        // 01/11 03:00 UTC = 31/10 22:00 en Perú: fuera de noviembre.
        $this->abierta(['created_at' => '2026-11-01 03:00:00']);
        // 01/11 05:00 UTC = 01/11 00:00 en Perú: dentro.
        $this->abierta(['created_at' => '2026-11-01 05:00:00']);

        $this->assertSame(2, $this->reporte()['embudo']['consultas']);
        $this->assertSame(1, $this->reporte(['desde' => '2026-10-31', 'hasta' => '2026-10-31'])['embudo']['consultas']);
    }

    public function test_reserva_cancelada_es_anulada_y_no_suma_al_monto(): void
    {
        $this->usuario();
        $this->ganada([], 500);
        $c = $this->cotizacion();
        $this->reserva($this->alternativa($c, 'aceptada', ['total' => 800]), 'cancelada');

        $e = $this->reporte()['embudo'];

        $this->assertSame(1, $e['ganadas']);
        $this->assertSame(1, $e['anuladas']);
        $this->assertSame(2, $e['enviadas']);
        $this->assertEquals(50.0, $e['conversion_pct']);
        $this->assertSame('500.00', $e['monto']['PEN']);
    }

    public function test_reserva_modificada_es_ganada_con_el_total_de_la_version_nueva(): void
    {
        $user = $this->usuario();
        $original = $this->cotizacion();
        $vendida = $this->alternativa($original, Alternativa::ESTADO_REEMPLAZADA, ['total' => 1000]);
        $copia = $this->cotizacion(['origen' => Cotizacion::ORIGEN_MODIFICACION]);
        $nueva = $this->alternativa($copia, 'aceptada', ['total' => 1300]);
        $reserva = $this->reserva($nueva);
        ReservaModificacion::create([
            'reserva_id' => $reserva->id, 'cotizacion_id' => $copia->id, 'alternativa_vendida_id' => $vendida->id,
            'alternativa_nueva_id' => $nueva->id, 'estado' => ReservaModificacion::ESTADO_APLICADA, 'creado_por' => $user->id,
        ]);

        $e = $this->reporte()['embudo'];

        $this->assertSame(1, $e['consultas']);
        $this->assertSame(1, $e['ganadas']);
        $this->assertSame('1300.00', $e['monto']['PEN']);
    }

    // ── CA2: origen y vendedor suman lo mismo que el embudo ──────────

    public function test_ca2_por_origen_y_por_vendedor_suman_lo_mismo_que_el_embudo(): void
    {
        $ana = $this->usuario(nombre: 'Ana');
        $this->casoBase();
        $luis = $this->usuario(nombre: 'Luis', extra: ['state' => User::STATE_INACTIVO]);
        $this->ganada([], 200);
        $this->perdida(['vendedor_id' => null]);

        $this->usuario(nombre: 'Jefa'); // quien mira el reporte
        $r = $this->reporte();

        foreach (['por_origen', 'por_vendedor'] as $grupo) {
            foreach (['consultas', 'enviadas', 'ganadas', 'perdidas', 'abiertas', 'anuladas'] as $clave) {
                $this->assertSame($r['embudo'][$clave], array_sum(array_column($r[$grupo], $clave)), "$grupo.$clave");
            }
            $this->assertSame((float) $r['embudo']['monto']['PEN'], array_sum(array_map(fn ($f) => (float) $f['monto']['PEN'], $r[$grupo])), "$grupo.monto");
        }

        $porOrigen = collect($r['por_origen'])->keyBy('nombre');
        $this->assertSame(['Sin origen', 'T062 Facebook', 'T062 WhatsApp'], $porOrigen->keys()->sort()->values()->all());
        $this->assertSame(2, $porOrigen['Sin origen']['consultas']);
        $this->assertTrue($porOrigen['T062 Facebook']['inactivo']);
        $this->assertEquals(100.0, $porOrigen['T062 Facebook']['conversion_pct']);

        $porVendedor = collect($r['por_vendedor'])->keyBy('id');
        $this->assertSame(10, $porVendedor[$ana->id]['consultas']);
        $this->assertFalse($porVendedor[$ana->id]['inactivo']);
        $this->assertTrue($porVendedor[$luis->id]['inactivo']);
        $this->assertSame('Sin vendedor', collect($r['por_vendedor'])->firstWhere('id', null)['nombre']);
    }

    public function test_filtros_por_vendedor_y_origen(): void
    {
        $ana = $this->usuario(nombre: 'Ana');
        $this->casoBase();
        $this->usuario(nombre: 'Luis');
        $this->ganada();

        $this->assertSame(10, $this->reporte(['vendedor_id' => (string) $ana->id])['embudo']['consultas']);
        $this->assertSame(1, $this->reporte(['origen_contacto_id' => (string) $this->origenFacebook])['embudo']['consultas']);
        $this->assertSame(2, $this->reporte(['origen_contacto_id' => 'sin'])['embudo']['consultas']);
        $this->assertSame(0, $this->reporte(['vendedor_id' => 'sin'])['embudo']['consultas']);
    }

    // ── CA3: montos por moneda, sin mezclar ──────────────────────────

    public function test_ca3_montos_por_moneda_sin_convertir(): void
    {
        $this->usuario();
        $this->ganada([], 1000.10);
        $this->ganada([], 250.25);
        $this->ganada([], 300.50, 'USD');
        $this->ganada(['origen_contacto_id' => $this->origenFacebook], 99.99, 'USD');
        $this->abierta([], 'enviada', ['total' => 5000, 'fecha_envio' => '2026-11-05 17:00:00']); // no vendida

        $r = $this->reporte();

        $this->assertSame(['PEN' => '1250.35', 'USD' => '400.49'], $r['embudo']['monto']);
        $porOrigen = collect($r['por_origen'])->keyBy('nombre');
        $this->assertSame(['PEN' => '1250.35', 'USD' => '300.50'], $porOrigen['T062 WhatsApp']['monto']);
        $this->assertSame(['PEN' => '0.00', 'USD' => '99.99'], $porOrigen['T062 Facebook']['monto']);
    }

    // ── CA4: número fijo de consultas ────────────────────────────────

    public function test_ca4_mismas_consultas_con_5_que_con_25_cotizaciones(): void
    {
        $this->usuario();
        $contar = function (): int {
            app(ReporteComercialController::class)->index(new Request([])); // calienta permisos y caché
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(ReporteComercialController::class)->index(new Request([]));
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        for ($i = 0; $i < 5; $i++) {
            $this->ganada();
        }
        $con5 = $contar();
        for ($i = 0; $i < 20; $i++) {
            $i % 2 ? $this->perdida() : $this->abierta();
        }
        $con25 = $contar();

        $this->assertSame($con5, $con25);
        $this->assertLessThanOrEqual(4, $con25);
    }

    // ── Motivos y tiempo de respuesta ────────────────────────────────

    public function test_motivos_con_porcentaje_y_sin_motivo(): void
    {
        $this->usuario();
        $rapidez = $this->motivo('ganada', 'Rapidez de respuesta');
        $this->ganada(motivo: $rapidez);
        $this->ganada(motivo: $rapidez);
        $this->ganada();
        $this->ganada();
        $this->perdida();
        $this->perdida();
        $this->perdida([], 'No respondió');

        $m = $this->reporte()['motivos'];

        $this->assertEquals([
            ['id' => $rapidez, 'nombre' => 'Rapidez de respuesta', 'cantidad' => 2, 'pct' => 50.0],
            ['id' => null, 'nombre' => 'Sin motivo', 'cantidad' => 2, 'pct' => 50.0],
        ], $m['ganada']);
        $this->assertSame(['Precio', 'No respondió'], array_column($m['perdida'], 'nombre'));
        $this->assertEquals([66.7, 33.3], array_column($m['perdida'], 'pct'));
    }

    public function test_tiempo_de_respuesta_es_la_mediana_de_horas_hasta_el_primer_envio(): void
    {
        $this->usuario();
        // Creadas 05/11 15:00 UTC; primer envío a las 2, 4 y 10 horas.
        foreach (['2026-11-05 17:00:00', '2026-11-05 19:00:00', '2026-11-06 01:00:00'] as $envio) {
            $c = $this->cotizacion();
            $this->alternativa($c, 'enviada', ['fecha_envio' => $envio]);
            $this->alternativa($c, 'enviada', ['fecha_envio' => '2026-11-07 15:00:00']); // reenvío posterior: no cuenta
        }
        $this->ganada(enviada: false); // sin fecha de envío real: fuera de la mediana

        $t = $this->reporte()['tiempo_respuesta'];

        $this->assertEquals(4.0, $t['mediana_horas']);
        $this->assertSame(3, $t['cotizaciones_con_envio']);
    }

    // ── Seguridad y validación ───────────────────────────────────────

    public function test_sin_ver_todas_solo_ve_sus_cotizaciones(): void
    {
        $this->usuario(nombre: 'Otro');
        $this->ganada();
        $this->ganada();
        $yo = $this->usuario(['reportes_comerciales.ver'], nombre: 'Yo');
        $this->perdida();

        $r = $this->reporte();
        $this->assertSame(1, $r['embudo']['consultas']);
        $this->assertSame([$yo->id], array_column($r['por_vendedor'], 'id'));

        $opciones = app(ReporteComercialController::class)->opciones()->getData(true);
        $this->assertSame([$yo->id], array_column($opciones['vendedores'], 'id'));
    }

    public function test_rango_validado_maximo_12_meses(): void
    {
        $this->usuario();

        $this->assertSame(0, $this->reporte(['desde' => '2025-11-11', 'hasta' => '2026-11-10'])['embudo']['consultas']);

        foreach ([
            ['desde' => '2025-11-10', 'hasta' => '2026-11-10'],
            ['desde' => '2026-11-10', 'hasta' => '2026-11-01'],
            ['desde' => '10/11/2026'],
            ['vendedor_id' => 'todos'],
        ] as $params) {
            try {
                $this->reporte($params);
                $this->fail('Debió rechazar ' . json_encode($params));
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_ruta_exige_el_permiso_y_otro_giro_da_404(): void
    {
        $ruta = app('router')->getRoutes()->getByAction(ReporteComercialController::class . '@index');
        $this->assertContains('permission:reportes_comerciales.ver', $ruta->gatherMiddleware());

        $this->usuario();
        $this->app->instance(GiroActual::class, new GiroActual('retail'));
        try {
            $this->reporte();
            $this->fail('Debió responder 404');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    // ── Excel ────────────────────────────────────────────────────────

    public function test_excel_trae_las_secciones_con_los_numeros(): void
    {
        $this->usuario();
        $this->casoBase();

        $respuesta = app(ReporteComercialController::class)->export(new Request(['origen_contacto_id' => 'sin']));
        $hojaExcel = IOFactory::load($respuesta->getFile()->getPathname())->getActiveSheet();
        $texto = collect($hojaExcel->toArray())->map(fn ($fila) => implode('|', array_map(fn ($v) => (string) $v, array_filter($fila, fn ($v) => $v !== null))))->all();

        // Los ceros se escriben (no quedan vacíos) y la negrita cae en los títulos de sección.
        $this->assertContains('Ganadas|0|0', $texto);
        $filaEmbudo = array_search('Embudo', $texto, true) + 1;
        $this->assertTrue($hojaExcel->getStyle('A' . $filaEmbudo)->getFont()->getBold());
        $this->assertFalse($hojaExcel->getStyle('A' . ($filaEmbudo + 2))->getFont()->getBold()); // fila «Consultas»

        $this->assertStringContainsString('reportes_comerciales_del_01-11-2026_al_10-11-2026.xlsx', $respuesta->headers->get('content-disposition'));
        $this->assertContains('Origen: sin origen', $texto);
        $this->assertContains('Consultas|2|100', $texto);
        foreach (['Embudo', 'Por origen', 'Por vendedor', 'Motivos de ganancia', 'Motivos de pérdida', 'Tiempo de respuesta'] as $seccion) {
            $this->assertContains($seccion, $texto);
        }
    }
}
