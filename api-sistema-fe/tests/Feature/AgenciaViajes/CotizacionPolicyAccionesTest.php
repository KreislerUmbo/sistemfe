<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaController;
use App\Http\Controllers\AgenciaViajes\AlternativaDestinoController;
use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\CotizacionController;
use App\Http\Controllers\AgenciaViajes\OpcionHotelController;
use App\Http\Controllers\AgenciaViajes\OpcionMayoristaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

// T-025 (H2): las acciones del cotizador no preguntaban de quién era la
// cotización. Un vendedor con permiso de ruta (cotizaciones.editar) pero sin
// cotizaciones.ver_todas no veía la cotización de otro en la lista, pero sí
// podía leerla o cambiarla llamando a la API con un id. Ahora cada acción
// pregunta a CotizacionPolicy por la cotización dueña (CotizacionDuena).
class CotizacionPolicyAccionesTest extends TestCase
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
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function vendedor(array $extra = []): User
    {
        $user = User::factory()->create([
            'role_id' => Role::create(['name' => 'rol-vendedor-'.uniqid(), 'guard_name' => 'api'])->id,
        ]);
        foreach (['cotizaciones.ver', 'cotizaciones.crear', 'cotizaciones.editar', ...$extra] as $permiso) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'api']));
        }

        return $user->fresh();
    }

    // Cotización del vendedor dado con: alternativa en borrador, un ítem
    // manual, una opción de mayorista con un hotel y una tarifa.
    private function cotizacionDe(User $vendedor): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10_000_000, 99_999_999), 'full_name' => 'Cliente Policy',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-T025-'.uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-11-10', 'vendedor_id' => $vendedor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 1,
            'descripcion_manual' => 'Traslado', 'modo_precio' => 'tarifa_fija', 'cantidad' => 1,
            'moneda_costo' => 'PEN', 'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'precio_convertido' => 15,
        ]);
        $opcion = OpcionMayorista::create([
            'alternativa_id' => $alternativa->id,
            'proveedor_id' => Proveedor::create(['razon_social' => 'Mayorista T025 SAC', 'estado' => true])->id,
            'moneda' => 'USD', 'estado' => 'candidata',
        ]);
        $hotel = OpcionHotel::create(['opcion_mayorista_id' => $opcion->id, 'nombre_hotel' => 'Hotel T025', 'moneda' => 'USD']);
        $tarifa = OpcionHotelTarifa::create(['opcion_hotel_id' => $hotel->id, 'tipo_habitacion' => 'doble', 'precio_costo' => 100, 'precio_venta' => 120]);

        return compact('cotizacionId', 'alternativa', 'item', 'opcion', 'hotel', 'tarifa');
    }

    /** @return array<string, callable> acciones de lectura y escritura del cotizador sobre esa cotización */
    private function acciones(array $c): array
    {
        $get = Request::create('/', 'GET');

        return [
            'ver PDF' => fn () => app(AlternativaController::class)->pdf((string) $c['alternativa']->id),
            'renombrar alternativa' => fn () => app(AlternativaController::class)->update(new Request(['nombre' => 'Otra']), (string) $c['alternativa']->id),
            'duplicar alternativa' => fn () => app(AlternativaController::class)->duplicar((string) $c['alternativa']->id),
            'cambiar precio' => fn () => app(AlternativaItemController::class)->update(new Request(['precio_convertido' => 1]), (string) $c['item']->id),
            'quitar servicio' => fn () => app(AlternativaItemController::class)->destroy((string) $c['item']->id),
            'temporadas' => fn () => app(AlternativaItemController::class)->temporadasTours((string) $c['alternativa']->id),
            'destinos' => fn () => app(AlternativaDestinoController::class)->index((string) $c['alternativa']->id),
            'mayoristas' => fn () => app(OpcionMayoristaController::class)->index((string) $c['alternativa']->id),
            'elegir mayorista' => fn () => app(OpcionMayoristaController::class)->elegir((string) $c['opcion']->id),
            'hoteles del mayorista' => fn () => app(OpcionMayoristaController::class)->hoteles($get, (string) $c['opcion']->id),
            'editar hotel' => fn () => app(OpcionHotelController::class)->update(new Request(['nombre_hotel' => 'X']), (string) $c['hotel']->id),
            'editar tarifa de hotel' => fn () => app(OpcionHotelController::class)->actualizarTarifa(new Request(['tipo_habitacion' => 'doble', 'precio_costo' => 1, 'precio_venta' => 1]), (string) $c['tarifa']->id),
            'pasajeros' => fn () => app(CotizacionController::class)->actualizarPasajeros(new Request(['pasajeros' => [['edad' => 30]]]), (string) $c['cotizacionId']),
        ];
    }

    public function test_un_vendedor_no_lee_ni_cambia_la_cotizacion_de_otro(): void
    {
        $c = $this->cotizacionDe($this->vendedor());
        Auth::guard('api')->setUser($this->vendedor());

        foreach ($this->acciones($c) as $accion => $llamar) {
            try {
                $llamar();
                $this->fail("Debió rechazar: {$accion}");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(15.0, (float) $c['item']->fresh()->precio_convertido);
        $this->assertSame('Alternativa 1', $c['alternativa']->fresh()->nombre);
        $this->assertSame('candidata', $c['opcion']->fresh()->estado);
    }

    public function test_el_dueno_y_quien_ve_todas_si_pueden(): void
    {
        $dueno = $this->vendedor();
        $supervisor = $this->vendedor(['cotizaciones.ver_todas']);

        foreach ([$dueno, $supervisor] as $quien) {
            $c = $this->cotizacionDe($dueno);
            Auth::guard('api')->setUser($quien);

            $respuesta = app(AlternativaItemController::class)->update(new Request(['precio_convertido' => 20]), (string) $c['item']->id);
            $this->assertSame(200, $respuesta->getStatusCode(), json_encode($respuesta->getData(true)));
            $this->assertSame(200, app(OpcionMayoristaController::class)->elegir((string) $c['opcion']->id)->getStatusCode());
        }
    }

    public function test_un_hotel_suelto_que_nadie_usa_no_tiene_dueno(): void
    {
        $hotel = OpcionHotel::create(['nombre_hotel' => 'Hotel suelto T025', 'moneda' => 'PEN']);
        Auth::guard('api')->setUser($this->vendedor());

        $respuesta = app(OpcionHotelController::class)->update(new Request(['nombre_hotel' => 'Hotel suelto corregido']), (string) $hotel->id);

        $this->assertSame(200, $respuesta->getStatusCode(), json_encode($respuesta->getData(true)));
    }
}
