<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ClientePerfilViajeroController;
use App\Http\Controllers\Client\ClienteLineaTiempoController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\CrmTipoViaje;
use App\Models\AgenciaViajes\Reserva;
use App\Models\Client\CrmAccion;
use App\Models\Client\CrmActividad;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\GiroActual;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Stancl\Tenancy\Tenancy;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// T-060 (CRM C5) — la ficha del cliente en Agencia: preferencias del viajero, resumen,
// «Atendido por última vez por» y línea de tiempo. Postgres real (sistemafe_test_migrations),
// transacción por test revertida; mismo armado que CrmAccionTest.
//
// El reloj arranca el 16-nov-2026 a las 21:00 de Lima (17-nov 02:00 UTC): si algo usara la fecha
// UTC en vez de HoraPeru, «hoy» ya sería mañana.
class CrmFichaClienteTest extends TestCase
{
    private const HOY = '2026-11-16';

    private int $minuto = 0;

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

        Carbon::setTestNow(Carbon::parse('2026-11-17 02:00:00', 'UTC'));

        DB::table('roles')->insert([
            'id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");

        $tenant = new Tenant();
        $tenant->id = 'test-tenant-' . uniqid();
        app(Tenancy::class)->tenant = $tenant;

        $this->app->instance(GiroActual::class, new GiroActual('agencia_viajes'));
    }

    protected function tearDown(): void
    {
        app(Tenancy::class)->tenant = null;
        Carbon::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private const PERMISOS_VENDEDOR = ['list_client', 'edit_client', 'cotizaciones.ver', 'cotizaciones.editar', 'reservas.ver'];
    private const PERMISOS_SUPERVISOR = ['list_client', 'edit_client', 'cotizaciones.ver_todas', 'reservas.ver_todas'];

    private function usuario(array $permisos = self::PERMISOS_SUPERVISOR, bool $sesion = true): User
    {
        $user = User::factory()->create(['email' => 'u-' . uniqid() . '@agencia.test']);
        foreach ($permisos as $permiso) {
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => $permiso]);
            $user->givePermissionTo($permiso);
        }
        if ($sesion) {
            Auth::guard('api')->setUser($user->fresh());
        }

        return $user;
    }

    private function cliente(): int
    {
        return DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10_000_000, 99_999_999),
            'full_name' => 'Ana Pérez', 'email' => 'ana@cliente.test', 'phone' => '987654321',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // Cada fixture avanza el reloj un minuto: el orden de la línea de tiempo queda determinado.
    private function avanzar(): void
    {
        $this->minuto++;
        Carbon::setTestNow(Carbon::parse('2026-11-17 02:00:00', 'UTC')->addMinutes($this->minuto));
    }

    private function cotizacion(int $clienteId, int $vendedorId, string $estado = 'borrador', string $origen = 'cotizador', array $extra = []): Alternativa
    {
        $this->avanzar();
        $cotizacionId = DB::table('cotizaciones')->insertGetId(array_merge([
            'codigo_prefijo' => 'TEST', 'codigo' => 'CDKM-' . uniqid(), 'cliente_id' => $clienteId,
            'vendedor_id' => $vendedorId, 'destino' => 'Tarapoto', 'origen' => $origen,
            'fecha_viaje_desde' => '2026-12-01', 'fecha_viaje_hasta' => '2026-12-04',
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Opción 1', 'estado' => $estado,
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
    }

    private function reserva(Alternativa $alternativa, string $desde = '2026-12-01', string $hasta = '2026-12-04', string $estado = 'activa'): Reserva
    {
        $this->avanzar();
        $alternativa->update(['estado' => 'aceptada']);

        return Reserva::create([
            'alternativa_id' => $alternativa->id, 'codigo' => 'R-' . uniqid(), 'estado' => $estado,
            'fecha_viaje_desde' => $desde, 'fecha_viaje_hasta' => $hasta,
        ]);
    }

    private function venta(Reserva $reserva, int $clienteId, float $total, array $extra = []): int
    {
        $this->avanzar();
        $ventaId = DB::table('sales')->insertGetId(array_merge([
            'user_id' => auth('api')->id(), 'client_id' => $clienteId, 'igv' => 0, 'total' => $total,
            'serie' => 'B001', 'tipo_comprobante_codigo' => '03', 'currency' => 'PEN', 'type' => 'sale',
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
        DB::table('reserva_ventas')->insert([
            'reserva_id' => $reserva->id, 'sale_id' => $ventaId, 'reserva_item_ids' => '[]', 'reserva_pasajero_ids' => '[]',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $ventaId;
    }

    private function pago(int $ventaId, float $monto): int
    {
        $this->avanzar();

        return DB::table('sale_payments')->insertGetId([
            'sale_id' => $ventaId, 'method_payment' => 'EFECTIVO', 'amount' => $monto, 'date_payment' => self::HOY,
            'moneda_pago' => 'PEN', 'monto_pagado' => $monto, 'tipo_cambio' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function actividad(int $clienteId, int $usuarioId, ?string $refTipo = null, ?int $refId = null, string $nota = 'Hablamos'): CrmActividad
    {
        $this->avanzar();

        return CrmActividad::create([
            'cliente_id' => $clienteId, 'referencia_tipo' => $refTipo, 'referencia_id' => $refId,
            'tipo' => 'llamada', 'nota' => $nota, 'usuario_id' => $usuarioId,
        ]);
    }

    private function controller(): ClienteLineaTiempoController
    {
        return app(ClienteLineaTiempoController::class);
    }

    private function linea(int $clienteId, array $query = []): array
    {
        return $this->controller()->index(new Request($query), (string) $clienteId)->getData(true);
    }

    private function resumen(int $clienteId): array
    {
        return $this->controller()->resumen((string) $clienteId)->getData(true);
    }

    private function guardarPerfil(int $clienteId, array $datos)
    {
        return app(ClientePerfilViajeroController::class)->update(new Request($datos), (string) $clienteId);
    }

    // ── CA1: preferencias ────────────────────────────────────────────

    public function test_guardar_preferencias_y_leerlas_como_las_lee_el_cotizador(): void
    {
        $this->usuario();
        $clienteId = $this->cliente();
        $naturaleza = CrmTipoViaje::where('nombre', 'Naturaleza')->value('id');
        $relax = CrmTipoViaje::where('nombre', 'Relax')->value('id');

        $sinPerfil = app(ClientePerfilViajeroController::class)->show((string) $clienteId)->getData(true);
        $this->assertNull($sinPerfil['perfil']);

        $respuesta = $this->guardarPerfil($clienteId, [
            'tipos_viaje' => [$relax, $naturaleza], 'presupuesto_por_persona' => 1500.5, 'moneda' => 'USD',
            'alimentacion' => ' Vegetariana ', 'movilidad' => '', 'hotel_preferido' => 'Boutique', 'notas' => '<b>Viaja con su perro</b>',
        ]);
        $this->assertSame(200, $respuesta->getStatusCode());

        $perfil = app(ClientePerfilViajeroController::class)->show((string) $clienteId)->getData(true)['perfil'];
        $this->assertSame([$relax, $naturaleza], $perfil['tipos_viaje']);
        $this->assertSame(['Naturaleza', 'Relax'], $perfil['tipos_viaje_nombres'], 'en el orden del catálogo');
        $this->assertEquals(1500.5, $perfil['presupuesto_por_persona']);
        $this->assertSame('USD', $perfil['moneda']);
        $this->assertSame('Vegetariana', $perfil['alimentacion']);
        $this->assertNull($perfil['movilidad']);
        $this->assertSame('<b>Viaja con su perro</b>', $perfil['notas'], 'se guarda como texto, sin tocar');
        $this->assertFalse($perfil['vacio']);

        // Un tipo desactivado se sigue mostrando en el perfil que lo tiene.
        CrmTipoViaje::whereKey($relax)->update(['activo' => false]);
        $perfil = app(ClientePerfilViajeroController::class)->show((string) $clienteId)->getData(true)['perfil'];
        $this->assertContains('Relax', $perfil['tipos_viaje_nombres']);

        // Guardar de nuevo reemplaza: una sola fila por cliente.
        $this->guardarPerfil($clienteId, ['tipos_viaje' => []]);
        $this->assertSame(1, DB::table('cliente_perfil_viajero')->where('cliente_id', $clienteId)->count());
        $perfil = app(ClientePerfilViajeroController::class)->show((string) $clienteId)->getData(true)['perfil'];
        $this->assertTrue($perfil['vacio']);
        $this->assertNull($perfil['moneda'], 'sin presupuesto no queda moneda suelta');
    }

    public function test_preferencias_validan_presupuesto_largos_y_tipos(): void
    {
        $this->usuario();
        $clienteId = $this->cliente();

        $casos = [
            'presupuesto negativo' => ['tipos_viaje' => [], 'presupuesto_por_persona' => -1, 'moneda' => 'PEN'],
            'presupuesto sin moneda' => ['tipos_viaje' => [], 'presupuesto_por_persona' => 100],
            'moneda inválida' => ['tipos_viaje' => [], 'presupuesto_por_persona' => 100, 'moneda' => 'EUR'],
            'texto largo' => ['tipos_viaje' => [], 'alimentacion' => str_repeat('a', 151)],
            'notas largas' => ['tipos_viaje' => [], 'notas' => str_repeat('a', 2001)],
            'tipo inexistente' => ['tipos_viaje' => [999999]],
            'sin tipos_viaje' => ['notas' => 'x'],
        ];
        foreach ($casos as $caso => $datos) {
            $this->assertSame(422, $this->guardarPerfil($clienteId, $datos)->getStatusCode(), $caso);
        }
        $this->assertSame(0, DB::table('cliente_perfil_viajero')->where('cliente_id', $clienteId)->count());
    }

    // ── CA2: todo en orden de fecha ──────────────────────────────────

    public function test_linea_de_tiempo_con_dos_cotizaciones_una_reserva_facturada_y_tres_actividades_en_orden(): void
    {
        $vendedor = $this->usuario();
        $clienteId = $this->cliente();

        $primera = $this->cotizacion($clienteId, $vendedor->id, 'enviada');
        $a1 = $this->actividad($clienteId, $vendedor->id, 'cotizacion', $primera->cotizacion_id);
        $segunda = $this->cotizacion($clienteId, $vendedor->id);
        $a2 = $this->actividad($clienteId, $vendedor->id);
        $reserva = $this->reserva($segunda);
        $venta = $this->venta($reserva, $clienteId, 500);
        $pago = $this->pago($venta, 500);
        $a3 = $this->actividad($clienteId, $vendedor->id, 'reserva', $reserva->id);

        $ids = array_column($this->linea($clienteId)['eventos'], 'id');

        $this->assertSame([
            "actividad-{$a3->id}", "pago-{$pago}", "comprobante-{$venta}", "reserva-{$reserva->id}",
            "actividad-{$a2->id}", "cotizacion-{$segunda->cotizacion_id}", "actividad-{$a1->id}", "cotizacion-{$primera->cotizacion_id}",
        ], $ids);

        $eventos = collect($this->linea($clienteId)['eventos'])->keyBy('id');
        $this->assertSame('reservada', $eventos["cotizacion-{$segunda->cotizacion_id}"]['estado']);
        $this->assertSame('enviada', $eventos["cotizacion-{$primera->cotizacion_id}"]['estado']);
        $this->assertSame(['reserva_id' => $reserva->id], $eventos["comprobante-{$venta}"]['enlace']);
        $this->assertStringEndsWith('Z', $eventos["pago-{$pago}"]['fecha'], 'instante en UTC');

        // Filtro por tipo.
        $soloActividades = array_column($this->linea($clienteId, ['tipo' => 'actividad'])['eventos'], 'id');
        $this->assertSame(["actividad-{$a3->id}", "actividad-{$a2->id}", "actividad-{$a1->id}"], $soloActividades);
        $this->assertSame(422, $this->controller()->index(new Request(['tipo' => 'otra']), (string) $clienteId)->getStatusCode());
    }

    public function test_acciones_pendientes_por_su_dia_y_hechas_por_cuando_se_hicieron(): void
    {
        $vendedor = $this->usuario();
        $clienteId = $this->cliente();

        $this->avanzar();
        $hecha = CrmAccion::create([
            'cliente_id' => $clienteId, 'tipo' => 'llamar', 'fecha' => '2026-11-10', 'responsable_id' => $vendedor->id,
            'hecha_en' => now(), 'hecha_por' => $vendedor->id,
        ]);
        $actividad = $this->actividad($clienteId, $vendedor->id);
        $pendiente = CrmAccion::create(['cliente_id' => $clienteId, 'tipo' => 'saludar', 'fecha' => '2026-11-20', 'responsable_id' => $vendedor->id]);

        $eventos = $this->linea($clienteId)['eventos'];

        $this->assertSame(["accion-{$pendiente->id}", "actividad-{$actividad->id}", "accion-{$hecha->id}"], array_column($eventos, 'id'));
        $this->assertTrue($eventos[0]['pendiente']);
        $this->assertSame('2026-11-20', $eventos[0]['dia']);
        $this->assertSame('2026-11-20T05:00:00.000000Z', $eventos[0]['fecha'], '00:00 de Perú');
        $this->assertFalse($eventos[2]['pendiente']);
    }

    // ── CA3: «Atendido por última vez por» ───────────────────────────

    public function test_atendido_por_ultima_vez_es_el_usuario_de_lo_mas_reciente(): void
    {
        $this->usuario();
        $clienteId = $this->cliente();
        $ana = $this->usuario(sesion: false);
        $beto = $this->usuario(sesion: false);
        $carla = $this->usuario(sesion: false);

        $this->assertNull($this->resumen($clienteId)['atendido_por']);

        $this->cotizacion($clienteId, $ana->id);
        $this->assertSame($ana->id, $this->resumen($clienteId)['atendido_por']['usuario_id']);

        $this->actividad($clienteId, $beto->id);
        $this->assertSame($beto->id, $this->resumen($clienteId)['atendido_por']['usuario_id'], 'la actividad es posterior');

        $this->cotizacion($clienteId, $carla->id);
        $atendido = $this->resumen($clienteId)['atendido_por'];
        $this->assertSame($carla->id, $atendido['usuario_id'], 'la cotización es posterior');
        $this->assertSame(trim($carla->name . ' ' . $carla->surname), $atendido['nombre']);
    }

    // ── CA4: paginada y sin consultas por elemento ───────────────────

    public function test_linea_de_tiempo_paginada_con_una_cantidad_fija_de_consultas(): void
    {
        $vendedor = $this->usuario();
        $clienteId = $this->cliente();

        $armar = function (int $veces) use ($clienteId, $vendedor) {
            for ($i = 0; $i < $veces; $i++) {
                $cotizacion = $this->cotizacion($clienteId, $vendedor->id, 'enviada');
                $this->actividad($clienteId, $vendedor->id, 'cotizacion', $cotizacion->cotizacion_id);
                $reserva = $this->reserva($this->cotizacion($clienteId, $vendedor->id));
                $this->pago($this->venta($reserva, $clienteId, 100), 100);
                CrmAccion::create(['cliente_id' => $clienteId, 'referencia_tipo' => 'reserva', 'referencia_id' => $reserva->id,
                    'tipo' => 'cobrar_saldo', 'fecha' => self::HOY, 'responsable_id' => $vendedor->id]);
            }
        };

        $contar = function () use ($clienteId): int {
            // La primera llamada carga el caché de permisos de Spatie (3 consultas que no son de la
            // línea de tiempo): se cuenta la segunda.
            $this->linea($clienteId);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->linea($clienteId);
            $consultas = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $consultas;
        };

        $armar(2);
        $pocas = $contar();
        $armar(6);
        $muchas = $contar();

        $this->assertSame($pocas, $muchas, 'la cantidad de consultas no crece con los elementos');

        // 8 vueltas × 8 eventos (2 cotizaciones, 1 actividad, 1 reserva, 1 comprobante, 1 pago, 1 acción) = 56.
        $pagina1 = $this->linea($clienteId);
        $pagina3 = $this->linea($clienteId, ['pagina' => 3]);
        $this->assertCount(20, $pagina1['eventos']);
        $this->assertTrue($pagina1['hay_mas']);
        $this->assertCount(16, $pagina3['eventos']);
        $this->assertFalse($pagina3['hay_mas']);

        $todas = array_merge($pagina1['eventos'], $this->linea($clienteId, ['pagina' => 2])['eventos'], $pagina3['eventos']);
        $this->assertCount(56, array_unique(array_column($todas, 'id')), 'sin repetidos ni huecos entre páginas');
    }

    // ── Visibilidad (Policy) ─────────────────────────────────────────

    public function test_un_vendedor_sin_ver_todas_solo_ve_lo_suyo_y_lo_sin_referencia(): void
    {
        $yo = $this->usuario(self::PERMISOS_VENDEDOR, sesion: false);
        $otro = $this->usuario(self::PERMISOS_VENDEDOR, sesion: false);
        $clienteId = $this->cliente();

        $mia = $this->cotizacion($clienteId, $yo->id);
        $ajena = $this->cotizacion($clienteId, $otro->id);
        $reservaAjena = $this->reserva($ajena);
        Auth::guard('api')->setUser($otro->fresh());
        $ventaAjena = $this->venta($reservaAjena, $clienteId, 300);
        $this->pago($ventaAjena, 300);
        $deLaAjena = $this->actividad($clienteId, $otro->id, 'cotizacion', $ajena->cotizacion_id);
        $deLaReservaAjena = $this->actividad($clienteId, $otro->id, 'reserva', $reservaAjena->id);
        $sinReferencia = $this->actividad($clienteId, $otro->id);
        $accionAjena = CrmAccion::create(['cliente_id' => $clienteId, 'referencia_tipo' => 'reserva', 'referencia_id' => $reservaAjena->id,
            'tipo' => 'cobrar_saldo', 'fecha' => self::HOY, 'responsable_id' => $otro->id]);

        Auth::guard('api')->setUser($yo->fresh());
        $ids = array_column($this->linea($clienteId)['eventos'], 'id');

        $this->assertEqualsCanonicalizing(["cotizacion-{$mia->cotizacion_id}", "actividad-{$sinReferencia->id}"], $ids);
        $this->assertNotContains("actividad-{$deLaAjena->id}", $ids);
        $this->assertNotContains("actividad-{$deLaReservaAjena->id}", $ids);
        $this->assertNotContains("accion-{$accionAjena->id}", $ids);

        $resumen = $this->resumen($clienteId);
        $this->assertSame(1, $resumen['resumen']['cotizaciones']);
        $this->assertSame(0, $resumen['resumen']['ganadas'], 'la ganada es del otro');
        $this->assertSame([], $resumen['resumen']['total_comprado']);
        $this->assertSame($otro->id, $resumen['atendido_por']['usuario_id'], 'el nombre se calcula sobre todo');

        // Con ver_todas lo ve todo.
        $this->usuario();
        $this->assertCount(9, $this->linea($clienteId)['eventos']);
    }

    // ── Resumen ──────────────────────────────────────────────────────

    public function test_resumen_ganadas_perdidas_viajes_ultimo_viaje_y_total_comprado(): void
    {
        $vendedor = $this->usuario();
        $clienteId = $this->cliente();

        // Viaje ya hecho (terminó ayer en Perú) con factura, NC parcial y un adelanto aplicado.
        $hecho = $this->reserva($this->cotizacion($clienteId, $vendedor->id), '2026-11-10', '2026-11-15');
        $factura = $this->venta($hecho, $clienteId, 800, ['serie' => 'F001', 'tipo_comprobante_codigo' => '01']);
        $this->avanzar();
        DB::table('notes')->insert([
            'sale_id' => $factura, 'tipo_doc' => '07', 'tipo_doc_afectado' => '01', 'serie_afectada' => 'F001', 'correlativo_afectado' => '1', 'serie' => 'FC01', 'cod_motivo' => '07', 'des_motivo' => 'Devolución',
            'tipo_afectacion' => 'parcial', 'client_id' => $clienteId, 'cod_tipo_doc_cliente' => '1', 'user_id' => $vendedor->id, 'currency' => 'PEN', 'mto_imp_venta' => 50,
            'status' => 'pendiente', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $adelantoVenta = DB::table('sales')->insertGetId([
            'user_id' => $vendedor->id, 'client_id' => $clienteId, 'igv' => 0, 'total' => 200, 'serie' => 'B001',
            'tipo_comprobante_codigo' => '03', 'currency' => 'PEN', 'type' => 'advance', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $adelanto = DB::table('advances')->insertGetId([
            'client_id' => $clienteId, 'sale_id' => $adelantoVenta, 'amount' => 200, 'currency' => 'PEN', 'status' => 'applied',
            'payment_method' => 'EFECTIVO', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('reserva_anticipos')->insert([
            'reserva_id' => $hecho->id, 'advance_id' => $adelanto, 'monto_asignado' => 200, 'fecha_asignacion' => self::HOY,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Viaje que termina hoy: todavía no es «realizado», pero sí el último iniciado.
        $hoy = $this->reserva($this->cotizacion($clienteId, $vendedor->id), '2026-11-14', self::HOY);
        $this->venta($hoy, $clienteId, 100, ['currency' => 'USD']);
        // Futuro y cancelado: no cuentan.
        $this->reserva($this->cotizacion($clienteId, $vendedor->id), '2026-12-20', '2026-12-24');
        $this->reserva($this->cotizacion($clienteId, $vendedor->id), '2026-11-01', '2026-11-03', 'cancelada');
        // Perdida, vencida y en borrador.
        $this->cotizacion($clienteId, $vendedor->id, 'descartada', extra: ['perdida_en' => now()]);
        $this->cotizacion($clienteId, $vendedor->id, 'descartada');
        $this->cotizacion($clienteId, $vendedor->id);
        // Venta directa: su reserva cuenta como viaje, su cotización no.
        $directa = $this->reserva($this->cotizacion($clienteId, $vendedor->id, origen: 'venta_directa'), '2026-11-01', '2026-11-02');

        $resumen = $this->resumen($clienteId)['resumen'];

        $this->assertSame(7, $resumen['cotizaciones'], 'sin la de venta directa');
        $this->assertSame(3, $resumen['ganadas'], 'reservadas; la anulada no cuenta');
        $this->assertSame(1, $resumen['perdidas']);
        $this->assertSame(2, $resumen['viajes_realizados'], 'el de ayer y la venta directa');
        $this->assertSame($hoy->id, $resumen['ultimo_viaje']['reserva_id']);
        $this->assertEquals([
            ['moneda' => 'PEN', 'monto' => 950.0],
            ['moneda' => 'USD', 'monto' => 100.0],
        ], $resumen['total_comprado'], 'PEN: 800 + adelanto 200 − NC 50');

        $ids = array_column($this->linea($clienteId)['eventos'], 'id');
        $this->assertContains("reserva-{$directa->id}", $ids);
        $this->assertNotContains("cotizacion-{$directa->alternativa->cotizacion_id}", $ids);
        $this->assertContains("comprobante-{$adelantoVenta}", $ids, 'el comprobante del adelanto, por reserva_anticipos');
    }

    public function test_la_factura_anulada_con_nc_total_sigue_en_el_historial_por_sus_lineas(): void
    {
        $vendedor = $this->usuario();
        $clienteId = $this->cliente();
        $alternativa = $this->cotizacion($clienteId, $vendedor->id);
        $alternativaItem = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => AlternativaItem::ORIGEN_MANUAL,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 250, 'precio_venta_snapshot' => 300, 'precio_convertido' => 300,
            'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ]);
        $reserva = $this->reserva($alternativa);
        $item = DB::table('reserva_items')->insertGetId([
            'reserva_id' => $reserva->id, 'alternativa_item_id' => $alternativaItem->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $venta = $this->venta($reserva, $clienteId, 300);
        $detalle = DB::table('sale_details')->insertGetId([
            'sale_id' => $venta, 'product_id' => DB::table('products')->value('id'),
            'product_categorie_id' => DB::table('categories')->value('id'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sale_detail_items')->insert(['sale_detail_id' => $detalle, 'reserva_item_id' => $item, 'created_at' => now(), 'updated_at' => now()]);
        // La NC total borra la fila de reserva_ventas (ReservaVenta::liberarPorNotaDeCredito()).
        DB::table('notes')->insert([
            'sale_id' => $venta, 'tipo_doc' => '07', 'tipo_doc_afectado' => '03', 'serie_afectada' => 'B001', 'correlativo_afectado' => '1', 'serie' => 'BC01', 'cod_motivo' => '01', 'des_motivo' => 'Anulación',
            'tipo_afectacion' => 'total', 'client_id' => $clienteId, 'cod_tipo_doc_cliente' => '1', 'user_id' => $vendedor->id, 'currency' => 'PEN', 'mto_imp_venta' => 300,
            'status' => 'pendiente', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('reserva_ventas')->where('sale_id', $venta)->delete();

        $ids = array_column($this->linea($clienteId, ['tipo' => 'comprobante'])['eventos'], 'id');
        $this->assertContains("comprobante-{$venta}", $ids);
        $this->assertCount(2, $ids, 'la factura y su NC');
        $this->assertEquals([['moneda' => 'PEN', 'monto' => 0]], $this->resumen($clienteId)['resumen']['total_comprado']);
    }

    // ── CA5 (backend): fuera de Agencia ──────────────────────────────

    public function test_fuera_de_agencia_responde_404(): void
    {
        $this->usuario();
        $clienteId = $this->cliente();
        $this->app->instance(GiroActual::class, new GiroActual('retail'));

        foreach ([
            fn () => app(ClienteLineaTiempoController::class)->index(new Request(), (string) $clienteId),
            fn () => app(ClienteLineaTiempoController::class)->resumen((string) $clienteId),
            fn () => app(ClientePerfilViajeroController::class)->show((string) $clienteId),
        ] as $llamada) {
            try {
                $llamada();
                $this->fail('debía responder 404');
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }
    }
}
