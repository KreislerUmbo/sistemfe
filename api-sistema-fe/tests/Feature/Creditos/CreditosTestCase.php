<?php

declare(strict_types=1);

namespace Tests\Feature\Creditos;

use App\Models\Cash\Branch;
use App\Models\Cash\CashRegister;
use App\Models\Cash\CashSession;
use App\Models\Cash\PaymentMethod;
use App\Models\Client\Client;
use App\Models\Creditos\Credito;
use App\Models\User;
use App\Services\Creditos\ActivacionService;
use App\Services\Creditos\CreditoBorradorService;
use App\Services\Creditos\Dto\DatosCredito;
use App\Services\Creditos\Motor\Dto\Frecuencia;
use App\Services\Creditos\Motor\Enums\FrecuenciaUnidad;
use App\Services\Creditos\Motor\Enums\ReglaNoLaborable;
use App\Services\Creditos\Motor\Enums\TopeMoraTipo;
use App\Services\Creditos\Motor\Enums\UnidadTasa;
use App\Services\Creditos\Motor\Fecha;
use App\Services\Creditos\Motor\Tasa;
use App\Services\Creditos\Reloj;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Base de los tests de la Fase 3 contra Postgres real (sistemafe_test_migrations), con
 * transacción revertida por test y "hoy" fijo en America/Lima. Montos en centavos salvo
 * donde se lee la BD (soles con 2 decimales).
 */
abstract class CreditosTestCase extends TestCase
{
    protected const TODOS_LOS_PERMISOS = [
        'creditos.ver', 'creditos.ver_todos', 'creditos.crear', 'creditos.cobrar', 'creditos.anular_pago',
        'creditos.condonar_mora', 'creditos.corregir', 'creditos.configurar', 'creditos.cartera.asignar',
        'creditos.autorizar_excepcion', 'creditos.reprogramar', 'creditos.castigar', 'creditos.migrar',
        'creditos.pago_fecha_anterior', 'cash.close_others_session',
    ];

    protected PaymentMethod $efectivo;

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
            'cache.default' => 'array',
        ]);
        DB::purge('pgsql');
        DB::purge('central');
        DB::beginTransaction();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->asegurarRolPorDefecto();
        // Configuración neutra para los ejemplos del plan: todos los días laborables.
        DB::table('credito_configuracion')->update(['dias_no_laborables' => '[]']);

        $this->efectivo = PaymentMethod::firstOrCreate(
            ['code' => 'EFECTIVO'],
            ['name' => 'Efectivo', 'is_active' => true, 'sort_order' => 1, 'affects_cash_count' => true],
        );
        $this->hoy('2026-01-01');
    }

    /** UserFactory usa role_id=1 (FK real a roles). */
    protected function asegurarRolPorDefecto(): void
    {
        if (! DB::table('roles')->where('id', 1)->exists()) {
            DB::table('roles')->insert(['id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api', 'created_at' => now(), 'updated_at' => now()]);
            DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");
        }
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    /**
     * Fija "hoy" (America/Lima) para servicios y motor, y mueve también now() de Laravel: los
     * created_at (UTC) quedan coherentes con la fecha de negocio, como en producción.
     */
    protected function hoy(string $fecha, string $hora = '10:00:00'): void
    {
        $momento = CarbonImmutable::parse("{$fecha} {$hora}", Reloj::ZONA);
        $this->travelTo($momento);
        $this->app->instance(Reloj::class, new Reloj($momento));
    }

    /** @param list<string> $permisos */
    protected function usuario(array $permisos = self::TODOS_LOS_PERMISOS): User
    {
        $usuario = User::factory()->create();
        $rol = Role::create(['name' => 'rol-creditos-' . uniqid(), 'guard_name' => 'api']);
        foreach ($permisos as $permiso) {
            $rol->givePermissionTo(Permission::firstOrCreate(['guard_name' => 'api', 'name' => $permiso]));
        }
        $usuario->assignRole($rol);
        $usuario = $usuario->fresh();
        Auth::guard('api')->setUser($usuario);

        return $usuario;
    }

    protected function abrirCaja(User $usuario): CashSession
    {
        $branchId = Branch::query()->value('id') ?? Branch::create(['name' => 'Sede Créditos Test', 'is_active' => true])->id;
        $caja = CashRegister::create(['branch_id' => $branchId, 'name' => 'Caja ' . uniqid(), 'is_active' => true]);

        return CashSession::create([
            'cash_register_id' => $caja->id,
            'opened_by' => $usuario->id,
            'opening_amount' => 0,
            'opened_at' => now(),
            'status' => 'open',
        ]);
    }

    protected function cliente(): Client
    {
        return Client::create([
            'name' => 'Cliente',
            'surname' => 'Préstamo',
            'full_name' => 'Cliente Préstamo',
            'type_client' => 1,
            'type_document' => 'DNI',
            'n_document' => (string) random_int(10_000_000, 99_999_999),
        ]);
    }

    /** Ejemplo 1.7: 5,000 al 20% total en 10 cuotas de 600 cada 30 días (c1 31/01, c2 02/03, c3 01/04, c4 01/05). */
    protected function datos(
        int $clienteId,
        int $capital = 500_000,
        string $tasa = '20',
        int $cuotas = 10,
        string $desembolso = '2026-01-01',
        TopeMoraTipo $tope = TopeMoraTipo::PorcentajeCuota,
    ): DatosCredito {
        return new DatosCredito(
            $clienteId, $capital, Tasa::desdeTexto($tasa), UnidadTasa::Total, new Frecuencia(FrecuenciaUnidad::Dia, 30),
            $cuotas, Fecha::desdeTexto($desembolso), null, [], false, ReglaNoLaborable::Siguiente, true,
            Tasa::desdeTexto('10'), 0, $tope, $tope === TopeMoraTipo::SinTope ? null : 100, 10, $this->efectivo->id,
        );
    }

    protected function activo(User $usuario, ?Client $cliente = null, ?DatosCredito $datos = null): Credito
    {
        $cliente ??= $this->cliente();
        $borrador = app(CreditoBorradorService::class)->crear($datos ?? $this->datos($cliente->id), $usuario);

        return app(ActivacionService::class)->activar($borrador, $usuario);
    }

    protected function saldoCaja(int $sesionId): string
    {
        $neto = DB::table('cash_movements')->where('cash_session_id', $sesionId)
            ->selectRaw("coalesce(sum(case when direction = 'in' then amount else -amount end), 0) as neto")->value('neto');

        return number_format((float) $neto, 2, '.', '');
    }
}
