<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\CotizacionActividadController;
use App\Http\Controllers\AgenciaViajes\CotizacionEnvioController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\Client\CrmAccionController;
use App\Http\Controllers\Client\CrmPendientesController;
use App\Services\Dashboard\DashboardService;
use App\Services\Tenancy\GiroActual;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\Reserva;
use App\Models\Central\TenantDominioCorreo;
use App\Models\Client\CrmAccion;
use App\Models\Client\CrmActividad;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AgenciaViajes\AlternativaPdfService;
use App\Services\AgenciaViajes\CondicionesGeneralesPdfService;
use App\Services\AgenciaViajes\CorreoAgenciaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Stancl\Tenancy\Tenancy;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// T-051 (CRM C2) — próximas acciones: la que se propone al enviar, "Agendar acción", "Marcar
// hecha" con resultado y siguiente, reprogramar y el cierre al aceptar la cotización. Postgres
// real (sistemafe_test_migrations), transacción por test revertida; mismo armado que
// CrmActividadCotizacionTest.
//
// El reloj queda en 02-nov-2026 21:00 de Lima (03-nov 02:00 UTC): si algo usara la fecha UTC
// en vez de HoraPeru, "hoy" ya sería mañana y los tests lo muestran.
class CrmAccionTest extends TestCase
{
    private const HOY = '2026-11-02';

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
            'mail.cotizaciones_respaldo' => 'cotizaciones@umbosystem.com',
        ]);
        DB::purge('pgsql');
        DB::beginTransaction();

        Carbon::setTestNow(Carbon::parse('2026-11-03 02:00:00', 'UTC'));

        DB::table('roles')->insert([
            'id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");

        $tenant = new Tenant();
        $tenant->id = 'test-tenant-' . uniqid();
        $tenant->correo_cotizaciones_habilitado = true;
        $tenant->correo_limite_diario = 50;
        app(Tenancy::class)->tenant = $tenant;

        $this->app->bind(CorreoAgenciaService::class, fn () => new class extends CorreoAgenciaService {
            public function dominio(): ?TenantDominioCorreo
            {
                return null;
            }
        });
        $this->mock(AlternativaPdfService::class, function ($mock) {
            $mock->shouldReceive('contenido')->andReturn(['bytes' => '%PDF-cotizacion', 'nombre' => 'Cotizacion-TEST.pdf']);
        });
        $this->mock(CondicionesGeneralesPdfService::class, function ($mock) {
            $mock->shouldReceive('contenido')->andReturn(['bytes' => '%PDF-condiciones', 'nombre' => 'Condiciones.pdf']);
        });
    }

    protected function tearDown(): void
    {
        app(Tenancy::class)->tenant = null;
        Carbon::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private const PERMISOS_VENDEDOR = ['cotizaciones.ver', 'cotizaciones.editar', 'cotizaciones.enviar_correo'];

    private function usuario(array $permisos = self::PERMISOS_VENDEDOR, bool $sesion = true): User
    {
        $user = User::factory()->create(['email' => 'vendedor-' . uniqid() . '@agencia.test']);
        foreach ($permisos as $permiso) {
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => $permiso]);
            $user->givePermissionTo($permiso);
        }
        if ($sesion) {
            $this->actuarComo($user);
        }

        return $user;
    }

    private function actuarComo(User $user): void
    {
        Auth::guard('api')->setUser($user->fresh());
    }

    private function cliente(): int
    {
        return DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10_000_000, 99_999_999),
            'full_name' => 'Ana Pérez', 'email' => 'ana@cliente.test', 'phone' => '987654321',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function alternativa(?int $vendedorId = null, string $estado = 'borrador'): Alternativa
    {
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'CDKM-' . uniqid(), 'cliente_id' => $this->cliente(),
            'vendedor_id' => $vendedorId ?? auth('api')->id(), 'destino' => 'Tarapoto',
            'fecha_viaje_desde' => '2026-11-15', 'fecha_viaje_hasta' => '2026-11-18',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Opción 1', 'estado' => $estado,
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
    }

    private function accion(Alternativa $alternativa, User $responsable, string $fecha = self::HOY, array $extra = []): CrmAccion
    {
        return CrmAccion::create(array_merge([
            'cliente_id' => $alternativa->cotizacion->cliente_id,
            'referencia_tipo' => 'cotizacion', 'referencia_id' => $alternativa->cotizacion_id,
            'tipo' => 'llamar', 'fecha' => $fecha, 'responsable_id' => $responsable->id,
        ], $extra));
    }

    private function controller(): CrmAccionController
    {
        return app(CrmAccionController::class);
    }

    private function marcarEnviada(Alternativa $alternativa, array $datos)
    {
        return app(CotizacionActividadController::class)->marcarEnviada(new Request($datos), (string) $alternativa->id);
    }

    private function enviarCorreo(Alternativa $alternativa, ?array $proximaAccion)
    {
        return app(CotizacionEnvioController::class)->enviar(new Request([
            'para' => ['ana@cliente.test'], 'cc' => [], 'asunto' => 'Cotización', 'mensaje' => 'Hola',
            'adjuntar_cotizacion' => true, 'adjuntar_condiciones' => false, 'marcar_enviada' => true,
            'proxima_accion' => $proximaAccion,
        ]), (string) $alternativa->id);
    }

    private function codigo(callable $llamada): int
    {
        try {
            return $llamada()->getStatusCode();
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }

    // ── CA1: enviar propone "Llamar" ─────────────────────────────────

    public function test_opciones_trae_los_dias_configurados_y_solo_usuarios_activos(): void
    {
        $this->usuario();
        ConfiguracionAgencia::query()->delete();
        ConfiguracionAgencia::create(['dias_proximo_contacto' => 3]);
        $inactivo = User::factory()->create(['email' => 'inactivo-' . uniqid() . '@agencia.test', 'state' => User::STATE_INACTIVO]);

        $datos = $this->controller()->opciones()->getData(true);

        $this->assertSame(3, $datos['dias_proximo_contacto']);
        $this->assertSame(auth('api')->id(), $datos['usuario_id']);
        $this->assertNotContains($inactivo->id, array_column($datos['responsables'], 'id'));
        $this->assertContains('llamar', array_column($datos['tipos'], 'valor'));
    }

    public function test_whatsapp_con_la_propuesta_crea_la_accion_llamar_a_nombre_de_quien_envia(): void
    {
        $vendedor = $this->usuario();
        $alternativa = $this->alternativa();

        $respuesta = $this->marcarEnviada($alternativa, [
            'canal' => 'whatsapp',
            'proxima_accion' => ['fecha' => '2026-11-04', 'responsable_id' => $vendedor->id],
        ]);

        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
        $accion = CrmAccion::sole();
        $this->assertSame('llamar', $accion->tipo);
        $this->assertSame('2026-11-04', $accion->fecha->toDateString());
        $this->assertSame($vendedor->id, $accion->responsable_id);
        $this->assertSame($vendedor->id, $accion->creada_por);
        $this->assertSame(['cotizacion', $alternativa->cotizacion_id], [$accion->referencia_tipo, $accion->referencia_id]);
        $this->assertSame($alternativa->cotizacion->cliente_id, $accion->cliente_id);
        $this->assertNull($accion->hecha_en);
    }

    public function test_correo_con_la_propuesta_crea_la_accion(): void
    {
        $vendedor = $this->usuario();
        $alternativa = $this->alternativa();
        Mail::fake();

        $respuesta = $this->enviarCorreo($alternativa, ['fecha' => '2026-11-04', 'responsable_id' => $vendedor->id]);

        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
        $this->assertSame(['llamar', '2026-11-04'], [CrmAccion::sole()->tipo, CrmAccion::sole()->fecha->toDateString()]);
    }

    public function test_la_propuesta_acepta_hoy_peru_y_rechaza_ayer_o_un_usuario_inactivo(): void
    {
        $vendedor = $this->usuario();
        $alternativa = $this->alternativa();
        $inactivo = User::factory()->create(['email' => 'inactivo-' . uniqid() . '@agencia.test', 'state' => User::STATE_INACTIVO]);

        // 21:00 de Lima: en UTC ya es 03-nov, pero "hoy" de negocio sigue siendo 02-nov.
        $hoy = $this->marcarEnviada($alternativa, ['canal' => 'otro', 'proxima_accion' => ['fecha' => self::HOY, 'responsable_id' => $vendedor->id]]);
        $ayer = $this->marcarEnviada($alternativa, ['canal' => 'otro', 'proxima_accion' => ['fecha' => '2026-11-01', 'responsable_id' => $vendedor->id]]);
        $deInactivo = $this->marcarEnviada($alternativa, ['canal' => 'otro', 'proxima_accion' => ['fecha' => self::HOY, 'responsable_id' => $inactivo->id]]);

        $this->assertSame(200, $hoy->getStatusCode(), $hoy->getContent());
        $this->assertSame(422, $ayer->getStatusCode());
        $this->assertSame('La fecha de la acción no puede ser anterior a hoy.', $ayer->getData(true)['message']);
        $this->assertSame(422, $deInactivo->getStatusCode());
        $this->assertSame(1, CrmAccion::count());
        // El envío rechazado no deja actividad: la validación corre antes que todo.
        $this->assertSame(1, CrmActividad::count());
    }

    // ── CA2: quitar la propuesta ─────────────────────────────────────

    public function test_enviar_quitando_la_propuesta_no_crea_ninguna_accion(): void
    {
        $this->usuario();
        Mail::fake();

        $this->assertSame(200, $this->marcarEnviada($this->alternativa(), ['canal' => 'whatsapp', 'proxima_accion' => null])->getStatusCode());
        $this->assertSame(200, $this->marcarEnviada($this->alternativa(), ['canal' => 'otro'])->getStatusCode());
        $this->assertSame(200, $this->enviarCorreo($this->alternativa(), null)->getStatusCode());

        $this->assertSame(0, CrmAccion::count());
        $this->assertSame(3, CrmActividad::where('tipo', 'envio')->count());
    }

    // ── CA3: aceptar cierra las pendientes ───────────────────────────

    public function test_aceptar_la_cotizacion_cierra_sus_acciones_pendientes_con_una_actividad(): void
    {
        $vendedor = $this->usuario([...self::PERMISOS_VENDEDOR, 'reservas.ver_todas']);
        $alternativa = $this->alternativa(estado: 'enviada');
        $pendiente1 = $this->accion($alternativa, $vendedor, '2026-11-04');
        $pendiente2 = $this->accion($alternativa, $vendedor, '2026-11-06', ['tipo' => 'whatsapp']);
        $yaHecha = $this->accion($alternativa, $vendedor, self::HOY, ['hecha_en' => now()->subDay()]);
        $deOtra = $this->accion($this->alternativa(estado: 'enviada'), $vendedor, '2026-11-04');

        $respuesta = app(ReservaController::class)->aceptar(new Request(), (string) $alternativa->id);

        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
        $this->assertNotNull($pendiente1->fresh()->hecha_en);
        $this->assertNotNull($pendiente2->fresh()->hecha_en);
        $this->assertSame($vendedor->id, $pendiente1->fresh()->hecha_por);
        $this->assertTrue($yaHecha->fresh()->hecha_en->lt(now()), 'una acción ya hecha no se toca');
        $this->assertNull($deOtra->fresh()->hecha_en, 'las acciones de otra cotización siguen pendientes');

        $estado = CrmActividad::where('tipo', 'estado')->sole();
        $this->assertSame($alternativa->cotizacion_id, $estado->referencia_id);
        $this->assertSame('Cotización aceptada: se cerraron las acciones pendientes «Llamar» del 04/11, «Escribir por WhatsApp» del 06/11.', $estado->nota);
    }

    public function test_aceptar_sin_acciones_pendientes_no_deja_actividad(): void
    {
        $this->usuario([...self::PERMISOS_VENDEDOR, 'reservas.ver_todas']);
        $alternativa = $this->alternativa(estado: 'enviada');

        $this->assertSame(200, app(ReservaController::class)->aceptar(new Request(), (string) $alternativa->id)->getStatusCode());
        $this->assertSame(0, CrmActividad::where('tipo', 'estado')->count());
    }

    // ── "Agendar acción" ─────────────────────────────────────────────

    public function test_agendar_desde_la_cotizacion_y_listar_sus_pendientes(): void
    {
        $vendedor = $this->usuario();
        $otro = $this->usuario(sesion: false);
        $alternativa = $this->alternativa();
        $clienteId = $alternativa->cotizacion->cliente_id;

        $respuesta = $this->controller()->store(new Request([
            'tipo' => 'cobrar_saldo', 'fecha' => '2026-11-10', 'responsable_id' => $otro->id,
            'descripcion' => '  Recordar el saldo  ', 'referencia_tipo' => 'cotizacion', 'referencia_id' => $alternativa->cotizacion_id,
        ]), (string) $clienteId);

        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
        $accion = CrmAccion::sole();
        $this->assertSame(['cobrar_saldo', 'Recordar el saldo', $otro->id, $vendedor->id], [$accion->tipo, $accion->descripcion, $accion->responsable_id, $accion->creada_por]);

        $lista = $this->controller()->index(new Request(['referencia_tipo' => 'cotizacion', 'referencia_id' => $alternativa->cotizacion_id]))->getData(true)['acciones'];
        $this->assertCount(1, $lista);
        $this->assertSame('Cobrar saldo', $lista[0]['tipo_etiqueta']);
        $this->assertTrue($lista[0]['puede_gestionar'], 'el vendedor puede editar su cotización');
    }

    public function test_agendar_con_una_cotizacion_ajena_o_de_otro_cliente_no_se_permite(): void
    {
        $dueno = $this->usuario(sesion: false);
        $ajena = $this->alternativa($dueno->id);
        $vendedor = $this->usuario();
        $propia = $this->alternativa();
        $datos = ['tipo' => 'llamar', 'fecha' => self::HOY, 'responsable_id' => $vendedor->id, 'referencia_tipo' => 'cotizacion'];

        $this->assertSame(403, $this->codigo(fn () => $this->controller()->store(
            new Request($datos + ['referencia_id' => $ajena->cotizacion_id]), (string) $ajena->cotizacion->cliente_id)));
        $deOtroCliente = $this->controller()->store(
            new Request($datos + ['referencia_id' => $propia->cotizacion_id]), (string) $ajena->cotizacion->cliente_id);
        $this->assertSame(422, $deOtroCliente->getStatusCode());
        $this->assertSame(0, CrmAccion::count());
    }

    public function test_agendar_desde_la_ficha_pide_editar_clientes(): void
    {
        $this->usuario();
        $clienteId = $this->cliente();
        $datos = ['tipo' => 'saludar', 'fecha' => '2026-12-24', 'responsable_id' => auth('api')->id()];

        $this->assertSame(403, $this->codigo(fn () => $this->controller()->store(new Request($datos), (string) $clienteId)));

        $this->usuario(['edit_client', 'list_client']);
        $datos['responsable_id'] = auth('api')->id();
        $this->assertSame(200, $this->controller()->store(new Request($datos), (string) $clienteId)->getStatusCode());
        $this->assertNull(CrmAccion::sole()->referencia_tipo);

        $deLaFicha = $this->controller()->index(new Request(['cliente_id' => $clienteId]))->getData(true)['acciones'];
        $this->assertCount(1, $deLaFicha);
    }

    public function test_agendar_desde_la_reserva_usa_reserva_policy(): void
    {
        $vendedor = $this->usuario([...self::PERMISOS_VENDEDOR, 'reservas.ver', 'reservas.editar']);
        $alternativa = $this->alternativa(estado: 'enviada');
        $reserva = Reserva::create(['alternativa_id' => $alternativa->id, 'codigo' => 'R-' . uniqid(), 'estado' => 'confirmada']);
        $clienteId = $alternativa->cotizacion->cliente_id;
        $datos = ['tipo' => 'cobrar_saldo', 'fecha' => self::HOY, 'responsable_id' => $vendedor->id, 'referencia_tipo' => 'reserva', 'referencia_id' => $reserva->id];

        $this->assertSame(200, $this->controller()->store(new Request($datos), (string) $clienteId)->getStatusCode());

        $this->usuario(['reservas.ver', 'reservas.editar']);
        $datos['responsable_id'] = auth('api')->id();
        $this->assertSame(403, $this->codigo(fn () => $this->controller()->store(new Request($datos), (string) $clienteId)));
        $this->assertSame(1, CrmAccion::where('referencia_tipo', 'reserva')->count());
    }

    // ── CA5: marcar hecha con resultado ──────────────────────────────

    public function test_marcar_hecha_con_resultado_crea_la_actividad_y_agenda_la_siguiente(): void
    {
        $vendedor = $this->usuario();
        $alternativa = $this->alternativa();
        $accion = $this->accion($alternativa, $vendedor);

        $respuesta = $this->controller()->marcarHecha(new Request([
            'resultado' => ['tipo' => 'llamada', 'nota' => 'Lo está pensando'],
            'siguiente' => ['tipo' => 'llamar', 'fecha' => '2026-11-09', 'responsable_id' => $vendedor->id],
        ]), (string) $accion->id);

        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
        $this->assertNotNull($accion->fresh()->hecha_en);
        $this->assertSame($vendedor->id, $accion->fresh()->hecha_por);

        $actividad = CrmActividad::sole();
        $this->assertSame(['llamada', 'Lo está pensando', 'cotizacion', $alternativa->cotizacion_id, $vendedor->id],
            [$actividad->tipo, $actividad->nota, $actividad->referencia_tipo, $actividad->referencia_id, $actividad->usuario_id]);

        $siguiente = CrmAccion::pendientes()->sole();
        $this->assertSame(['2026-11-09', $alternativa->cotizacion_id], [$siguiente->fecha->toDateString(), $siguiente->referencia_id]);
    }

    public function test_marcar_hecha_sin_resultado_ni_siguiente_solo_la_cierra_y_no_dos_veces(): void
    {
        $vendedor = $this->usuario();
        $accion = $this->accion($this->alternativa(), $vendedor);

        $this->assertSame(200, $this->controller()->marcarHecha(new Request([]), (string) $accion->id)->getStatusCode());
        $this->assertSame(0, CrmActividad::count());
        $this->assertSame(0, CrmAccion::pendientes()->count());

        $otraVez = $this->controller()->marcarHecha(new Request([]), (string) $accion->id);
        $this->assertSame(422, $otraVez->getStatusCode());
    }

    public function test_marcar_hecha_valida_resultado_y_siguiente(): void
    {
        $vendedor = $this->usuario();
        $accion = $this->accion($this->alternativa(), $vendedor);

        $sinNota = $this->controller()->marcarHecha(new Request(['resultado' => ['tipo' => 'nota']]), (string) $accion->id);
        $siguienteAyer = $this->controller()->marcarHecha(new Request([
            'siguiente' => ['tipo' => 'llamar', 'fecha' => '2026-11-01', 'responsable_id' => $vendedor->id],
        ]), (string) $accion->id);
        $tipoSistema = $this->controller()->marcarHecha(new Request(['resultado' => ['tipo' => 'envio']]), (string) $accion->id);

        $this->assertSame([422, 422, 422], [$sinNota->getStatusCode(), $siguienteAyer->getStatusCode(), $tipoSistema->getStatusCode()]);
        $this->assertNull($accion->fresh()->hecha_en);
    }

    // ── Quién gestiona una acción ────────────────────────────────────

    public function test_el_responsable_la_gestiona_aunque_no_sea_el_vendedor_y_un_tercero_no(): void
    {
        $dueno = $this->usuario(sesion: false);
        $alternativa = $this->alternativa($dueno->id);
        $responsable = $this->usuario(['cotizaciones.ver'], sesion: false);
        $accion = $this->accion($alternativa, $responsable);

        $this->usuario();
        $this->assertSame(403, $this->codigo(fn () => $this->controller()->marcarHecha(new Request([]), (string) $accion->id)));
        $this->assertSame(403, $this->codigo(fn () => $this->controller()->update(new Request(['fecha' => '2026-11-05']), (string) $accion->id)));

        $this->actuarComo($responsable);
        $this->assertSame(200, $this->controller()->update(new Request(['fecha' => '2026-11-05']), (string) $accion->id)->getStatusCode());
        $this->assertSame('2026-11-05', $accion->fresh()->fecha->toDateString());
        $this->assertSame(200, $this->controller()->marcarHecha(new Request([]), (string) $accion->id)->getStatusCode());

        $this->actuarComo($dueno);
        $otra = $this->accion($alternativa, $responsable);
        $this->assertSame(200, $this->controller()->marcarHecha(new Request([]), (string) $otra->id)->getStatusCode(), 'el vendedor de la cotización también');
    }

    public function test_reprogramar_no_acepta_una_fecha_pasada_ni_una_accion_hecha(): void
    {
        $vendedor = $this->usuario();
        $accion = $this->accion($this->alternativa(), $vendedor);

        $this->assertSame(422, $this->controller()->update(new Request(['fecha' => '2026-11-01']), (string) $accion->id)->getStatusCode());

        $accion->update(['hecha_en' => now()]);
        $this->assertSame(422, $this->controller()->update(new Request(['fecha' => '2026-11-05']), (string) $accion->id)->getStatusCode());
    }

    // ── CA4: "Mis pendientes de hoy" y la bandeja de Seguimiento ─────

    /** Acciones de $yo y de otro en distintas fechas; devuelve [ids por nombre]. */
    private function pendientesVariados(User $yo, User $otro): array
    {
        $alternativa = $this->alternativa($yo->id);

        return [
            'manana' => $this->accion($alternativa, $yo, '2026-11-03')->id,
            'hoy' => $this->accion($alternativa, $yo, self::HOY)->id,
            'atrasada' => $this->accion($alternativa, $yo, '2026-10-28', ['tipo' => 'cobrar_saldo'])->id,
            'lejana' => $this->accion($alternativa, $yo, '2026-11-20')->id,
            'hecha' => $this->accion($alternativa, $yo, '2026-10-30', ['hecha_en' => now()])->id,
            'de_otro' => $this->accion($alternativa, $otro, self::HOY)->id,
        ];
    }

    public function test_mis_pendientes_de_hoy_en_inicio_solo_las_del_usuario_hasta_hoy_atrasadas_primero(): void
    {
        $this->app->instance(GiroActual::class, new GiroActual('agencia_viajes'));
        $yo = $this->usuario();
        $ids = $this->pendientesVariados($yo, $this->usuario(sesion: false));

        $bloque = app(DashboardService::class)->inicio($yo->fresh())['bloques']['pendientes'];

        $this->assertSame(["crm-{$ids['atrasada']}", "crm-{$ids['hoy']}"], array_column($bloque['filas'], 'id'));
        $this->assertSame(['atrasadas', 'hoy'], array_column($bloque['filas'], 'bloque'));
        $this->assertSame('crm', $bloque['filas'][0]['fuente']);
        $this->assertSame('Ana Pérez', $bloque['filas'][0]['cliente']['nombre']);
        $this->assertSame('cotizacion', $bloque['filas'][0]['referencia']['tipo']);
        $this->assertStringStartsWith('CDKM-', $bloque['filas'][0]['referencia']['codigo']);
        $this->assertTrue($bloque['filas'][0]['accion']['puede_gestionar']);
        $this->assertFalse($bloque['puede_ver_todos']);
    }

    public function test_seguimiento_trae_las_propias_en_tres_bloques_hasta_siete_dias(): void
    {
        $yo = $this->usuario();
        $ids = $this->pendientesVariados($yo, $this->usuario(sesion: false));

        $datos = app(CrmPendientesController::class)->index(new Request())->getData(true);

        $this->assertSame(self::HOY, $datos['hoy']);
        $this->assertSame('2026-11-09', $datos['hasta']);
        $this->assertSame(
            ["crm-{$ids['atrasada']}", "crm-{$ids['hoy']}", "crm-{$ids['manana']}"],
            array_column($datos['filas'], 'id'),
        );
        $this->assertSame(['atrasadas', 'hoy', 'proximos'], array_column($datos['filas'], 'bloque'));

        $cobrar = app(CrmPendientesController::class)->index(new Request(['tipo' => 'cobrar_saldo']))->getData(true);
        $this->assertSame(["crm-{$ids['atrasada']}"], array_column($cobrar['filas'], 'id'));
    }

    public function test_de_todos_o_de_otro_responsable_solo_con_ver_todas(): void
    {
        $otro = $this->usuario(sesion: false);
        $yo = $this->usuario();
        $ids = $this->pendientesVariados($yo, $otro);
        $controller = app(CrmPendientesController::class);

        $this->assertSame(403, $this->codigo(fn () => $controller->index(new Request(['ambito' => 'todos']))));
        $this->assertSame(403, $this->codigo(fn () => $controller->index(new Request(['ambito' => 'todos', 'responsable_id' => $otro->id]))));

        $this->actuarComo($this->usuario(['cotizaciones.ver_todas'], sesion: false));
        $todos = $controller->index(new Request(['ambito' => 'todos']))->getData(true);
        $this->assertContains("crm-{$ids['de_otro']}", array_column($todos['filas'], 'id'));
        $this->assertCount(4, $todos['filas']);
        $this->assertTrue($todos['puede_ver_todos']);

        $delOtro = $controller->index(new Request(['ambito' => 'todos', 'responsable_id' => $otro->id]))->getData(true);
        $this->assertSame(["crm-{$ids['de_otro']}"], array_column($delOtro['filas'], 'id'));
    }
}
