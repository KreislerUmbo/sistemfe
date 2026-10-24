<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\Reserva;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// Doble aceptación de una reserva (revisión del usuario, 23-oct-2026): una
// cotización tiene como máximo UNA reserva activa, aunque dos pestañas
// acepten a la vez la misma alternativa o alternativas distintas.
// ReservaController::aceptar() bloquea la fila de la cotización y repite sus
// revisiones con el lock tomado.
class ReservaAceptarConcurrencyTest extends TestCase
{
    use ConAccesoATodasLasReservas;

    private User $usuario;

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

        $this->usuario = $this->verTodasLasReservas();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function crearCotizacion(): int
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI',
            'n_document' => (string) random_int(10000000, 99999999),
            'full_name' => 'Cliente concurrencia reserva',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST',
            'codigo' => 'TEST-ACEPTAR-'.uniqid(),
            'cliente_id' => $clienteId,
            'destino' => 'Tarapoto',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function crearAlternativa(int $cotizacionId, string $nombre = 'Alternativa 1'): Alternativa
    {
        return Alternativa::create([
            'cotizacion_id' => $cotizacionId,
            'nombre' => $nombre,
            'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN',
            'tipo_cambio_aplicado' => 1,
            'tipo_cambio_origen' => 'dia',
        ]);
    }

    public function test_aceptar_espera_el_lock_de_la_cotizacion_antes_de_crear_la_reserva(): void
    {
        $alternativa = $this->crearAlternativa($this->crearCotizacion());
        $clienteId = DB::table('cotizaciones')->where('id', $alternativa->cotizacion_id)->value('cliente_id');

        // Un lock entre dos conexiones solo se ve con los datos ya guardados de
        // verdad: se hace commit (también del usuario de setUp()) y el finally
        // borra a mano todo lo creado, para no dejar filas en la base de pruebas.
        // Los permisos que setUp() creó también se guardarían con el commit:
        // antes se anotan los que ya estaban guardados (la segunda conexión
        // solo ve lo confirmado) para borrar después solo los nuevos. Sin esto
        // quedaban en la base de pruebas y rompían los tests de Roles y Usuarios
        // que los crean (PermissionAlreadyExists, 24-oct-2026).
        config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
        DB::purge('pgsql_b');
        $permisosQueYaEstaban = DB::connection('pgsql_b')->table('permissions')->pluck('id')->all();

        DB::commit();

        $bloqueada = false;
        $codigoError = null;

        try {
            // La "otra pestaña": una segunda conexión que tiene tomada la cotización.
            DB::connection('pgsql_b')->beginTransaction();
            DB::connection('pgsql_b')->select(
                'select * from cotizaciones where id = ? for update',
                [$alternativa->cotizacion_id]
            );

            DB::beginTransaction();
            DB::statement("SET LOCAL lock_timeout = '300ms'");

            try {
                app(ReservaController::class)->aceptar(new Request(), (string) $alternativa->id);
            } catch (\Throwable $error) {
                $bloqueada = true;
                $codigoError = $error->getCode();
            }

            DB::rollBack();
            DB::connection('pgsql_b')->rollBack();

            $this->assertTrue($bloqueada, 'aceptar() debe esperar el lock de la cotización antes de crear la reserva.');
            $this->assertSame('55P03', (string) $codigoError, 'Postgres corta la espera por lock_timeout.');
            $this->assertSame(0, Reserva::where('alternativa_id', $alternativa->id)->count());

            // Liberado el lock, se acepta una sola vez. El segundo intento lo
            // rechaza la revisión rápida de antes del lock (la alternativa ya
            // está aceptada); la de dentro del lock solo se alcanza si dos
            // peticiones se cruzan de verdad, y eso es lo que el lock ordena.
            $primera = app(ReservaController::class)->aceptar(new Request(), (string) $alternativa->id);
            $segunda = app(ReservaController::class)->aceptar(new Request(), (string) $alternativa->id);

            $this->assertSame(200, $primera->getStatusCode(), $primera->getContent());
            $this->assertSame(422, $segunda->getStatusCode());
            $this->assertSame(1, Reserva::where('alternativa_id', $alternativa->id)->count());
        } finally {
            if (DB::connection('pgsql_b')->transactionLevel() > 0) {
                DB::connection('pgsql_b')->rollBack();
            }
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Reserva::where('alternativa_id', $alternativa->id)->delete();
            Alternativa::whereKey($alternativa->id)->delete();
            DB::table('cotizaciones')->where('id', $alternativa->cotizacion_id)->delete();
            DB::table('clients')->where('id', $clienteId)->delete();
            $this->borrarUsuarioDeSetUp();
            DB::table('permissions')->whereNotIn('id', $permisosQueYaEstaban)->delete();
            DB::beginTransaction();
        }
    }

    // La regla es por cotización: aceptada una alternativa, otra de la misma
    // cotización ya no puede generar una segunda reserva.
    public function test_otra_alternativa_de_la_misma_cotizacion_no_genera_una_segunda_reserva(): void
    {
        $cotizacionId = $this->crearCotizacion();
        $primera = $this->crearAlternativa($cotizacionId, 'Alternativa 1');
        $segunda = $this->crearAlternativa($cotizacionId, 'Alternativa 2');

        $respuestaPrimera = app(ReservaController::class)->aceptar(new Request(), (string) $primera->id);
        $respuestaSegunda = app(ReservaController::class)->aceptar(new Request(), (string) $segunda->id);

        $this->assertSame(200, $respuestaPrimera->getStatusCode(), $respuestaPrimera->getContent());
        $this->assertSame(422, $respuestaSegunda->getStatusCode());
        $this->assertSame(1, Reserva::whereIn('alternativa_id', [$primera->id, $segunda->id])->count());
    }

    // El usuario de setUp() queda guardado por el commit del test del lock:
    // se borran él, su rol, sus permisos directos y la auditoría que generó
    // darle esos permisos (role_audit_logs; sin esto fallaba
    // RolePermissionChangedListenerTest, que cuenta las filas, 24-oct-2026).
    private function borrarUsuarioDeSetUp(): void
    {
        $roleId = $this->usuario->role_id;
        DB::table('role_audit_logs')->where('target_type', 'user')->where('target_id', $this->usuario->id)->delete();
        DB::table('model_has_permissions')->where('model_id', $this->usuario->id)->where('model_type', User::class)->delete();
        DB::table('model_has_roles')->where('model_id', $this->usuario->id)->where('model_type', User::class)->delete();
        DB::table('users')->where('id', $this->usuario->id)->delete();
        DB::table('roles')->where('id', $roleId)->delete();
    }
}
