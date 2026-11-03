<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ConfiguracionAgenciaController;
use App\Http\Controllers\AgenciaViajes\CotizacionActividadController;
use App\Http\Controllers\AgenciaViajes\CotizacionController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\CrmMotivoResultado;
use App\Models\AgenciaViajes\Reserva;
use App\Models\Client\CrmAccion;
use App\Models\Client\CrmActividad;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AgenciaViajes\SeguimientoCotizacion;
use App\Services\HoraPeru;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Stancl\Tenancy\Tenancy;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// T-053 (CRM C6a) — columna «Seguimiento», filtro «Por seguir hoy» y «Extender vigencia».
// Postgres real (sistemafe_test_migrations), transacción por test revertida; mismo armado que
// CotizacionResultadoTest. «Hoy» es el martes 03/11/2026 en Perú (12:00).
class SeguimientoCotizacionTest extends TestCase
{
    private const HOY = '2026-11-03';

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

        Carbon::setTestNow(Carbon::parse('2026-11-03 17:00:00', 'UTC'));

        DB::table('roles')->insert([
            'id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");

        $tenant = new Tenant();
        $tenant->id = 'test-tenant-' . uniqid();
        app(Tenancy::class)->tenant = $tenant;

        $this->diasAviso(2);
    }

    protected function tearDown(): void
    {
        app(Tenancy::class)->tenant = null;
        Carbon::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private function usuario(array $permisos = ['cotizaciones.ver', 'cotizaciones.editar'], bool $sesion = true, string $nombre = 'Ana María'): User
    {
        $user = User::factory()->create(['email' => 'vendedor-' . uniqid() . '@agencia.test', 'name' => $nombre]);
        foreach ($permisos as $permiso) {
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => $permiso]);
            $user->givePermissionTo($permiso);
        }
        if ($sesion) {
            Auth::guard('api')->setUser($user->fresh());
        }

        return $user;
    }

    private function diasAviso(int $dias): void
    {
        if (ConfiguracionAgencia::query()->exists()) {
            ConfiguracionAgencia::query()->update(['dias_aviso_vencimiento_cotizacion' => $dias]);
        } else {
            ConfiguracionAgencia::create(['dias_aviso_vencimiento_cotizacion' => $dias]);
        }
    }

    private function cotizacion(array $extra = []): Cotizacion
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10_000_000, 99_999_999),
            'full_name' => 'T053 Cliente', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = DB::table('cotizaciones')->insertGetId(array_merge([
            'codigo_prefijo' => 'TEST', 'codigo' => 'T053-' . uniqid(), 'cliente_id' => $clienteId,
            'vendedor_id' => auth('api')->id(), 'destino' => 'Tarapoto',
            'origen' => Cotizacion::ORIGEN_COTIZADOR, 'created_at' => now(), 'updated_at' => now(),
        ], $extra));

        return Cotizacion::findOrFail($id);
    }

    private function alternativa(Cotizacion $cotizacion, string $estado, array $extra = []): Alternativa
    {
        return Alternativa::create(array_merge([
            'cotizacion_id' => $cotizacion->id, 'nombre' => 'Alternativa ' . uniqid(), 'estado' => $estado,
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ], $extra));
    }

    private function accion(Cotizacion $cotizacion, string $fecha, array $extra = []): CrmAccion
    {
        return CrmAccion::create(array_merge([
            'cliente_id' => $cotizacion->cliente_id, 'referencia_tipo' => 'cotizacion', 'referencia_id' => $cotizacion->id,
            'tipo' => CrmAccion::TIPO_LLAMAR, 'fecha' => $fecha, 'responsable_id' => auth('api')->id(),
        ], $extra));
    }

    /** Vigente todo el día $ymd de Perú (lo que guarda «Extender vigencia»). */
    private function vence(string $ymd): array
    {
        return ['fecha_envio' => '2026-10-30 15:00:00', 'fecha_vencimiento' => HoraPeru::finDiaUtc($ymd)];
    }

    private function listar(array $params = []): array
    {
        return app(CotizacionController::class)->index(new Request($params + ['per_page' => 100]))->getData(true)['cotizaciones'];
    }

    /** Ids del filtro «Por seguir hoy», en su orden, acotados a $ids (la base puede traer otras). */
    private function porSeguir(array $ids): array
    {
        return collect($this->listar(['por_seguir' => 1]))->pluck('id')->intersect($ids)->values()->all();
    }

    private function extender(Alternativa $alternativa, string $fecha)
    {
        return app(CotizacionActividadController::class)->extenderVigencia(new Request(['fecha' => $fecha]), (string) $alternativa->id);
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

    // ── CA1: acciones pendientes ─────────────────────────────────────

    public function test_por_seguir_trae_las_acciones_de_hoy_y_atrasadas_lo_mas_viejo_primero(): void
    {
        $this->usuario();
        $ayer = $this->cotizacion();
        $this->accion($ayer, '2026-11-02');
        $deHoy = $this->cotizacion();
        $this->accion($deHoy, self::HOY);
        $manana = $this->cotizacion();
        $this->alternativa($manana, 'borrador');
        $this->accion($manana, '2026-11-04');
        // Una acción ya hecha no cuenta.
        $hecha = $this->cotizacion();
        $this->accion($hecha, '2026-11-01', ['hecha_en' => now()]);

        $this->assertSame([$ayer->id, $deHoy->id], $this->porSeguir([$ayer->id, $deHoy->id, $manana->id, $hecha->id]));

        // Sin el filtro, todas y en el orden de siempre (id desc).
        $todas = collect($this->listar())->pluck('id')->intersect([$ayer->id, $deHoy->id, $manana->id, $hecha->id])->values()->all();
        $this->assertSame([$hecha->id, $manana->id, $deHoy->id, $ayer->id], $todas);
    }

    // ── CA2: vencimientos y cotizaciones cerradas ────────────────────

    public function test_por_seguir_trae_las_que_vencen_dentro_del_aviso_y_las_vencidas_abiertas(): void
    {
        $this->usuario();
        $pasadoManana = $this->cotizacion();
        $this->alternativa($pasadoManana, 'enviada', $this->vence('2026-11-05'));
        $enTres = $this->cotizacion();
        $this->alternativa($enTres, 'enviada', $this->vence('2026-11-06'));
        $vencidaHaceDiez = $this->cotizacion();
        $this->alternativa($vencidaHaceDiez, 'enviada', $this->vence('2026-10-24'));
        $sinVigencia = $this->cotizacion();
        $this->alternativa($sinVigencia, 'enviada', ['fecha_envio' => '2026-10-30 15:00:00']);
        // Revisión: la A venció, pero la B reenviada sigue vigente lejos → hay propuesta viva.
        $conOtraVigente = $this->cotizacion();
        $this->alternativa($conOtraVigente, 'enviada', $this->vence('2026-10-24'));
        $this->alternativa($conOtraVigente, 'enviada', $this->vence('2026-11-20'));

        $ganada = $this->cotizacion();
        $this->alternativa($ganada, 'aceptada');
        $this->accion($ganada, '2026-10-30');
        $perdida = $this->cotizacion(['perdida_en' => now()]);
        $this->alternativa($perdida, 'descartada');
        $this->accion($perdida, '2026-10-30');
        $ventaDirecta = $this->cotizacion(['origen' => Cotizacion::ORIGEN_VENTA_DIRECTA]);
        $this->accion($ventaDirecta, '2026-10-30');

        $ids = [$pasadoManana->id, $enTres->id, $vencidaHaceDiez->id, $sinVigencia->id, $conOtraVigente->id, $ganada->id, $perdida->id, $ventaDirecta->id];
        $this->assertSame([$vencidaHaceDiez->id, $pasadoManana->id], $this->porSeguir($ids));

        // Con 3 días de aviso entra la que vence en 3 días.
        $this->diasAviso(3);
        $this->assertSame([$vencidaHaceDiez->id, $pasadoManana->id, $enTres->id], $this->porSeguir($ids));
    }

    public function test_el_orden_mezcla_acciones_y_vencimientos_por_la_fecha_mas_urgente(): void
    {
        $this->usuario();
        $venceHoy = $this->cotizacion();
        $this->alternativa($venceHoy, 'enviada', $this->vence(self::HOY));
        $accionAtrasada = $this->cotizacion();
        $this->accion($accionAtrasada, '2026-11-01');
        // Acción para mañana pero vencida ayer: cuenta el vencimiento.
        $vencidaConAccion = $this->cotizacion();
        $this->alternativa($vencidaConAccion, 'enviada', $this->vence('2026-11-02'));
        $this->accion($vencidaConAccion, '2026-11-04');

        $this->assertSame(
            [$accionAtrasada->id, $vencidaConAccion->id, $venceHoy->id],
            $this->porSeguir([$venceHoy->id, $accionAtrasada->id, $vencidaConAccion->id])
        );
    }

    // ── CA3: textos de «Seguimiento» ─────────────────────────────────

    private function enMemoria(array $alternativas = [], array $atributos = []): Cotizacion
    {
        $cotizacion = new Cotizacion();
        $cotizacion->forceFill($atributos + ['origen' => Cotizacion::ORIGEN_COTIZADOR]);
        $cotizacion->setRelation('alternativas', collect($alternativas)->map(function (array $a) {
            $reserva = $a['_reserva'] ?? null;
            unset($a['_reserva']);
            $alternativa = new Alternativa();
            $alternativa->forceFill($a);
            $alternativa->setRelation('reserva', $reserva);

            return $alternativa;
        }));
        $cotizacion->setRelation('resultadoMotivo', null);

        return $cotizacion;
    }

    private function accionEnMemoria(string $fecha, string $tipo = CrmAccion::TIPO_LLAMAR, ?string $responsable = 'Ana María'): CrmAccion
    {
        $accion = new CrmAccion();
        $accion->forceFill(['tipo' => $tipo, 'fecha' => $fecha]);
        $accion->setRelation('responsable', $responsable ? (new User())->forceFill(['name' => $responsable]) : null);

        return $accion;
    }

    private function texto(Cotizacion $c, ?CrmAccion $accion = null, int $diasAviso = 2): array
    {
        return app(SeguimientoCotizacion::class)->texto($c, $accion, CarbonImmutable::parse(self::HOY), $diasAviso);
    }

    private function enviada(string $fechaEnvio, ?string $venceEl = null, ?string $vencimientoExacto = null): array
    {
        return ['estado' => 'enviada', 'fecha_envio' => $fechaEnvio, 'fecha_vencimiento' => $vencimientoExacto ?? ($venceEl ? HoraPeru::finDiaUtc($venceEl) : null)];
    }

    public function test_textos_de_perdida_ganada_borrador_y_descartada(): void
    {
        $perdida = $this->enMemoria([['estado' => 'descartada']], ['perdida_en' => '2026-11-01 15:00:00']);
        $this->assertSame(['texto' => 'Perdida', 'urgente' => false], $this->texto($perdida, $this->accionEnMemoria('2026-11-01')));
        $perdida->setRelation('resultadoMotivo', (new CrmMotivoResultado())->forceFill(['nombre' => 'Precio']));
        $this->assertSame(['texto' => 'Perdida: Precio', 'urgente' => false], $this->texto($perdida));

        $reservada = $this->enMemoria([['estado' => 'aceptada'], ['estado' => 'descartada']]);
        $this->assertSame(['texto' => 'Ganada', 'urgente' => false], $this->texto($reservada, $this->accionEnMemoria('2026-11-01')));
        $anulada = $this->enMemoria([['estado' => 'aceptada', '_reserva' => (new Reserva())->forceFill(['estado' => 'cancelada'])]]);
        $this->assertSame('anulada', $anulada->estadoResumen());
        $this->assertSame(['texto' => 'Ganada', 'urgente' => false], $this->texto($anulada));

        $this->assertSame(['texto' => 'Sin enviar', 'urgente' => false], $this->texto($this->enMemoria([['estado' => 'borrador']])));
        $this->assertSame(['texto' => 'Sin enviar', 'urgente' => false], $this->texto($this->enMemoria()));
        $this->assertSame(['texto' => 'Llamar hoy (Ana)', 'urgente' => true], $this->texto($this->enMemoria([['estado' => 'borrador']]), $this->accionEnMemoria(self::HOY)));

        $this->assertSame(['texto' => '', 'urgente' => false], $this->texto($this->enMemoria([['estado' => 'descartada']])));
    }

    public function test_textos_de_envio_y_vencimiento(): void
    {
        // Ejemplo completo de la tarea.
        $this->assertSame(
            ['texto' => 'Enviada hace 3 días · Vence mañana · llamar hoy (Ana)', 'urgente' => true],
            $this->texto($this->enMemoria([$this->enviada('2026-10-31 15:00:00', '2026-11-04')]), $this->accionEnMemoria(self::HOY))
        );
        $this->assertSame(['texto' => 'Enviada ayer · Vence en 2 días', 'urgente' => false],
            $this->texto($this->enMemoria([$this->enviada('2026-11-02 15:00:00', '2026-11-05')])));
        // Fuera del aviso, el vencimiento no sale.
        $this->assertSame(['texto' => 'Enviada ayer', 'urgente' => false],
            $this->texto($this->enMemoria([$this->enviada('2026-11-02 15:00:00', '2026-11-06')])));
        $this->assertSame(['texto' => 'Enviada hoy · Vence hoy', 'urgente' => true],
            $this->texto($this->enMemoria([$this->enviada('2026-11-03 14:00:00', self::HOY)])));
        // Sin vigencia configurada: solo el envío.
        $this->assertSame(['texto' => 'Enviada hace 5 días', 'urgente' => false],
            $this->texto($this->enMemoria([$this->enviada('2026-10-29 15:00:00')])));

        // El envío más reciente y la ÚLTIMA vigencia (revisión): mientras una siga vigente lejos,
        // ni la que vence mañana ni la ya vencida cuentan ni la ponen en rojo.
        $this->assertSame(['texto' => 'Enviada ayer', 'urgente' => false],
            $this->texto($this->enMemoria([
                $this->enviada('2026-10-25 15:00:00', '2026-11-04'),
                $this->enviada('2026-11-02 15:00:00', '2026-11-20'),
                $this->enviada('2026-10-20 15:00:00', '2026-11-01'),
            ])));
        $this->assertSame(['texto' => 'Enviada ayer · Vence en 2 días', 'urgente' => false],
            $this->texto($this->enMemoria([
                $this->enviada('2026-10-20 15:00:00', '2026-11-01'),
                $this->enviada('2026-11-02 15:00:00', '2026-11-05'),
            ])));
        // Una enviada sin vigencia: la cotización no vence.
        $this->assertSame(['texto' => 'Enviada ayer', 'urgente' => false],
            $this->texto($this->enMemoria([
                $this->enviada('2026-10-20 15:00:00', '2026-11-01'),
                $this->enviada('2026-11-02 15:00:00'),
            ])));

        // Todas vencidas: el vencimiento más reciente.
        $this->assertSame(['texto' => 'Enviada hace 10 días · Vencida hace 2 días', 'urgente' => true],
            $this->texto($this->enMemoria([
                $this->enviada('2026-10-24 15:00:00', '2026-11-01'),
                $this->enviada('2026-10-20 15:00:00', '2026-10-25'),
            ])));
        $this->assertSame(['texto' => 'Enviada hace 4 días · Vencida ayer', 'urgente' => true],
            $this->texto($this->enMemoria([$this->enviada('2026-10-30 15:00:00', '2026-11-02')])));
        // Venció hoy a las 09:00 de Perú.
        $this->assertSame(['texto' => 'Enviada hace 4 días · Vencida hoy', 'urgente' => true],
            $this->texto($this->enMemoria([$this->enviada('2026-10-30 15:00:00', null, '2026-11-03 14:00:00')])));
    }

    public function test_textos_de_la_accion_pendiente(): void
    {
        $enviada = [$this->enviada('2026-11-02 15:00:00')];

        $this->assertSame(['texto' => 'Enviada ayer · llamar mañana (Ana)', 'urgente' => false],
            $this->texto($this->enMemoria($enviada), $this->accionEnMemoria('2026-11-04')));
        $this->assertSame(['texto' => 'Enviada ayer · llamar el 05/11 (Ana)', 'urgente' => false],
            $this->texto($this->enMemoria($enviada), $this->accionEnMemoria('2026-11-05')));
        $this->assertSame(['texto' => 'Enviada ayer · llamar, atrasada 1 día (Ana)', 'urgente' => true],
            $this->texto($this->enMemoria($enviada), $this->accionEnMemoria('2026-11-02')));
        $this->assertSame(['texto' => 'Llamar, atrasada 2 días (Ana)', 'urgente' => true],
            $this->texto($this->enMemoria([['estado' => 'borrador']]), $this->accionEnMemoria('2026-11-01')));
        $this->assertSame(['texto' => 'Cobrar saldo hoy', 'urgente' => true],
            $this->texto($this->enMemoria(), $this->accionEnMemoria(self::HOY, CrmAccion::TIPO_COBRAR_SALDO, null)));
        // Una descartada con acción pendiente muestra la acción (sale en «Por seguir hoy» por ella).
        $this->assertSame(['texto' => 'Llamar hoy (Ana)', 'urgente' => true],
            $this->texto($this->enMemoria([['estado' => 'descartada']]), $this->accionEnMemoria(self::HOY)));
    }

    public function test_los_dias_son_de_calendario_de_peru_al_cruzar_la_medianoche(): void
    {
        // Enviada a las 21:00 del 02/11 en Perú (02:00 UTC del 03/11): fue ayer, no hoy.
        $this->assertSame('Enviada ayer',
            $this->texto($this->enMemoria([$this->enviada('2026-11-03 02:00:00')]))['texto']);

        // Esa misma noche (21:00 de Perú del 03/11 = 02:00 UTC del 04/11) se envía otra: hoy.
        Carbon::setTestNow(Carbon::parse('2026-11-04 02:00:00', 'UTC'));
        $this->assertSame(self::HOY, HoraPeru::hoyTexto());
        $this->assertSame('Enviada hoy · Vence mañana',
            $this->texto($this->enMemoria([$this->enviada('2026-11-04 02:00:00', '2026-11-04')]))['texto']);
    }

    public function test_el_listado_devuelve_el_seguimiento_de_cada_fila(): void
    {
        $this->usuario();
        $cotizacion = $this->cotizacion();
        $this->alternativa($cotizacion, 'enviada', ['fecha_envio' => '2026-10-31 15:00:00', 'fecha_vencimiento' => HoraPeru::finDiaUtc('2026-11-04')]);
        $this->accion($cotizacion, '2026-11-05', ['tipo' => CrmAccion::TIPO_WHATSAPP]);
        $this->accion($cotizacion, self::HOY);
        $perdida = $this->cotizacion([
            'perdida_en' => now(),
            'resultado_motivo_id' => CrmMotivoResultado::where('tipo', 'perdida')->where('nombre', 'Precio')->value('id'),
        ]);

        $filas = collect($this->listar())->keyBy('id');
        $this->assertSame(['texto' => 'Enviada hace 3 días · Vence mañana · llamar hoy (Ana)', 'urgente' => true], $filas[$cotizacion->id]['seguimiento']);
        $this->assertSame(['texto' => 'Perdida: Precio', 'urgente' => false], $filas[$perdida->id]['seguimiento']);
    }

    // ── CA4: sin consultas por fila ──────────────────────────────────

    private function consultasDelListado(array $params): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->listar($params);
        $cantidad = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $cantidad;
    }

    public function test_el_listado_hace_las_mismas_consultas_con_5_y_con_50_cotizaciones(): void
    {
        $this->usuario();
        $crear = function (int $n) {
            for ($i = 0; $i < $n; $i++) {
                $cotizacion = $this->cotizacion();
                $this->alternativa($cotizacion, 'enviada', $this->vence('2026-11-04'));
                $this->alternativa($cotizacion, 'borrador');
                $this->accion($cotizacion, '2026-11-02');
            }
        };

        $crear(5);
        $this->listar(); // calienta la caché de permisos
        $conCinco = $this->consultasDelListado([]);
        $conCincoPorSeguir = $this->consultasDelListado(['por_seguir' => 1]);

        $crear(45);
        $this->assertSame($conCinco, $this->consultasDelListado([]));
        $this->assertSame($conCincoPorSeguir, $this->consultasDelListado(['por_seguir' => 1]));
        $this->assertCount(50, collect($this->listar(['por_seguir' => 1]))->filter(fn ($c) => $c['seguimiento']['urgente']));
    }

    // ── CA5: extender vigencia ───────────────────────────────────────

    public function test_extender_una_vencida_la_deja_vigente_con_su_actividad_y_sale_del_filtro(): void
    {
        $this->usuario();
        $cotizacion = $this->cotizacion();
        $alternativa = $this->alternativa($cotizacion, 'enviada', $this->vence('2026-10-23') + ['nombre' => 'Alternativa A']);
        $this->assertSame('vencida', $cotizacion->fresh()->estadoResumen());
        $this->assertSame([$cotizacion->id], $this->porSeguir([$cotizacion->id]));

        $respuesta = $this->extender($alternativa, '2026-11-20');

        $this->assertSame(200, $respuesta->getStatusCode());
        $alternativa->refresh();
        $this->assertSame('enviada', $alternativa->estado);
        $this->assertSame('2026-11-21 05:00:00', $alternativa->fecha_vencimiento->format('Y-m-d H:i:s'));
        $this->assertSame('enviada', $cotizacion->fresh()->estadoResumen());
        $actividad = CrmActividad::deCotizacion($cotizacion->id)->where('tipo', CrmActividad::TIPO_ESTADO)->sole();
        $this->assertSame('Vigencia de «Alternativa A» extendida hasta el 20/11/2026 (antes: 23/10/2026)', $actividad->nota);
        $this->assertSame($alternativa->id, $actividad->alternativa_id);
        $this->assertSame([], $this->porSeguir([$cotizacion->id]));

        // Se puede acortar mientras no sea antes de hoy; hoy vale.
        $this->assertSame(200, $this->extender($alternativa, self::HOY)->getStatusCode());
        $this->assertSame('Enviada hace 4 días · Vence hoy', $this->texto($cotizacion->fresh(), null)['texto']);
    }

    public function test_extender_sin_vigencia_previa_lo_anota(): void
    {
        $this->usuario();
        $cotizacion = $this->cotizacion();
        $alternativa = $this->alternativa($cotizacion, 'enviada', ['nombre' => 'Alternativa B', 'fecha_envio' => '2026-11-01 15:00:00']);

        $this->assertSame(200, $this->extender($alternativa, '2026-11-10')->getStatusCode());
        $this->assertSame('Vigencia de «Alternativa B» extendida hasta el 10/11/2026 (antes: no tenía)',
            CrmActividad::deCotizacion($cotizacion->id)->value('nota'));
    }

    public function test_extender_rechaza_fecha_pasada_borrador_perdida_y_modificacion(): void
    {
        $this->usuario();
        $enviada = $this->alternativa($this->cotizacion(), 'enviada', $this->vence('2026-11-01'));
        $this->assertSame(422, $this->extender($enviada, '2026-11-02')->getStatusCode());
        $this->assertSame(422, $this->extender($enviada, '03/11/2026')->getStatusCode());

        $this->assertSame(422, $this->extender($this->alternativa($this->cotizacion(), 'borrador'), '2026-11-10')->getStatusCode());
        $this->assertSame(422, $this->extender($this->alternativa($this->cotizacion(['perdida_en' => now()]), 'enviada', $this->vence('2026-11-01')), '2026-11-10')->getStatusCode());
        $this->assertSame(422, $this->extender($this->alternativa($this->cotizacion(['origen' => Cotizacion::ORIGEN_MODIFICACION]), 'enviada', $this->vence('2026-11-01')), '2026-11-10')->getStatusCode());
        $this->assertSame(422, $this->extender($this->alternativa($this->cotizacion(['origen' => Cotizacion::ORIGEN_VENTA_DIRECTA]), 'enviada', $this->vence('2026-11-01')), '2026-11-10')->getStatusCode());

        $this->assertSame(HoraPeru::finDiaUtc('2026-11-01'), $enviada->fresh()->fecha_vencimiento->format('Y-m-d H:i:s'));
        $this->assertSame(0, CrmActividad::where('tipo', CrmActividad::TIPO_ESTADO)->whereIn('alternativa_id', [$enviada->id])->count());
    }

    public function test_extender_pide_permiso_de_edicion_y_respeta_la_policy(): void
    {
        $ruta = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => in_array('POST', $r->methods(), true) && $r->uri() === 'api/alternativas/{id}/extender-vigencia');
        $this->assertNotNull($ruta);
        $this->assertContains('permission:cotizaciones.editar', $ruta->gatherMiddleware());

        $this->usuario();
        $alternativa = $this->alternativa($this->cotizacion(), 'enviada', $this->vence('2026-11-01'));

        $this->usuario(['cotizaciones.ver', 'cotizaciones.editar']);
        $this->assertSame(403, $this->codigo(fn () => $this->extender($alternativa, '2026-11-10')));

        $this->usuario(['cotizaciones.ver', 'cotizaciones.editar', 'cotizaciones.ver_todas']);
        $this->assertSame(200, $this->extender($alternativa, '2026-11-10')->getStatusCode());
    }

    // ── CA6: lo que ve cada uno ──────────────────────────────────────

    public function test_un_vendedor_sin_ver_todas_no_ve_en_el_filtro_las_de_otro(): void
    {
        $this->usuario();
        $mia = $this->cotizacion();
        $this->accion($mia, '2026-11-01');

        $this->usuario();
        $ajena = $this->cotizacion();
        $this->accion($ajena, '2026-11-01');
        $this->assertSame([$ajena->id], $this->porSeguir([$mia->id, $ajena->id]));

        // Misma fecha: desempata id desc.
        $this->usuario(['cotizaciones.ver', 'cotizaciones.ver_todas']);
        $this->assertSame([$ajena->id, $mia->id], $this->porSeguir([$mia->id, $ajena->id]));
    }

    // ── Configuración: días de aviso ─────────────────────────────────

    public function test_configuracion_guarda_los_dias_de_aviso(): void
    {
        $controller = app(ConfiguracionAgenciaController::class);
        $actual = $controller->show()->getData(true)['configuracion_agencia'];

        $respuesta = $controller->update(new Request(['dias_aviso_vencimiento_cotizacion' => 5] + $actual));
        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame(5, ConfiguracionAgencia::first()->dias_aviso_vencimiento_cotizacion);
        $this->assertSame(5, app(SeguimientoCotizacion::class)->diasAviso());

        $this->assertSame(422, $controller->update(new Request(['dias_aviso_vencimiento_cotizacion' => 61] + $actual))->getStatusCode());
        $this->assertSame(422, $controller->update(new Request(['dias_aviso_vencimiento_cotizacion' => -1] + $actual))->getStatusCode());
    }
}
