<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaAnticipoController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaFacturacionController;
use App\Http\Controllers\AgenciaViajes\ReservaPasajeroController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\Reserva;
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

// Auditoría reserva + facturación (23-oct-2026): ReservaPolicy solo se
// usaba en ver y cancelar. Un usuario con permiso de ruta (reservas.editar)
// pero sin reservas.ver_todas no veía la reserva de otro vendedor en la
// lista, pero sí podía facturarla o cambiarla llamando a la API con su ID.
// Ahora cada acción pregunta a la Policy: dueño de la cotización o ver_todas.
class ReservaPolicyAccionesTest extends TestCase
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

    // Un vendedor como el de un rol hecho a mano: puede editar reservas,
    // pero no ve las de los demás.
    private function vendedor(): User
    {
        $user = User::factory()->create([
            'role_id' => Role::create(['name' => 'rol-vendedor-'.uniqid(), 'guard_name' => 'api'])->id,
        ]);
        // register_advance: para que el anticipo llegue hasta la Policy.
        foreach (['reservas.ver', 'reservas.crear', 'reservas.editar', 'register_advance'] as $permiso) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'api']));
        }

        return $user;
    }

    private function actuarComo(User $user): void
    {
        Auth::guard('api')->setUser($user->fresh());
    }

    // Reserva activa con un pasajero, de una cotización del vendedor dado.
    private function reservaDe(User $vendedor): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '44556677', 'full_name' => 'Cliente Policy',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-POL-'.uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-11-01', 'fecha_viaje_hasta' => '2026-11-03',
            'vendedor_id' => $vendedor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'aceptada',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
        $reserva = Reserva::create(['alternativa_id' => $alternativa->id, 'estado' => 'activa']);
        $pasajero = ReservaPasajero::create(['reserva_id' => $reserva->id, 'tipo_pax' => 'adulto', 'nombre' => 'Ana']);

        return [$reserva, $pasajero, $alternativa];
    }

    private function esperarBloqueo(callable $accion): void
    {
        try {
            $accion();
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('Se esperaba que la Policy bloqueara la acción sobre una reserva ajena.');
    }

    public function test_vendedor_no_puede_facturar_ni_cambiar_la_reserva_de_otro(): void
    {
        $duenio = $this->vendedor();
        $otro = $this->vendedor();
        [$reserva, $pasajero] = $this->reservaDe($duenio);
        $id = (string) $reserva->id;

        $this->actuarComo($otro);

        $this->esperarBloqueo(fn () => app(ReservaFacturacionController::class)
            ->prepararFactura(new Request(['pasajero_ids' => [$pasajero->id]]), $id));
        $this->esperarBloqueo(fn () => app(ReservaFacturacionController::class)
            ->store(new Request(['pasajero_ids' => [$pasajero->id], 'tipo_comprobante_codigo' => '03']), $id));
        $this->esperarBloqueo(fn () => app(ReservaAnticipoController::class)
            ->store(new Request(['amount' => 50]), $id));
        $this->esperarBloqueo(fn () => app(ReservaPasajeroController::class)
            ->store(new Request(['tipo_pax' => 'adulto']), $id));
        $this->esperarBloqueo(fn () => app(ReservaPasajeroController::class)
            ->update(new Request(['nombre' => 'Cambiado']), (string) $pasajero->id));
        $this->esperarBloqueo(fn () => app(ReservaController::class)
            ->reprogramar(new Request(['fecha_viaje_desde' => '2026-12-01']), $id));
        $this->esperarBloqueo(fn () => app(ReservaController::class)
            ->actualizarFacturacionExterna(new Request(['facturacion_externa' => true]), $id));

        // Nada cambió.
        $this->assertSame(1, $reserva->pasajeros()->count());
        $this->assertSame('Ana', $pasajero->fresh()->nombre);
    }

    public function test_vendedor_no_puede_aceptar_la_alternativa_de_otro(): void
    {
        $duenio = $this->vendedor();
        $otro = $this->vendedor();
        [, , $alternativa] = $this->reservaDe($duenio);
        $alternativa->update(['estado' => 'enviada']);

        $this->actuarComo($otro);

        $this->esperarBloqueo(fn () => app(ReservaController::class)
            ->aceptar(new Request([]), (string) $alternativa->id));
    }

    public function test_el_duenio_sin_ver_todas_sigue_pudiendo_trabajar_su_reserva(): void
    {
        $duenio = $this->vendedor();
        [$reserva, $pasajero] = $this->reservaDe($duenio);

        $this->actuarComo($duenio);

        $respuesta = app(ReservaPasajeroController::class)
            ->update(new Request(['nombre' => 'Ana María']), (string) $pasajero->id);

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame('Ana María', $pasajero->fresh()->nombre);
        $this->assertSame((string) $reserva->id, (string) $pasajero->reserva_id);
    }

    public function test_quien_ve_todas_puede_trabajar_la_reserva_de_otro(): void
    {
        $duenio = $this->vendedor();
        $supervisor = $this->vendedor();
        $supervisor->givePermissionTo(Permission::firstOrCreate(['name' => 'reservas.ver_todas', 'guard_name' => 'api']));
        [, $pasajero] = $this->reservaDe($duenio);

        $this->actuarComo($supervisor);

        $respuesta = app(ReservaPasajeroController::class)
            ->update(new Request(['nombre' => 'Ana Supervisada']), (string) $pasajero->id);

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame('Ana Supervisada', $pasajero->fresh()->nombre);
    }
}
