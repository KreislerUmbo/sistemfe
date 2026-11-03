<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaController;
use App\Http\Controllers\AgenciaViajes\CotizacionResultadoController;
use App\Http\Controllers\AgenciaViajes\CrmMotivoResultadoController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\CrmMotivoResultado;
use App\Models\Client\CrmAccion;
use App\Models\Client\CrmActividad;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Stancl\Tenancy\Tenancy;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// T-052 (CRM C3) — «Cerrar como perdida», «Reabrir», el motivo de ganancia al aceptar y el catálogo
// de motivos. Postgres real (sistemafe_test_migrations), transacción por test revertida; mismo
// armado que CrmAccionTest. Las filas base del catálogo las insertó la migración.
class CotizacionResultadoTest extends TestCase
{
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

        Carbon::setTestNow(Carbon::parse('2026-11-03 02:00:00', 'UTC'));

        DB::table('roles')->insert([
            'id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");

        $tenant = new Tenant();
        $tenant->id = 'test-tenant-' . uniqid();
        app(Tenancy::class)->tenant = $tenant;
    }

    protected function tearDown(): void
    {
        app(Tenancy::class)->tenant = null;
        Carbon::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private function usuario(array $permisos = ['cotizaciones.ver', 'cotizaciones.editar'], bool $sesion = true): User
    {
        $user = User::factory()->create(['email' => 'vendedor-' . uniqid() . '@agencia.test']);
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
            'full_name' => 'Ana Pérez', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = DB::table('cotizaciones')->insertGetId(array_merge([
            'codigo_prefijo' => 'TEST', 'codigo' => 'CDKM-' . uniqid(), 'cliente_id' => $clienteId,
            'vendedor_id' => auth('api')->id(), 'destino' => 'Tarapoto',
            'fecha_viaje_desde' => '2026-11-15', 'fecha_viaje_hasta' => '2026-11-18',
            'origen' => Cotizacion::ORIGEN_COTIZADOR, 'created_at' => now(), 'updated_at' => now(),
        ], $extra));

        return Cotizacion::findOrFail($id);
    }

    private function alternativa(Cotizacion $cotizacion, string $estado, array $extra = []): Alternativa
    {
        return Alternativa::create(array_merge([
            'cotizacion_id' => $cotizacion->id, 'nombre' => 'Opción ' . uniqid(), 'estado' => $estado,
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ], $extra));
    }

    private function motivo(string $tipo, string $nombre): CrmMotivoResultado
    {
        return CrmMotivoResultado::where('tipo', $tipo)->where('nombre', $nombre)->firstOrFail();
    }

    private function cerrar(Cotizacion $cotizacion, ?int $motivoId, ?string $detalle = null)
    {
        return app(CotizacionResultadoController::class)
            ->cerrarComoPerdida(new Request(['motivo_id' => $motivoId, 'detalle' => $detalle]), (string) $cotizacion->id);
    }

    private function reabrir(Cotizacion $cotizacion)
    {
        return app(CotizacionResultadoController::class)->reabrir((string) $cotizacion->id);
    }

    private function codigo(callable $llamada): int
    {
        try {
            return $llamada()->getStatusCode();
        } catch (HttpException $e) {
            return $e->getStatusCode();
        } catch (AuthorizationException) {
            return 403;
        }
    }

    /** Dos enviadas (con fechas), un borrador y una que ya estaba descartada. */
    private function cotizacionConAlternativas(): array
    {
        $cotizacion = $this->cotizacion();
        $fechas = ['fecha_envio' => '2026-10-28 15:00:00', 'fecha_vencimiento' => '2026-11-10 04:59:59'];

        return [$cotizacion, [
            'enviada1' => $this->alternativa($cotizacion, 'enviada', $fechas),
            'enviada2' => $this->alternativa($cotizacion, 'enviada', $fechas),
            'borrador' => $this->alternativa($cotizacion, 'borrador'),
            'descartada' => $this->alternativa($cotizacion, 'descartada'),
        ]];
    }

    // ── CA1: cerrar como perdida ─────────────────────────────────────

    public function test_cerrar_con_precio_descarta_las_alternativas_y_cierra_las_pendientes(): void
    {
        $yo = $this->usuario();
        [$cotizacion, $alts] = $this->cotizacionConAlternativas();
        $accion = CrmAccion::create([
            'cliente_id' => $cotizacion->cliente_id, 'referencia_tipo' => 'cotizacion', 'referencia_id' => $cotizacion->id,
            'tipo' => 'llamar', 'fecha' => '2026-11-04', 'responsable_id' => $yo->id,
        ]);

        $respuesta = $this->cerrar($cotizacion, $this->motivo('perdida', 'Precio')->id, 'Le pareció caro');

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame(
            ['enviada1' => ['descartada', 'enviada'], 'enviada2' => ['descartada', 'enviada'], 'borrador' => ['descartada', 'borrador'], 'descartada' => ['descartada', null]],
            collect($alts)->map(fn (Alternativa $a) => [$a->fresh()->estado, $a->fresh()->estado_antes_de_perdida])->all()
        );

        $cotizacion->refresh();
        $this->assertSame('perdida', $cotizacion->estadoResumen());
        $this->assertSame($this->motivo('perdida', 'Precio')->id, $cotizacion->resultado_motivo_id);
        $this->assertSame('Le pareció caro', $cotizacion->resultado_detalle);
        $this->assertSame($yo->id, $cotizacion->perdida_por);
        $this->assertTrue($cotizacion->perdida_en->equalTo(now()));

        $notas = CrmActividad::deCotizacion($cotizacion->id)->where('tipo', 'estado')->pluck('nota');
        $this->assertContains('Cotización perdida: Precio — Le pareció caro', $notas);
        $this->assertNotNull($accion->fresh()->hecha_en);
        $this->assertTrue($notas->contains(fn ($n) => str_starts_with($n, 'Cotización perdida: se cerró la acción pendiente')));
    }

    public function test_show_devuelve_el_motivo_y_quien_la_cerro(): void
    {
        $this->usuario();
        [$cotizacion] = $this->cotizacionConAlternativas();
        $this->cerrar($cotizacion, $this->motivo('perdida', 'No respondió')->id);

        $datos = app(\App\Http\Controllers\AgenciaViajes\CotizacionController::class)->show((string) $cotizacion->id)->getData(true)['cotizacion'];

        $this->assertSame('No respondió', $datos['resultado_motivo']['nombre']);
        $this->assertSame('perdida', $datos['resultado_motivo']['tipo']);
        $this->assertSame(auth('api')->id(), $datos['perdida_por']['id']);
        $this->assertNotNull($datos['perdida_en']);
    }

    // ── CA2: cuándo no se puede cerrar ───────────────────────────────

    public function test_no_se_cierra_con_alternativa_aceptada_ni_otro_sin_detalle_ni_motivo_invalido_ni_dos_veces(): void
    {
        $this->usuario();

        $aceptada = $this->cotizacion();
        $this->alternativa($aceptada, 'aceptada');
        $this->assertSame(422, $this->cerrar($aceptada, $this->motivo('perdida', 'Precio')->id)->getStatusCode());

        $reemplazada = $this->cotizacion();
        $this->alternativa($reemplazada, Alternativa::ESTADO_REEMPLAZADA);
        $this->assertSame(422, $this->cerrar($reemplazada, $this->motivo('perdida', 'Precio')->id)->getStatusCode());

        $modificacion = $this->cotizacion(['origen' => Cotizacion::ORIGEN_MODIFICACION]);
        $this->alternativa($modificacion, 'borrador');
        $this->assertSame(422, $this->cerrar($modificacion, $this->motivo('perdida', 'Precio')->id)->getStatusCode());

        [$cotizacion, $alts] = $this->cotizacionConAlternativas();
        $otro = $this->motivo('perdida', 'Otro');
        $this->assertSame(422, $this->cerrar($cotizacion, $otro->id)->getStatusCode());
        $this->assertSame(422, $this->cerrar($cotizacion, $otro->id, '   ')->getStatusCode());
        $this->assertSame(422, $this->cerrar($cotizacion, null)->getStatusCode());
        $this->assertSame(422, $this->cerrar($cotizacion, $this->motivo('ganada', 'Precio')->id)->getStatusCode());
        $this->assertSame(422, $this->cerrar($cotizacion, $otro->id, str_repeat('a', 501))->getStatusCode());

        $inactivo = CrmMotivoResultado::create(['tipo' => 'perdida', 'nombre' => 'Viejo', 'activo' => false, 'orden' => 9]);
        $this->assertSame(422, $this->cerrar($cotizacion, $inactivo->id)->getStatusCode());

        $this->assertSame('enviada', $alts['enviada1']->fresh()->estado);
        $this->assertNull($cotizacion->fresh()->perdida_en);

        $this->assertSame(200, $this->cerrar($cotizacion, $otro->id, 'Viajó con su familia')->getStatusCode());
        $this->assertSame(422, $this->cerrar($cotizacion, $this->motivo('perdida', 'Precio')->id)->getStatusCode());
        $this->assertSame('Viajó con su familia', $cotizacion->fresh()->resultado_detalle);
    }

    public function test_un_vendedor_sin_ver_todas_no_cierra_ni_reabre_una_cotizacion_ajena(): void
    {
        $duenio = $this->usuario();
        [$cotizacion] = $this->cotizacionConAlternativas();
        $this->usuario();

        $this->assertSame(403, $this->codigo(fn () => $this->cerrar($cotizacion, $this->motivo('perdida', 'Precio')->id)));
        $this->assertNull($cotizacion->fresh()->perdida_en);

        Auth::guard('api')->setUser($duenio->fresh());
        $this->cerrar($cotizacion, $this->motivo('perdida', 'Precio')->id);
        $this->usuario();
        $this->assertSame(403, $this->codigo(fn () => $this->reabrir($cotizacion)));
        $this->assertNotNull($cotizacion->fresh()->perdida_en);
    }

    // ── CA3: reabrir ─────────────────────────────────────────────────

    public function test_reabrir_devuelve_cada_alternativa_a_su_estado_con_sus_fechas(): void
    {
        $this->usuario();
        [$cotizacion, $alts] = $this->cotizacionConAlternativas();
        $this->cerrar($cotizacion, $this->motivo('perdida', 'Precio')->id);

        $respuesta = $this->reabrir($cotizacion);

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame(
            ['enviada1' => 'enviada', 'enviada2' => 'enviada', 'borrador' => 'borrador', 'descartada' => 'descartada'],
            collect($alts)->map(fn (Alternativa $a) => $a->fresh()->estado)->all()
        );
        foreach ($alts as $alternativa) {
            $this->assertNull($alternativa->fresh()->estado_antes_de_perdida);
        }
        $enviada = $alts['enviada1']->fresh();
        $this->assertSame('2026-10-28 15:00:00', $enviada->fecha_envio->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-10 04:59:59', $enviada->fecha_vencimiento->format('Y-m-d H:i:s'));

        $cotizacion->refresh();
        $this->assertSame([null, null, null, null], [$cotizacion->resultado_motivo_id, $cotizacion->resultado_detalle, $cotizacion->perdida_en, $cotizacion->perdida_por]);
        $this->assertSame('enviada', $cotizacion->estadoResumen());
        $this->assertSame(1, CrmActividad::deCotizacion($cotizacion->id)->where('nota', 'Cotización reabierta')->count());

        $this->assertSame(422, $this->reabrir($cotizacion)->getStatusCode());
    }

    // ── CA4: motivo de ganancia al aceptar ───────────────────────────

    private function aceptar(Alternativa $alternativa, array $datos = [])
    {
        return app(ReservaController::class)->aceptar(new Request($datos), (string) $alternativa->id);
    }

    public function test_aceptar_guarda_el_motivo_de_ganancia_opcional(): void
    {
        $this->usuario();

        $conMotivo = $this->alternativa($this->cotizacion(), 'enviada');
        $otro = $this->motivo('ganada', 'Otro');
        $this->assertSame(200, $this->aceptar($conMotivo, ['resultado_motivo_id' => $otro->id, 'resultado_detalle' => 'Lo atendimos rápido'])->getStatusCode());
        $cotizacion = $conMotivo->cotizacion->fresh();
        $this->assertSame([$otro->id, 'Lo atendimos rápido', null], [$cotizacion->resultado_motivo_id, $cotizacion->resultado_detalle, $cotizacion->perdida_en]);
        $this->assertSame('reservada', $cotizacion->estadoResumen());

        // «Otro» sin detalle también se acepta al ganar.
        $otroSinDetalle = $this->alternativa($this->cotizacion(), 'enviada');
        $this->assertSame(200, $this->aceptar($otroSinDetalle, ['resultado_motivo_id' => $otro->id])->getStatusCode());

        $sinMotivo = $this->alternativa($this->cotizacion(), 'borrador');
        $this->assertSame(200, $this->aceptar($sinMotivo)->getStatusCode());
        $this->assertNull($sinMotivo->cotizacion->fresh()->resultado_motivo_id);

        $conPerdida = $this->alternativa($this->cotizacion(), 'enviada');
        $this->assertSame(422, $this->aceptar($conPerdida, ['resultado_motivo_id' => $this->motivo('perdida', 'Precio')->id])->getStatusCode());
        $this->assertSame('enviada', $conPerdida->fresh()->estado);
    }

    public function test_una_cotizacion_perdida_no_se_acepta(): void
    {
        $this->usuario();
        [$cotizacion, $alts] = $this->cotizacionConAlternativas();
        $this->cerrar($cotizacion, $this->motivo('perdida', 'Precio')->id);

        $this->assertSame(422, $this->aceptar($alts['enviada1'])->getStatusCode());
    }

    // ── CA5: el catálogo ─────────────────────────────────────────────

    private function catalogo(): CrmMotivoResultadoController
    {
        return app(CrmMotivoResultadoController::class);
    }

    private function nombresActivos(string $tipo): array
    {
        $motivos = $this->catalogo()->index(new Request(['tipo' => $tipo, 'solo_activos' => 1]))->getData(true)['motivos'];

        return array_column($motivos, 'nombre');
    }

    public function test_un_motivo_nuevo_aparece_antes_de_otro_y_se_puede_usar(): void
    {
        $this->usuario(['agencia.configuracion', 'cotizaciones.editar']);

        $respuesta = $this->catalogo()->store(new Request(['tipo' => 'perdida', 'nombre' => '  Temporada de lluvias ']));

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame(
            ['Precio', 'Eligió otra agencia', 'Cambió de fechas o de planes', 'No respondió', 'Temporada de lluvias', 'Otro'],
            $this->nombresActivos('perdida')
        );
        $this->assertNotContains('Temporada de lluvias', $this->nombresActivos('ganada'));

        $nuevo = $this->motivo('perdida', 'Temporada de lluvias');
        [$cotizacion] = $this->cotizacionConAlternativas();
        $this->assertSame(200, $this->cerrar($cotizacion, $nuevo->id)->getStatusCode());

        $this->assertSame(422, $this->catalogo()->store(new Request(['tipo' => 'perdida', 'nombre' => 'precio']))->getStatusCode());
        $this->assertSame(422, $this->catalogo()->store(new Request(['tipo' => 'empate', 'nombre' => 'X']))->getStatusCode());
    }

    public function test_desactivado_no_se_ofrece_ni_se_acepta_y_otro_no_se_renombra_ni_desactiva(): void
    {
        $this->usuario(['agencia.configuracion', 'cotizaciones.editar']);
        $precio = $this->motivo('ganada', 'Precio');

        $this->assertSame(200, $this->catalogo()->update(new Request(['activo' => false]), (string) $precio->id)->getStatusCode());
        $this->assertNotContains('Precio', $this->nombresActivos('ganada'));
        $todos = $this->catalogo()->index(new Request(['tipo' => 'ganada']))->getData(true)['motivos'];
        $this->assertContains('Precio', array_column($todos, 'nombre'));

        $alternativa = $this->alternativa($this->cotizacion(), 'enviada');
        $this->assertSame(422, $this->aceptar($alternativa, ['resultado_motivo_id' => $precio->id])->getStatusCode());

        $otro = $this->motivo('ganada', 'Otro');
        $this->assertSame(422, $this->catalogo()->update(new Request(['nombre' => 'Otros']), (string) $otro->id)->getStatusCode());
        $this->assertSame(422, $this->catalogo()->update(new Request(['activo' => false]), (string) $otro->id)->getStatusCode());
        $this->assertSame(200, $this->catalogo()->update(new Request(['orden' => 1]), (string) $otro->id)->getStatusCode());
        $this->assertSame([CrmMotivoResultado::NOMBRE_OTRO, true, 1], [$otro->fresh()->nombre, $otro->fresh()->activo, $otro->fresh()->orden]);

        $this->assertSame(200, $this->catalogo()->update(new Request(['nombre' => 'Mejor precio']), (string) $precio->id)->getStatusCode());
        $this->assertSame(422, $this->catalogo()->update(new Request(['nombre' => 'Otro']), (string) $precio->id)->getStatusCode());
    }

    public function test_rutas_sin_delete_y_con_sus_permisos(): void
    {
        $rutas = collect(Route::getRoutes()->getRoutes());
        $permiso = function (string $metodo, string $uri) use ($rutas) {
            $ruta = $rutas->first(fn ($r) => in_array($metodo, $r->methods(), true) && $r->uri() === "api/{$uri}");
            $this->assertNotNull($ruta, "{$metodo} {$uri}");

            return collect($ruta->gatherMiddleware())->first(fn ($m) => str_starts_with($m, 'permission:'));
        };

        $this->assertFalse($rutas->contains(fn ($r) => str_contains($r->uri(), 'crm-motivos-resultado') && in_array('DELETE', $r->methods(), true)));
        $this->assertSame('permission:agencia.configuracion|cotizaciones.editar', $permiso('GET', 'crm-motivos-resultado'));
        $this->assertSame('permission:agencia.configuracion', $permiso('POST', 'crm-motivos-resultado'));
        $this->assertSame('permission:agencia.configuracion', $permiso('PUT', 'crm-motivos-resultado/{id}'));
        $this->assertSame('permission:cotizaciones.editar', $permiso('POST', 'cotizaciones/{id}/perdida'));
        $this->assertSame('permission:cotizaciones.editar', $permiso('POST', 'cotizaciones/{id}/reabrir'));
    }

    // ── CA6: perdida no recibe alternativas nuevas ───────────────────

    public function test_perdida_no_crea_ni_duplica_alternativas(): void
    {
        $this->usuario(['cotizaciones.ver', 'cotizaciones.editar', 'cotizaciones.crear']);
        [$cotizacion, $alts] = $this->cotizacionConAlternativas();
        $this->cerrar($cotizacion, $this->motivo('perdida', 'Precio')->id);
        $controller = app(AlternativaController::class);

        $crear = $controller->store(new Request(['moneda_cotizacion' => 'PEN', 'tipo_cambio_origen' => 'dia']), (string) $cotizacion->id);
        $duplicar = $controller->duplicar((string) $alts['enviada1']->id);

        $this->assertSame([422, 422], [$crear->getStatusCode(), $duplicar->getStatusCode()]);
        $this->assertStringContainsString('Reabre la cotización primero', $crear->getData(true)['message']);
        $this->assertSame(4, Alternativa::where('cotizacion_id', $cotizacion->id)->count());
    }
}
