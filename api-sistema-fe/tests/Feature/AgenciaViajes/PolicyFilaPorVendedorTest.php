<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaController;
use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\CotizacionController;
use App\Http\Controllers\AgenciaViajes\ReservaAnticipoController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaFacturacionController;
use App\Http\Controllers\AgenciaViajes\ReservaItemController;
use App\Http\Controllers\AgenciaViajes\ReservaItemPasajeroController;
use App\Http\Controllers\AgenciaViajes\ReservaModificacionController;
use App\Http\Controllers\AgenciaViajes\ReservaPasajeroController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

// T-093 (CA3) — Segunda barrera por FILA: un vendedor sin ver_todas no puede
// tocar la cotización ni la reserva de otro vendedor, aunque tenga el permiso
// de la ruta (cotizaciones.editar, reservas.editar…). Una prueba por controller
// con Policy, no una por método: lo que se garantiza es que ese controller
// sigue preguntando a la Policy.
//
// Se llama al controller directo (como ReservaPolicyAccionesTest): ninguna ruta
// de este proyecto pasa por el stack completo de tenancy en los tests.
// La Policy lanza AuthorizationException, que el handler convierte en 403.
class PolicyFilaPorVendedorTest extends TestCase
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

    // Vendedor con permisos de ruta para todo, pero sin cotizaciones.ver_todas ni reservas.ver_todas.
    private function vendedor(): User
    {
        $user = User::factory()->create([
            'role_id' => Role::create(['name' => 'rol-vendedor-'.uniqid(), 'guard_name' => 'api'])->id,
        ]);
        foreach ([
            'cotizaciones.ver', 'cotizaciones.crear', 'cotizaciones.editar',
            'reservas.ver', 'reservas.crear', 'reservas.editar', 'reservas.modificar', 'register_advance',
        ] as $permiso) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'api']));
        }

        return $user;
    }

    private function actuarComo(User $user): void
    {
        Auth::guard('api')->setUser($user->fresh());
    }

    /**
     * Cotización del vendedor, con su alternativa aceptada, un ítem, una reserva activa,
     * un ítem de reserva y un pasajero.
     *
     * @return array{cotizacionId:int, alternativa:Alternativa, itemCotizacion:AlternativaItem, reserva:Reserva, itemReserva:ReservaItem, pasajero:ReservaPasajero}
     */
    private function trabajoDe(User $vendedor): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '44556677', 'full_name' => 'Cliente Fila',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-FILA-'.uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-11-01', 'fecha_viaje_hasta' => '2026-11-03',
            'vendedor_id' => $vendedor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'aceptada',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
        $itemCotizacion = AlternativaItem::create(['alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor', 'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 80, 'precio_venta_snapshot' => 120, 'precio_convertido' => 120,
            'tip_afe_igv' => '20', 'destino_tributario' => 'amazonia',
        ]);
        $reserva = Reserva::create(['alternativa_id' => $alternativa->id, 'estado' => 'activa']);
        $itemReserva = ReservaItem::create(['reserva_id' => $reserva->id, 'alternativa_item_id' => $itemCotizacion->id, 'fecha' => '2026-11-01']);
        $pasajero = ReservaPasajero::create(['reserva_id' => $reserva->id, 'tipo_pax' => 'adulto', 'nombre' => 'Ana']);

        return compact('cotizacionId', 'alternativa', 'itemCotizacion', 'reserva', 'itemReserva', 'pasajero');
    }

    private function esperarBloqueo(callable $accion): void
    {
        try {
            $accion();
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('Se esperaba que la Policy bloqueara (403) la acción sobre un trabajo ajeno.');
    }

    /** @return array{0: array, 1: User} datos del dueño y el vendedor ajeno ya autenticado */
    private function escenario(): array
    {
        $duenio = $this->vendedor();
        $ajeno = $this->vendedor();
        $datos = $this->trabajoDe($duenio);
        $this->actuarComo($ajeno);

        return [$datos, $ajeno];
    }

    public function test_cotizacion_ajena_da_403(): void
    {
        [$d] = $this->escenario();

        $this->esperarBloqueo(fn () => app(CotizacionController::class)->show((string) $d['cotizacionId']));
    }

    public function test_alternativa_ajena_da_403(): void
    {
        [$d] = $this->escenario();

        $this->esperarBloqueo(fn () => app(AlternativaController::class)
            ->update(new Request(['nombre' => 'Cambiada']), (string) $d['alternativa']->id));
    }

    public function test_item_de_cotizacion_ajeno_da_403(): void
    {
        [$d] = $this->escenario();

        $this->esperarBloqueo(fn () => app(AlternativaItemController::class)
            ->destroy((string) $d['itemCotizacion']->id));
    }

    public function test_reserva_ajena_da_403(): void
    {
        [$d] = $this->escenario();

        $this->esperarBloqueo(fn () => app(ReservaController::class)
            ->reprogramar(new Request(['fecha_viaje_desde' => '2026-12-01']), (string) $d['reserva']->id));
    }

    public function test_item_de_reserva_ajeno_da_403(): void
    {
        [$d] = $this->escenario();

        $this->esperarBloqueo(fn () => app(ReservaItemController::class)
            ->update(new Request(['hora' => '09:00']), (string) $d['itemReserva']->id));
    }

    public function test_asignacion_de_pasajeros_a_item_ajeno_da_403(): void
    {
        [$d] = $this->escenario();

        $this->esperarBloqueo(fn () => app(ReservaItemPasajeroController::class)
            ->store(new Request(['pasajero_ids' => [$d['pasajero']->id]]), (string) $d['itemReserva']->id));
    }

    public function test_pasajero_ajeno_da_403(): void
    {
        [$d] = $this->escenario();

        $this->esperarBloqueo(fn () => app(ReservaPasajeroController::class)
            ->update(new Request(['nombre' => 'Cambiado']), (string) $d['pasajero']->id));
        $this->assertSame('Ana', $d['pasajero']->fresh()->nombre);
    }

    public function test_facturacion_de_reserva_ajena_da_403(): void
    {
        [$d] = $this->escenario();

        $this->esperarBloqueo(fn () => app(ReservaFacturacionController::class)
            ->store(new Request(['pasajero_ids' => [$d['pasajero']->id], 'tipo_comprobante_codigo' => '03']), (string) $d['reserva']->id));
    }

    public function test_anticipo_de_reserva_ajena_da_403(): void
    {
        [$d] = $this->escenario();

        $this->esperarBloqueo(fn () => app(ReservaAnticipoController::class)
            ->store(new Request(['amount' => 50]), (string) $d['reserva']->id));
    }

    public function test_modificacion_de_reserva_ajena_da_403(): void
    {
        [$d] = $this->escenario();

        $this->esperarBloqueo(fn () => app(ReservaModificacionController::class)
            ->store((string) $d['reserva']->id));
    }
}
