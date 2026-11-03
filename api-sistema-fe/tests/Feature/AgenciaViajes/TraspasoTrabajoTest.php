<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\TraspasoTrabajoController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\Client\CrmAccion;
use App\Models\Client\CrmActividad;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use App\Services\Tenancy\GiroActual;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Stancl\Tenancy\Tenancy;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// T-061 (CRM) — «Pasar su trabajo a…»: qué pasa (cotizaciones abiertas y acciones pendientes), qué
// no (ganadas, perdidas, hechas, venta directa, modificaciones), la actividad por cliente, los
// rechazos y el aviso en Inicio. Postgres real, transacción por test revertida; mismo armado que
// CotizacionResultadoTest.
class TraspasoTrabajoTest extends TestCase
{
    private User $admin;

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

        Carbon::setTestNow(Carbon::parse('2026-11-03 15:00:00', 'UTC'));

        DB::table('roles')->insert([
            'id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");

        $tenant = new Tenant();
        $tenant->id = 'test-tenant-' . uniqid();
        app(Tenancy::class)->tenant = $tenant;
        $this->app->instance(GiroActual::class, new GiroActual('agencia_viajes'));

        $this->admin = $this->usuario('Admin', ['edit_user']);
        Auth::guard('api')->setUser($this->admin);
    }

    protected function tearDown(): void
    {
        app(Tenancy::class)->tenant = null;
        Carbon::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private function usuario(string $nombre, array $permisos = ['cotizaciones.ver', 'cotizaciones.editar'], array $extra = []): User
    {
        [$name, $surname] = array_pad(explode(' ', $nombre, 2), 2, '');
        $user = User::factory()->create(array_merge([
            'name' => $name, 'surname' => $surname, 'email' => 'u-' . uniqid() . '@agencia.test', 'state' => 1,
        ], $extra));
        foreach ($permisos as $permiso) {
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => $permiso]);
            $user->givePermissionTo($permiso);
        }

        return $user->fresh();
    }

    private function cliente(string $nombre = 'Cliente'): int
    {
        return DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10_000_000, 99_999_999),
            'full_name' => $nombre, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cotizacion(User $vendedor, int $clienteId, ?string $alternativa = 'enviada', array $extra = []): Cotizacion
    {
        $id = DB::table('cotizaciones')->insertGetId(array_merge([
            'codigo_prefijo' => 'TEST', 'codigo' => 'CDKM-' . uniqid(), 'cliente_id' => $clienteId,
            'vendedor_id' => $vendedor->id, 'destino' => 'Tarapoto',
            'fecha_viaje_desde' => '2026-11-15', 'fecha_viaje_hasta' => '2026-11-18',
            'origen' => Cotizacion::ORIGEN_COTIZADOR, 'created_at' => now(), 'updated_at' => now(),
        ], $extra));
        if ($alternativa !== null) {
            Alternativa::create([
                'cotizacion_id' => $id, 'nombre' => 'Opción ' . uniqid(), 'estado' => $alternativa,
                'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
                'fecha_envio' => '2026-10-01 15:00:00', 'fecha_vencimiento' => '2026-10-10 04:59:59',
            ]);
        }

        return Cotizacion::findOrFail($id);
    }

    private function accion(User $responsable, int $clienteId, ?string $referenciaTipo = null, ?int $referenciaId = null, bool $hecha = false): CrmAccion
    {
        return CrmAccion::create([
            'cliente_id' => $clienteId, 'referencia_tipo' => $referenciaTipo, 'referencia_id' => $referenciaId,
            'tipo' => CrmAccion::TIPO_LLAMAR, 'fecha' => '2026-11-03', 'responsable_id' => $responsable->id,
            'hecha_en' => $hecha ? now() : null, 'creada_por' => $responsable->id,
        ]);
    }

    private function traspasar(User $desde, ?int $haciaId)
    {
        return app(TraspasoTrabajoController::class)->traspasar(new Request(['hacia_usuario_id' => $haciaId]), (string) $desde->id);
    }

    private function codigo(callable $llamada): int
    {
        try {
            return $llamada()->getStatusCode();
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }

    private function inicio(User $usuario): array
    {
        Auth::guard('api')->setUser($usuario);
        $this->app->forgetInstance(DashboardService::class);

        return app(DashboardService::class)->inicio($usuario)['bloques'];
    }

    // ── CA1: qué pasa y qué no ───────────────────────────────────────

    public function test_pasan_las_abiertas_y_las_pendientes_y_lo_cerrado_sigue_de_ana(): void
    {
        $ana = $this->usuario('Ana Pérez');
        $luis = $this->usuario('Luis Soto');
        $cliente = $this->cliente();

        $abierta = $this->cotizacion($ana, $cliente, 'borrador');
        $vencida = $this->cotizacion($ana, $cliente, 'enviada'); // fecha_vencimiento pasada: sigue abierta
        $ganada = $this->cotizacion($ana, $cliente, 'aceptada');
        $perdida = $this->cotizacion($ana, $cliente, 'descartada', ['perdida_en' => now()]);
        $pendientes = [
            $this->accion($ana, $cliente, 'cotizacion', $abierta->id),
            $this->accion($ana, $cliente, 'reserva', 999_001),
            $this->accion($ana, $cliente),
        ];
        $hecha = $this->accion($ana, $cliente, hecha: true);

        $this->assertSame(['cotizaciones' => 2, 'acciones' => 3], app(TraspasoTrabajoController::class)->resumen((string) $ana->id)->getData(true)['data']);

        $respuesta = $this->traspasar($ana, $luis->id)->getData(true)['data'];
        $this->assertSame(['cotizaciones' => 2, 'acciones' => 3, 'clientes' => 1], $respuesta);

        $this->assertSame($luis->id, $abierta->fresh()->vendedor_id);
        $this->assertSame($luis->id, $vencida->fresh()->vendedor_id);
        $this->assertSame($ana->id, $ganada->fresh()->vendedor_id);
        $this->assertSame($ana->id, $perdida->fresh()->vendedor_id);
        foreach ($pendientes as $accion) {
            $this->assertSame($luis->id, $accion->fresh()->responsable_id);
        }
        $this->assertSame($ana->id, $hecha->fresh()->responsable_id);
        $this->assertSame(['cotizaciones' => 0, 'acciones' => 0], app(TraspasoTrabajoController::class)->resumen((string) $ana->id)->getData(true)['data']);
    }

    // ── CA2: una actividad por cliente ───────────────────────────────

    public function test_una_actividad_de_estado_por_cliente_con_nombres_y_cantidades(): void
    {
        $ana = $this->usuario('Ana Pérez');
        $luis = $this->usuario('Luis Soto');
        $conDos = $this->cliente('Con dos');
        $conAccion = $this->cliente('Con acción');

        $this->cotizacion($ana, $conDos);
        $this->cotizacion($ana, $conDos);
        $this->accion($ana, $conDos);
        $this->accion($ana, $conAccion);

        $this->traspasar($ana, $luis->id);

        $actividades = CrmActividad::whereIn('cliente_id', [$conDos, $conAccion])->get()->keyBy('cliente_id');
        $this->assertCount(2, $actividades);
        $this->assertSame('Trabajo traspasado de Ana Pérez a Luis Soto: 2 cotizaciones y 1 acción', $actividades[$conDos]->nota);
        $this->assertSame('Trabajo traspasado de Ana Pérez a Luis Soto: 1 acción', $actividades[$conAccion]->nota);
        foreach ($actividades as $actividad) {
            $this->assertSame(CrmActividad::TIPO_ESTADO, $actividad->tipo);
            $this->assertNull($actividad->referencia_tipo);
            $this->assertNull($actividad->referencia_id);
            $this->assertSame($this->admin->id, $actividad->usuario_id);
        }
    }

    // ── CA3: aviso en Inicio ─────────────────────────────────────────

    public function test_aviso_en_inicio_de_inactivos_y_eliminados_con_trabajo_abierto(): void
    {
        $inactiva = $this->usuario('Ana Pérez', extra: ['state' => User::STATE_INACTIVO]);
        $eliminado = $this->usuario('Beto Ruiz');
        $sinTrabajo = $this->usuario('Carla Díaz', extra: ['state' => User::STATE_INACTIVO]);
        $activo = $this->usuario('Dani Vega');
        $cliente = $this->cliente();

        $this->cotizacion($inactiva, $cliente);
        $this->cotizacion($inactiva, $cliente);
        $this->accion($inactiva, $cliente);
        $this->accion($eliminado, $cliente);
        $this->cotizacion($sinTrabajo, $cliente, 'aceptada');
        $this->accion($sinTrabajo, $cliente, hecha: true);
        $this->cotizacion($activo, $cliente);
        $eliminado->delete();

        $aviso = collect($this->inicio($this->admin)['trabajo_sin_dueno'])->keyBy('usuario_id');
        $this->assertSame([$inactiva->id, $eliminado->id], $aviso->keys()->intersect([$inactiva->id, $eliminado->id, $sinTrabajo->id, $activo->id])->values()->all());
        $this->assertSame(['usuario_id' => $inactiva->id, 'nombre' => 'Ana Pérez', 'estado' => 'inactivo', 'cotizaciones' => 2, 'acciones' => 1], $aviso[$inactiva->id]);
        $this->assertSame(['usuario_id' => $eliminado->id, 'nombre' => 'Beto Ruiz', 'estado' => 'eliminado', 'cotizaciones' => 0, 'acciones' => 1], $aviso[$eliminado->id]);

        // Sin edit_user, o en otro giro: sin aviso.
        $this->assertArrayNotHasKey('trabajo_sin_dueno', $this->inicio($this->usuario('Sin permiso', ['cotizaciones.ver_todas'])));
        $this->app->instance(GiroActual::class, new GiroActual('retail'));
        $this->assertArrayNotHasKey('trabajo_sin_dueno', $this->inicio($this->admin));
    }

    // ── CA4: rechazos ────────────────────────────────────────────────

    public function test_destino_invalido_u_origen_sin_trabajo_da_422(): void
    {
        $ana = $this->usuario('Ana Pérez', extra: ['state' => User::STATE_INACTIVO]);
        $cliente = $this->cliente();
        $cotizacion = $this->cotizacion($ana, $cliente);

        $inactivo = $this->usuario('Inactivo', extra: ['state' => User::STATE_INACTIVO]);
        $eliminado = $this->usuario('Eliminado');
        $eliminado->delete();
        $sinEditar = $this->usuario('Solo ver', ['cotizaciones.ver']);

        foreach ([$inactivo->id, $eliminado->id, $ana->id, $sinEditar->id] as $haciaId) {
            $this->assertSame(422, $this->codigo(fn () => $this->traspasar($ana, $haciaId)), "destino {$haciaId}");
        }
        $this->assertSame(422, $this->codigo(fn () => $this->traspasar($ana, null)));
        $this->assertSame($ana->id, $cotizacion->fresh()->vendedor_id);

        $nada = $this->usuario('Sin trabajo');
        $this->assertSame(422, $this->codigo(fn () => $this->traspasar($nada, $this->usuario('Luis Soto')->id)));
    }

    public function test_el_selector_ofrece_solo_destinos_validos(): void
    {
        Role::firstOrCreate(['guard_name' => 'api', 'name' => 'Super-Admin']);
        $ana = $this->usuario('Ana Pérez');
        $luis = $this->usuario('Luis Soto');
        $inactivo = $this->usuario('Inactivo', extra: ['state' => User::STATE_INACTIVO]);
        $eliminado = $this->usuario('Eliminado');
        $eliminado->delete();
        $sinEditar = $this->usuario('Solo ver', ['cotizaciones.ver']);
        $soporte = $this->usuario('Soporte Umbo');
        $soporte->assignRole('Super-Admin');

        $ids = fn () => collect(app(TraspasoTrabajoController::class)->resumen((string) $ana->id)->getData(true)['destinos'])->pluck('id');
        $this->assertTrue($ids()->contains($luis->id));
        $this->assertEmpty($ids()->intersect([$ana->id, $inactivo->id, $eliminado->id, $sinEditar->id, $soporte->id]));

        Auth::guard('api')->setUser($soporte);
        $this->assertTrue($ids()->contains($soporte->id));
    }

    public function test_el_origen_eliminado_se_puede_traspasar(): void
    {
        $ana = $this->usuario('Ana Pérez');
        $cotizacion = $this->cotizacion($ana, $this->cliente());
        $ana->delete();
        $luis = $this->usuario('Luis Soto');

        $this->assertSame(200, $this->traspasar($ana, $luis->id)->getStatusCode());
        $this->assertSame($luis->id, $cotizacion->fresh()->vendedor_id);
    }

    public function test_super_admin_protegido_y_rutas_con_edit_user(): void
    {
        Role::firstOrCreate(['guard_name' => 'api', 'name' => 'Super-Admin']);
        $soporte = $this->usuario('Soporte Umbo');
        $soporte->assignRole('Super-Admin');
        $ana = $this->usuario('Ana Pérez');
        $cliente = $this->cliente();
        $this->cotizacion($soporte, $cliente);
        $this->cotizacion($ana, $cliente);

        $this->assertSame(403, $this->codigo(fn () => $this->traspasar($soporte, $ana->id)));
        $this->assertSame(403, $this->codigo(fn () => $this->traspasar($ana, $soporte->id)));

        // Un Super-Admin sí puede.
        Auth::guard('api')->setUser($soporte);
        $this->assertSame(200, $this->traspasar($ana, $soporte->id)->getStatusCode());

        $rutas = collect(Route::getRoutes()->getRoutes());
        foreach ([['GET', 'users/{id}/trabajo-abierto'], ['POST', 'users/{id}/traspasar-trabajo']] as [$metodo, $uri]) {
            $ruta = $rutas->first(fn ($r) => in_array($metodo, $r->methods(), true) && $r->uri() === "api/{$uri}");
            $this->assertNotNull($ruta, "{$metodo} {$uri}");
            $this->assertContains('permission:edit_user', $ruta->gatherMiddleware());
        }
    }

    public function test_fuera_de_agencia_responde_404(): void
    {
        $ana = $this->usuario('Ana Pérez');
        $this->app->instance(GiroActual::class, new GiroActual('retail'));
        $this->app->forgetInstance(TraspasoTrabajoController::class);

        $this->assertSame(404, $this->codigo(fn () => app(TraspasoTrabajoController::class)->resumen((string) $ana->id)));
        $this->assertSame(404, $this->codigo(fn () => $this->traspasar($ana, $this->admin->id)));
    }

    // ── CA5: venta directa y modificación no cuentan ni pasan ────────

    public function test_venta_directa_y_modificacion_no_cuentan_ni_pasan(): void
    {
        $ana = $this->usuario('Ana Pérez');
        $luis = $this->usuario('Luis Soto');
        $cliente = $this->cliente();
        $directa = $this->cotizacion($ana, $cliente, 'borrador', ['origen' => Cotizacion::ORIGEN_VENTA_DIRECTA]);
        $modificacion = $this->cotizacion($ana, $cliente, 'borrador', ['origen' => Cotizacion::ORIGEN_MODIFICACION]);
        $this->accion($ana, $cliente);

        $this->assertSame(['cotizaciones' => 0, 'acciones' => 1], app(TraspasoTrabajoController::class)->resumen((string) $ana->id)->getData(true)['data']);
        $this->assertSame(['cotizaciones' => 0, 'acciones' => 1, 'clientes' => 1], $this->traspasar($ana, $luis->id)->getData(true)['data']);
        $this->assertSame($ana->id, $directa->fresh()->vendedor_id);
        $this->assertSame($ana->id, $modificacion->fresh()->vendedor_id);
    }
}
