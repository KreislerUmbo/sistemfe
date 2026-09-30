<?php

declare(strict_types=1);

namespace Tests\Feature\Creditos;

use App\Enums\Creditos\CreditoEstado;
use App\Enums\Creditos\OrigenFeriado;
use App\Enums\Creditos\TipoCorrelativo;
use App\Models\Client\Client;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoConfiguracion;
use App\Models\Creditos\CreditoCuota;
use App\Models\Creditos\CreditoPago;
use App\Models\Creditos\CreditoPagoAplicacion;
use App\Models\Creditos\Feriado;
use App\Services\Creditos\CorrelativoCreditoService;
use App\Services\Creditos\Motor\Enums\ConceptoAplicacion;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use App\Services\Creditos\Motor\Enums\EstadoCuota;
use App\Services\Creditos\Motor\Enums\FrecuenciaUnidad;
use App\Services\Creditos\Motor\Enums\OrigenPago;
use App\Services\Creditos\Motor\Enums\ReglaNoLaborable;
use App\Services\Creditos\Motor\Enums\TopeMoraTipo;
use App\Services\Creditos\Motor\Enums\UnidadTasa;
use App\Services\TenantProvisioningService;
use Database\Seeders\CreditosRolesSeeder;
use Database\Seeders\FeriadosNacionalesSeeder;
use Database\Seeders\MenuItemsSeeder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MenuResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Módulo Créditos — Fase 2 (datos) contra Postgres real (sistemafe_test_migrations),
 * transacción por test revertida en tearDown(); mismo patrón que CommercialQuoteControllerTest.
 */
class CreditosDatosTest extends TestCase
{
    /** Tablas creadas por la Fase 2 (plan §2 + decisiones de la Fase 2). */
    private const TABLAS = [
        'feriados', 'credito_configuracion', 'credito_correlativos', 'creditos', 'credito_cuotas', 'credito_pagos',
        'credito_pago_aplicaciones', 'credito_condonaciones', 'credito_castigos', 'credito_garantes', 'prendas',
        'prenda_fotos', 'cartera_asignaciones', 'credito_gestiones', 'credito_plantillas', 'credito_documentos',
        'credito_cartera_diaria', 'credito_cliente_limites', 'credito_autorizaciones', 'credito_reprogramaciones',
        'credito_reprogramacion_cuotas', 'credito_cargos', 'credito_cliente_fichas', 'credito_cliente_archivos',
        'credito_saldo_favor_movimientos', 'credito_auditoria',
    ];

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
        app('cache')->forgetDriver();
        DB::beginTransaction();
        DB::connection('central')->beginTransaction();   // menu_items vive en la central

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    private function cliente(): Client
    {
        return Client::create([
            'name' => 'Cliente',
            'surname' => 'Créditos',
            'full_name' => 'Cliente Créditos',
            'type_client' => 1,
            'type_document' => 'DNI',
            'n_document' => (string) random_int(10_000_000, 99_999_999),
        ]);
    }

    private function credito(Client $cliente, array $extra = []): Credito
    {
        return Credito::create([
            'cliente_id' => $cliente->id,
            'monto_capital' => '1000.00',
            'tasa_interes' => '20.0000',
            'unidad_tasa' => UnidadTasa::Total,
            'interes_total' => '200.00',
            'frecuencia_unidad' => FrecuenciaUnidad::Mes,
            'dias_no_laborables' => [7],
            'numero_cuotas' => 1,
            'fecha_desembolso' => '2026-09-10',
            'fecha_primer_vencimiento' => '2026-10-10',
            'tasa_interes_minimo' => '10.0000',
            'registrado_por' => 1,
            ...$extra,
        ]);
    }

    private function cuota(Credito $credito, int $version, int $numero): CreditoCuota
    {
        return CreditoCuota::create([
            'credito_id' => $credito->id,
            'version_cronograma' => $version,
            'numero_cuota' => $numero,
            'fecha_inicio_periodo' => '2026-09-10',
            'fecha_vencimiento' => '2026-10-10',
            'fecha_vencimiento_original' => '2026-10-10',
            'monto_capital' => '1000.00',
            'monto_interes' => '200.00',
            'monto_total' => '1200.00',
        ]);
    }

    private function pago(Credito $credito, string $clave): CreditoPago
    {
        return CreditoPago::create([
            'credito_id' => $credito->id,
            'numero_recibo' => 'RC-' . $clave,
            'monto_recibido' => '1400.00',
            'monto_aplicado' => '1400.00',
            'fecha_pago' => '2026-10-15 10:30:00',
            'clave_idempotencia' => $clave,
            'registrado_por' => 1,
        ]);
    }

    // ---- Esquema ----

    public function test_toda_columna_nueva_tiene_comentario(): void
    {
        $sinComentario = DB::select(
            "select c.table_name, c.column_name
               from information_schema.columns c
              where c.table_schema = current_schema()
                and c.table_name = any(?)
                and c.column_name not in ('id', 'created_at', 'updated_at')
                and col_description((quote_ident(c.table_name))::regclass, c.ordinal_position) is null",
            ['{' . implode(',', self::TABLAS) . '}'],
        );

        $this->assertSame([], array_map(static fn ($c): string => "{$c->table_name}.{$c->column_name}", $sinComentario));
    }

    public function test_configuracion_sembrada_con_los_defaults_del_plan_y_fila_unica(): void
    {
        $config = CreditoConfiguracion::actual();

        $this->assertSame([7], $config->dias_no_laborables);
        $this->assertSame('10.0000', $config->tasa_interes_minimo);
        $this->assertSame('0.10', $config->paso_redondeo);
        $this->assertSame(ReglaNoLaborable::Siguiente, $config->regla_no_laborable);
        $this->assertSame(TopeMoraTipo::PorcentajeCuota, $config->tope_mora_tipo);
        $this->assertSame(100, $config->tope_mora_valor);
        $this->assertSame(2, $config->max_creditos_activos);
        $this->assertSame(90, $config->dias_para_castigo);

        $this->expectException(QueryException::class);
        DB::table('credito_configuracion')->insert(['fila_unica' => false, 'dias_no_laborables' => '[]']);
    }

    // ---- Correlativos ----

    public function test_correlativos_independientes_por_tipo(): void
    {
        $servicio = new CorrelativoCreditoService();
        $inicialCredito = DB::table('credito_correlativos')->where('tipo', 'credito')->value('ultimo_numero');

        $primero = $servicio->siguiente(TipoCorrelativo::Credito);
        $segundo = $servicio->siguiente(TipoCorrelativo::Credito);
        $recibo = $servicio->siguiente(TipoCorrelativo::Recibo);

        $this->assertSame(sprintf('CR-%08d', $inicialCredito + 1), $primero);
        $this->assertSame(sprintf('CR-%08d', $inicialCredito + 2), $segundo);
        $this->assertStringStartsWith('RC-', $recibo);
    }

    // ---- Modelos y enums ----

    public function test_credito_con_cuotas_pagos_y_aplicaciones_usa_los_enums(): void
    {
        $credito = $this->credito($this->cliente());
        $cuota = $this->cuota($credito, 1, 1);
        $pago = $this->pago($credito, 'clave-roundtrip');
        CreditoPagoAplicacion::create([
            'credito_id' => $credito->id, 'pago_id' => $pago->id, 'cuota_id' => $cuota->id,
            'concepto' => ConceptoAplicacion::Mora, 'monto' => '200.00',
        ]);

        $credito = Credito::with('cuotas', 'pagos.aplicaciones')->findOrFail($credito->id);
        $this->assertSame(CreditoEstado::Borrador, $credito->estado);
        $this->assertSame(UnidadTasa::Total, $credito->unidad_tasa);
        $this->assertSame([7], $credito->dias_no_laborables);
        $this->assertSame('1000.00', $credito->monto_capital);
        $this->assertSame(EstadoCuota::Pendiente, $credito->cuotas->first()->estado);

        $pago = $credito->pagos->first();
        $this->assertSame(OrigenPago::Cobro, $pago->origen);
        $this->assertFalse($pago->es_cierre);
        $this->assertNull($pago->destino_excedente);
        $this->assertSame(ConceptoAplicacion::Mora, $pago->aplicaciones->first()->concepto);
        $this->assertTrue($pago->aplicaciones->first()->vigente);
    }

    public function test_destino_excedente_usa_los_valores_del_motor(): void
    {
        $pago = $this->pago($this->credito($this->cliente()), 'clave-excedente');
        $pago->update(['destino_excedente' => DestinoExcedente::Devolver]);

        $this->assertSame('devolver', DB::table('credito_pagos')->where('id', $pago->id)->value('destino_excedente'));
    }

    public function test_cuotas_vigentes_filtra_por_la_version_actual(): void
    {
        $credito = $this->credito($this->cliente(), ['version_cronograma_actual' => 2]);
        $this->cuota($credito, 1, 1);
        $v2 = $this->cuota($credito, 2, 1);

        $this->assertSame([$v2->id], $credito->cuotasVigentes()->pluck('id')->all());
    }

    public function test_varios_borradores_sin_numero_no_chocan_en_el_indice_unico(): void
    {
        // Postgres trata cada NULL como distinto en un índice único: no hace falta índice parcial.
        $cliente = $this->cliente();
        $this->credito($cliente);
        $this->credito($cliente);

        $this->assertSame(2, Credito::where('cliente_id', $cliente->id)->whereNull('numero_credito')->count());
    }

    public function test_numero_de_credito_asignado_si_es_unico(): void
    {
        $cliente = $this->cliente();
        $this->credito($cliente, ['numero_credito' => 'CR-TEST-1']);

        $this->expectException(QueryException::class);
        $this->credito($cliente, ['numero_credito' => 'CR-TEST-1']);
    }

    public function test_clave_de_idempotencia_unica(): void
    {
        $credito = $this->credito($this->cliente());
        $this->pago($credito, 'clave-repetida');

        $this->expectException(QueryException::class);
        CreditoPago::create([
            'credito_id' => $credito->id, 'numero_recibo' => 'RC-otro', 'monto_recibido' => '1.00', 'monto_aplicado' => '1.00',
            'fecha_pago' => '2026-10-15', 'clave_idempotencia' => 'clave-repetida', 'registrado_por' => 1,
        ]);
    }

    public function test_estado_fuera_del_enum_lo_rechaza_la_base(): void
    {
        $this->expectException(QueryException::class);
        DB::table('creditos')->where('id', $this->credito($this->cliente())->id)->update(['estado' => 'inventado']);
    }

    // ---- Seeders y giro ----

    public function test_giro_creditos_es_valido(): void
    {
        $this->assertContains('creditos', TenantProvisioningService::GIROS_VALIDOS);
    }

    public function test_menu_real_del_giro_creditos_muestra_clientes_y_caja_y_oculta_retail(): void
    {
        // Con el seeder real y Super-Admin (que pasa todos los permisos): solo giros_excluidos oculta.
        (new MenuItemsSeeder())->run();
        // UserFactory usa role_id=1 (FK real a roles), igual que MenuResolverTest.
        if (! DB::table('roles')->where('id', 1)->exists()) {
            DB::table('roles')->insert(['id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api', 'created_at' => now(), 'updated_at' => now()]);
            DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");
        }
        $rol = Role::firstOrCreate(['guard_name' => 'api', 'name' => 'Super-Admin']);
        $admin = User::factory()->create();
        $admin->assignRole($rol);
        $admin = $admin->fresh();

        $creditos = $this->codigosDelMenu($admin, 'creditos');
        $agencia = $this->codigosDelMenu($admin, 'agencia_viajes');

        foreach (['comercial.clientes', 'caja', 'caja.turno_activo', 'caja.historial', 'configuraciones.metodos_pago'] as $visible) {
            $this->assertContains($visible, $creditos);
        }
        foreach (['comercial.categorias', 'comercial.productos', 'comercial.productos_listar', 'comercial.ventas',
            'comercial.ventas_mis_ventas', 'comercial.ventas_nc_nd', 'configuraciones.series', 'agencia'] as $oculto) {
            $this->assertNotContains($oculto, $creditos);
        }
        // Agencia no cambia: sigue viendo Ventas y Series.
        $this->assertContains('comercial.ventas_mis_ventas', $agencia);
        $this->assertContains('configuraciones.series', $agencia);
    }

    /** @return list<string> códigos de todo el árbol, aplanado */
    private function codigosDelMenu(User $usuario, string $giro): array
    {
        $arbol = (new MenuResolver())->paraUsuario($usuario, Tenant::make(['id' => "t-menu-{$giro}", 'giro' => $giro]));
        $codigos = [];
        $recorrer = function (array $nodos) use (&$recorrer, &$codigos): void {
            foreach ($nodos as $nodo) {
                $codigos[] = $nodo['codigo'];
                $recorrer($nodo['hijos']);
            }
        };
        $recorrer($arbol);

        return $codigos;
    }

    public function test_roles_del_giro_creditos(): void
    {
        (new CreditosRolesSeeder())->run();

        $admin = Role::findByName('Administrador de créditos', 'api');
        $cajero = Role::findByName('Cajero de créditos', 'api');
        $cobrador = Role::findByName('Cobrador', 'api');

        $this->assertTrue($admin->hasPermissionTo('creditos.castigar'));
        $this->assertTrue($admin->hasPermissionTo('cash.close_others_session'));
        $this->assertTrue($cajero->hasPermissionTo('creditos.cobrar'));
        $this->assertFalse($cajero->hasPermissionTo('creditos.anular_pago'));
        $this->assertTrue($cobrador->hasPermissionTo('creditos.cobrar'));
        $this->assertFalse($cobrador->hasPermissionTo('list_client'));   // solo su cartera (1.13)

        (new CreditosRolesSeeder())->run();   // idempotente
        $this->assertSame(1, Role::where('name', 'Cobrador')->where('guard_name', 'api')->count());
    }

    public function test_feriados_nacionales_con_semana_santa_calculada(): void
    {
        $seeder = new FeriadosNacionalesSeeder();

        $f2026 = $seeder->feriadosDe(2026);
        $this->assertCount(16, $f2026);
        $this->assertSame('Jueves Santo', $f2026['2026-04-02']);
        $this->assertSame('Viernes Santo', $f2026['2026-04-03']);
        $this->assertSame('Jueves Santo', $seeder->feriadosDe(2027)['2027-03-25']);
    }

    public function test_feriados_no_pisan_los_propios_y_son_idempotentes(): void
    {
        Feriado::where('fecha', '2026-12-25')->delete();
        Feriado::create(['fecha' => '2026-12-25', 'descripcion' => 'Navidad (cierre del local)', 'origen' => OrigenFeriado::Propio]);

        (new FeriadosNacionalesSeeder())->run([2026]);
        (new FeriadosNacionalesSeeder())->run([2026]);

        $navidad = Feriado::where('fecha', '2026-12-25')->sole();
        $this->assertSame(OrigenFeriado::Propio, $navidad->origen);
        $this->assertSame(16, Feriado::whereYear('fecha', 2026)->count());
    }
}
