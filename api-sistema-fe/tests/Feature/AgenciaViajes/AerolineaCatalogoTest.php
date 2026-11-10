<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AerolineaController;
use App\Http\Controllers\Central\AerolineaController as CentralAerolineaController;
use App\Models\AgenciaViajes\Aerolinea;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\GiroActual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Models\Permission;
use Stancl\Tenancy\Tenancy;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// T-015 — Catálogo central de aerolíneas: lista inicial, búsqueda y alta desde un tenant Agencia,
// aislamiento por giro y permiso, y administración desde el panel central (editar, desactivar,
// unir). Postgres real; la conexión 'central' apunta a la misma base de pruebas y todo se
// revierte con una transacción por conexión.
class AerolineaCatalogoTest extends TestCase
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
            'database.connections.central.database' => 'sistemafe_test_migrations',
        ]);
        DB::purge('pgsql');
        DB::purge('central');

        // La base de pruebas se arma una vez; la tabla (con su lista inicial) se crea acá si falta.
        if (! Schema::connection('central')->hasTable('aerolineas')) {
            Artisan::call('migrate', [
                '--path' => ['database/migrations/2026_11_09_100000_create_aerolineas_table.php'],
                '--force' => true,
            ]);
        }

        DB::beginTransaction();
        DB::connection('central')->beginTransaction();

        // users.role_id tiene FK a roles: el factory de User usa el rol 1.
        DB::table('roles')->insert([
            'id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");

        $this->comoTenant('agencia_viajes');
    }

    protected function tearDown(): void
    {
        Aerolinea::flushEventListeners();
        app(Tenancy::class)->tenant = null;
        DB::connection('central')->rollBack();
        DB::rollBack();
        parent::tearDown();
    }

    private function comoTenant(string $giro, string $id = 'tenant-a'): void
    {
        $tenant = new Tenant();
        $tenant->id = $id;
        app(Tenancy::class)->tenant = $tenant;
        $this->app->instance(GiroActual::class, new GiroActual($giro));
    }

    private function tenantController(): AerolineaController
    {
        return new AerolineaController(app(GiroActual::class));
    }

    private function buscar(string $q): array
    {
        return $this->tenantController()->index(Request::create('/x', 'GET', ['buscar' => $q]))->getData(true)['data'];
    }

    private function nombres(string $q): array
    {
        return array_column($this->buscar($q), 'nombre');
    }

    private function agregar(array $datos)
    {
        return $this->tenantController()->store(Request::create('/x', 'POST', $datos));
    }

    private function central(): CentralAerolineaController
    {
        return app(CentralAerolineaController::class);
    }

    private function codigo(callable $fn): int
    {
        try {
            $fn();
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }

        return 200;
    }

    private function crear(string $nombre): Aerolinea
    {
        return Aerolinea::create(['nombre' => $nombre, 'nombre_normalizado' => Aerolinea::normalizar($nombre)]);
    }

    private function unir(Aerolinea $origen, Aerolinea $destino): void
    {
        $this->central()->unir(Request::create('/x', 'POST', ['destino_id' => $destino->id]), (string) $origen->id);
    }

    // CA1
    public function test_la_lista_inicial_existe_tras_migrar(): void
    {
        $nombres = Aerolinea::pluck('nombre')->all();
        foreach (['LATAM', 'Sky Airline', 'JetSMART', 'Star Perú', 'ATSA', 'Avianca', 'Copa Airlines', 'American Airlines', 'Iberia', 'Aerolíneas Argentinas'] as $esperada) {
            $this->assertContains($esperada, $nombres);
        }
    }

    // CA2
    public function test_buscar_lat_devuelve_latam_y_tambien_por_codigo(): void
    {
        $this->assertContains('LATAM', $this->nombres('lat'));
        $this->assertContains('Sky Airline', $this->nombres('h2'));
        $this->assertSame([], $this->nombres('zzzz-no-existe'));
    }

    // T-016 — las que empiezan con lo escrito van antes que las que solo lo contienen.
    public function test_buscar_prioriza_las_que_empiezan_con_lo_escrito(): void
    {
        $this->crear('Aaa Latinoamerica Air');   // lo contiene, pero alfabéticamente iría primero
        $this->crear('Latitud Air');

        $nombres = $this->nombres('lat');

        $this->assertLessThan(array_search('Aaa Latinoamerica Air', $nombres), array_search('LATAM', $nombres));
        $this->assertLessThan(array_search('Aaa Latinoamerica Air', $nombres), array_search('Latitud Air', $nombres));
    }

    public function test_buscar_devuelve_maximo_20(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->crear("Zeta $i");
        }
        $this->assertCount(20, $this->buscar('zeta'));
    }

    // CA3
    public function test_agregar_un_nombre_existente_devuelve_la_existente_sin_duplicar(): void
    {
        $antes = Aerolinea::count();
        $respuesta = $this->agregar(['nombre' => '  latam ']);

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame('LATAM', $respuesta->getData(true)['data']['nombre']);
        $this->assertSame($antes, Aerolinea::count());
    }

    // CA4
    public function test_lo_que_agrega_un_tenant_lo_ve_otro(): void
    {
        $respuesta = $this->agregar(['nombre' => 'Viva Air']);
        $this->assertSame(201, $respuesta->getStatusCode());
        $this->assertSame('tenant-a', Aerolinea::where('nombre', 'Viva Air')->value('creada_por_tenant'));

        $this->comoTenant('agencia_viajes', 'tenant-b');
        $this->assertContains('Viva Air', $this->nombres('viva'));
    }

    public function test_regla_de_mayusculas_del_alta(): void
    {
        $this->agregar(['nombre' => 'KLM']);
        $this->agregar(['nombre' => 'viva  air peru']);
        $this->agregar(['nombre' => 'easyJet']);
        $this->agregar(['nombre' => 'KLM Royal Dutch Airlines']);
        $this->agregar(['nombre' => 'Wingo air']);
        $this->assertNotNull(Aerolinea::where('nombre', 'KLM')->first());
        $this->assertNotNull(Aerolinea::where('nombre', 'Viva Air Peru')->first());
        // Con alguna mayúscula se respeta tal cual (no "Easyjet" ni "Klm Royal…").
        $this->assertNotNull(Aerolinea::where('nombre', 'easyJet')->first());
        $this->assertNotNull(Aerolinea::where('nombre', 'KLM Royal Dutch Airlines')->first());
        $this->assertNotNull(Aerolinea::where('nombre', 'Wingo air')->first());
    }

    public function test_las_tildes_no_cuentan_ni_al_buscar_ni_al_agregar(): void
    {
        // La lista inicial ya viene normalizada sin tildes desde la migración.
        $this->assertSame('star peru', Aerolinea::where('nombre', 'Star Perú')->value('nombre_normalizado'));

        $this->assertContains('Star Perú', $this->nombres('peru'));
        $this->assertContains('Star Perú', $this->nombres('perú'));
        $this->assertContains('Aerolíneas Argentinas', $this->nombres('aerolineas'));

        $antes = Aerolinea::count();
        $respuesta = $this->agregar(['nombre' => 'star peru']);
        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame('Star Perú', $respuesta->getData(true)['data']['nombre']);
        $this->assertSame($antes, Aerolinea::count());
    }

    public function test_central_no_deja_renombrar_a_un_nombre_que_solo_difiere_en_tildes(): void
    {
        $otra = $this->crear('Aerolineas Argentinas Cargo');

        $this->expectException(ValidationException::class);
        $this->central()->update(Request::create('/x', 'PUT', ['nombre' => 'Aerolineas Argentinas']), (string) $otra->id);
    }

    public function test_si_dos_agencias_agregan_a_la_vez_la_segunda_recibe_la_existente(): void
    {
        // Simula la carrera: justo antes del insert, OTRA conexión (otra agencia) confirma el mismo
        // nombre. Va por conexión aparte porque el insert fallido revierte su propio savepoint.
        config(['database.connections.central_otra' => config('database.connections.central')]);
        $otra = DB::connection('central_otra');
        Aerolinea::creating(function () use ($otra) {
            $otra->table('aerolineas')->insert([
                'nombre' => 'Carrera Air', 'nombre_normalizado' => 'carrera air',
                'activa' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        try {
            $respuesta = $this->agregar(['nombre' => 'Carrera Air']);

            $this->assertSame(200, $respuesta->getStatusCode());
            $this->assertSame('Carrera Air', $respuesta->getData(true)['data']['nombre']);
            $this->assertSame(1, Aerolinea::where('nombre_normalizado', 'carrera air')->count());
        } finally {
            $otra->table('aerolineas')->where('nombre_normalizado', 'carrera air')->delete();
        }
    }

    public function test_validacion_del_alta(): void
    {
        foreach ([['nombre' => 'A'], ['nombre' => str_repeat('x', 101)], ['nombre' => 'Bien Air', 'codigo_iata' => 'A']] as $datos) {
            try {
                $this->agregar($datos);
                $this->fail('Debió rechazar '.json_encode($datos));
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    // CA5
    public function test_un_tenant_retail_recibe_404_aunque_tenga_todos_los_permisos(): void
    {
        $this->comoTenant('retail');

        $this->assertSame(404, $this->codigo(fn () => $this->buscar('lat')));
        $this->assertSame(404, $this->codigo(fn () => $this->agregar(['nombre' => 'Nueva Air'])));
        $this->assertNull(Aerolinea::where('nombre', 'Nueva Air')->first());
    }

    // CA7: las rutas exigen el permiso de Agencia y, sin él, el middleware responde 403.
    public function test_sin_permiso_de_agencia_el_middleware_da_403(): void
    {
        foreach (['GET', 'POST'] as $metodo) {
            $ruta = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($r) => $r->uri() === 'api/aerolineas' && in_array($metodo, $r->methods(), true));
            $this->assertNotNull($ruta, "Falta la ruta $metodo api/aerolineas");
            $this->assertContains('permission:cotizaciones.editar|reservas.editar|agencia.paquetes', $ruta->gatherMiddleware());
        }

        $sinPermiso = User::factory()->create(['email' => 'u-'.uniqid().'@agencia.test', 'state' => 1]);
        Auth::guard('api')->setUser($sinPermiso);

        $this->expectException(UnauthorizedException::class);
        app(PermissionMiddleware::class)->handle(Request::create('/x', 'POST'), fn () => response('ok'), 'cotizaciones.editar|reservas.editar', 'api');
    }

    public function test_con_uno_de_los_permisos_pasa_el_middleware(): void
    {
        Permission::firstOrCreate(['guard_name' => 'api', 'name' => 'reservas.editar']);
        $usuario = User::factory()->create(['email' => 'u-'.uniqid().'@agencia.test', 'state' => 1]);
        $usuario->givePermissionTo('reservas.editar');
        Auth::guard('api')->setUser($usuario->fresh());

        $respuesta = app(PermissionMiddleware::class)->handle(Request::create('/x', 'POST'), fn () => response('ok'), 'cotizaciones.editar|reservas.editar', 'api');
        $this->assertSame(200, $respuesta->getStatusCode());
    }

    // CA6
    public function test_central_editar_desactivar_y_unir(): void
    {
        $latam = Aerolinea::where('nombre', 'LATAM')->first();
        $sucia = $this->crear('Latam Peru');

        // Editar
        $this->central()->update(Request::create('/x', 'PUT', ['nombre' => 'LATAM Airlines', 'codigo_iata' => 'la', 'activa' => true]), (string) $latam->id);
        $this->assertSame('LATAM Airlines', $latam->fresh()->nombre);
        $this->assertSame('LA', $latam->fresh()->codigo_iata);
        $this->assertSame('latam airlines', $latam->fresh()->nombre_normalizado);

        // Editar sin chocar con otra
        try {
            $this->central()->update(Request::create('/x', 'PUT', ['nombre' => ' sky AIRLINE ']), (string) $sucia->id);
            $this->fail('Debió rechazar el nombre duplicado');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        // Unir: sucia → latam. Queda inactiva, apuntando a LATAM, y ya no sale en la búsqueda.
        $this->unir($sucia, $latam);
        $this->assertFalse($sucia->fresh()->activa);
        $this->assertSame($latam->id, $sucia->fresh()->unida_a_id);
        $this->assertNotContains('Latam Peru', $this->nombres('latam'));

        // Agregar "latam peru" desde la agencia devuelve la vigente, no la unida.
        $this->assertSame($latam->id, $this->agregar(['nombre' => 'latam peru'])->getData(true)['data']['id']);

        // Desactivar: deja de salir en la búsqueda
        $this->central()->destroy((string) $latam->id);
        $this->assertNotContains('LATAM Airlines', $this->nombres('latam'));
    }

    public function test_unir_rechaza_a_si_misma_y_destino_inactivo(): void
    {
        $a = $this->crear('A Air');
        $b = $this->crear('B Air');
        $this->central()->destroy((string) $b->id);

        foreach ([$a, $b] as $destino) {
            try {
                $this->unir($a, $destino);
                $this->fail('Debió rechazar la unión');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_unir_reapunta_las_que_ya_estaban_unidas(): void
    {
        $b = $this->crear('B Air');
        $a = $this->crear('A Air');
        $c = $this->crear('C Air');
        $this->unir($c, $a);
        $this->unir($a, $b);

        $this->assertSame($b->id, $c->fresh()->unida_a_id);
        $this->assertSame($b->id, $a->fresh()->unida_a_id);
    }

    public function test_reactivar_una_unida_la_vuelve_independiente(): void
    {
        $a = $this->crear('A Air');
        $b = $this->crear('B Air');
        $this->unir($a, $b);

        $this->central()->update(Request::create('/x', 'PUT', ['nombre' => 'A Air', 'activa' => true]), (string) $a->id);

        $this->assertTrue($a->fresh()->activa);
        $this->assertNull($a->fresh()->unida_a_id);
    }
}
