<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\CotizacionActividadController;
use App\Http\Controllers\AgenciaViajes\CotizacionEnvioController;
use App\Http\Controllers\Client\CrmActividadController;
use App\Mail\AgenciaViajes\CotizacionClienteMail;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\Central\TenantDominioCorreo;
use App\Models\Client\CrmActividad;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AgenciaViajes\AlternativaPdfService;
use App\Services\AgenciaViajes\CondicionesGeneralesPdfService;
use App\Services\AgenciaViajes\CorreoAgenciaService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Stancl\Tenancy\Tenancy;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// T-050 (CRM C1) — "Enviar" la cotización por WhatsApp u otro medio, la actividad del envío
// por correo, "Anotar contacto" y el historial de la cotización. Postgres real
// (sistemafe_test_migrations), transacción por test revertida; mismo patrón que
// EnvioCorreoCotizacionTest.
class CrmActividadCotizacionTest extends TestCase
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
            'mail.cotizaciones_respaldo' => 'cotizaciones@umbosystem.com',
        ]);
        DB::purge('pgsql');
        DB::beginTransaction();

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

    private function usuario(array $permisos = ['cotizaciones.editar', 'cotizaciones.enviar_correo', 'cotizaciones.ver_todas']): User
    {
        $user = User::factory()->create(['email' => 'vendedor-' . uniqid() . '@agencia.test']);
        foreach ($permisos as $permiso) {
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => $permiso]);
            $user->givePermissionTo($permiso);
        }
        Auth::guard('api')->setUser($user->fresh());

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

    private function alternativa(string $estado = 'borrador', ?int $vendedorId = null, ?int $clienteId = null): Alternativa
    {
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'CDKM-' . uniqid(), 'cliente_id' => $clienteId ?? $this->cliente(),
            'vendedor_id' => $vendedorId, 'destino' => 'Tarapoto',
            'fecha_viaje_desde' => '2026-11-15', 'fecha_viaje_hasta' => '2026-11-18',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Opción 1', 'estado' => $estado,
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
    }

    private function marcarEnviada(Alternativa $alternativa, array $datos = ['canal' => 'otro'])
    {
        return app(CotizacionActividadController::class)->marcarEnviada(new Request($datos), (string) $alternativa->id);
    }

    private function diasVigencia(int $dias): void
    {
        ConfiguracionAgencia::query()->delete();
        ConfiguracionAgencia::create(['dias_vigencia_cotizacion' => $dias]);
    }

    // ── CA1 ──────────────────────────────────────────────────────────

    public function test_otro_medio_en_borrador_la_marca_enviada_con_vencimiento_y_deja_actividad(): void
    {
        $usuario = $this->usuario();
        $alternativa = $this->alternativa();
        $this->diasVigencia(7);

        $respuesta = $this->marcarEnviada($alternativa, ['canal' => 'otro', 'nota' => '  Se la entregué impresa en la oficina.  ']);

        $this->assertSame(200, $respuesta->getStatusCode(), json_encode($respuesta->getData(true)));
        $alternativa->refresh();
        $this->assertSame('enviada', $alternativa->estado);
        $this->assertNotNull($alternativa->fecha_envio);
        $this->assertSame(7, (int) round($alternativa->fecha_envio->diffInDays($alternativa->fecha_vencimiento, true)));

        $actividad = CrmActividad::sole();
        $this->assertSame('envio', $actividad->tipo);
        $this->assertSame('otro', $actividad->canal);
        $this->assertSame('Se la entregué impresa en la oficina.', $actividad->nota);
        $this->assertSame($alternativa->cotizacion->cliente_id, $actividad->cliente_id);
        $this->assertSame('cotizacion', $actividad->referencia_tipo);
        $this->assertSame($alternativa->cotizacion_id, $actividad->referencia_id);
        $this->assertSame($alternativa->id, $actividad->alternativa_id);
        $this->assertSame($usuario->id, $actividad->usuario_id);
    }

    public function test_whatsapp_sin_dias_de_vigencia_deja_el_vencimiento_vacio(): void
    {
        $this->usuario();
        $alternativa = $this->alternativa();
        ConfiguracionAgencia::query()->delete();

        $this->marcarEnviada($alternativa, ['canal' => 'whatsapp']);

        $alternativa->refresh();
        $this->assertSame('enviada', $alternativa->estado);
        $this->assertNull($alternativa->fecha_vencimiento);
        $this->assertSame('whatsapp', CrmActividad::sole()->canal);
    }

    // ── CA2 ──────────────────────────────────────────────────────────

    public function test_reenviar_deja_otra_actividad_sin_cambiar_la_fecha_de_envio(): void
    {
        $this->usuario();
        $alternativa = $this->alternativa();
        Carbon::setTestNow('2026-11-02 15:00:00');
        $this->marcarEnviada($alternativa, ['canal' => 'whatsapp']);
        $primerEnvio = $alternativa->fresh()->fecha_envio;

        Carbon::setTestNow('2026-11-05 10:00:00');
        $respuesta = $this->marcarEnviada($alternativa, ['canal' => 'otro']);

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame(2, CrmActividad::count());
        $this->assertTrue($primerEnvio->equalTo($alternativa->fresh()->fecha_envio));
    }

    // ── CA3 ──────────────────────────────────────────────────────────

    public function test_aceptada_descartada_o_reemplazada_no_se_envian(): void
    {
        $this->usuario();

        foreach (['aceptada', 'descartada', Alternativa::ESTADO_REEMPLAZADA] as $estado) {
            $alternativa = $this->alternativa($estado);
            $respuesta = $this->marcarEnviada($alternativa);

            $this->assertSame(422, $respuesta->getStatusCode(), $estado);
            $this->assertSame($estado, $alternativa->fresh()->estado);
        }
        $this->assertSame(0, CrmActividad::count());
    }

    public function test_valida_el_canal_y_el_largo_de_la_nota(): void
    {
        $this->usuario();
        $alternativa = $this->alternativa();

        $this->assertSame(422, $this->marcarEnviada($alternativa, ['canal' => 'correo'])->getStatusCode());
        $this->assertSame(422, $this->marcarEnviada($alternativa, ['canal' => 'otro', 'nota' => str_repeat('a', 1001)])->getStatusCode());
        $this->assertSame('borrador', $alternativa->fresh()->estado);
    }

    public function test_vendedor_sin_ver_todas_no_marca_una_cotizacion_ajena(): void
    {
        $this->usuario(['cotizaciones.editar']);
        $ajena = $this->alternativa('borrador', User::factory()->create()->id);

        $this->expectException(AuthorizationException::class);
        $this->marcarEnviada($ajena);
    }

    // ── CA4 ──────────────────────────────────────────────────────────

    private function enviarCorreo(Alternativa $alternativa)
    {
        return app(CotizacionEnvioController::class)->enviar(new Request([
            'para' => ['ana@cliente.test'], 'cc' => [], 'asunto' => 'Cotización', 'mensaje' => 'Hola',
            'adjuntar_cotizacion' => true, 'adjuntar_condiciones' => false, 'marcar_enviada' => true,
        ]), (string) $alternativa->id);
    }

    public function test_correo_exitoso_deja_actividad_envio_correo(): void
    {
        $this->usuario();
        $alternativa = $this->alternativa();
        Mail::fake();

        $this->assertSame(200, $this->enviarCorreo($alternativa)->getStatusCode());

        $actividad = CrmActividad::sole();
        $this->assertSame(['envio', 'correo'], [$actividad->tipo, $actividad->canal]);
        $this->assertSame('Para: ana@cliente.test', $actividad->nota);
        $this->assertSame($alternativa->id, $actividad->alternativa_id);
    }

    public function test_correo_fallido_no_deja_actividad_pero_aparece_en_el_historial(): void
    {
        $this->usuario();
        $alternativa = $this->alternativa();
        $this->app->bind(CorreoAgenciaService::class, fn () => new class extends CorreoAgenciaService {
            public function dominio(): ?TenantDominioCorreo
            {
                return null;
            }

            public function enviar(CotizacionClienteMail $mail, array $para, array $cc = [], array $bcc = []): ?string
            {
                throw new RuntimeException('smtp caído');
            }
        });

        $this->assertSame(502, $this->enviarCorreo($alternativa)->getStatusCode());
        $this->assertSame(0, CrmActividad::count());

        $historial = app(CotizacionActividadController::class)->index((string) $alternativa->cotizacion_id)->getData(true)['actividades'];
        $this->assertCount(1, $historial);
        $this->assertTrue($historial[0]['fallido']);
        $this->assertSame('smtp caído', $historial[0]['error']);
        $this->assertSame('Opción 1', $historial[0]['alternativa']);
    }

    // ── CA5 ──────────────────────────────────────────────────────────

    private function anotar(int $clienteId, array $datos)
    {
        return app(CrmActividadController::class)->store(new Request($datos), (string) $clienteId);
    }

    public function test_anotar_una_llamada_desde_la_cotizacion_guarda_cliente_y_referencia(): void
    {
        $usuario = $this->usuario();
        $alternativa = $this->alternativa();
        $clienteId = $alternativa->cotizacion->cliente_id;

        $respuesta = $this->anotar($clienteId, [
            'tipo' => 'llamada', 'nota' => 'Pidió precio con hotel 4*', 'referencia_tipo' => 'cotizacion', 'referencia_id' => $alternativa->cotizacion_id,
        ]);

        $this->assertSame(200, $respuesta->getStatusCode(), json_encode($respuesta->getData(true)));
        $actividad = CrmActividad::sole();
        $this->assertSame('llamada', $actividad->tipo);
        $this->assertNull($actividad->canal);
        $this->assertSame($clienteId, $actividad->cliente_id);
        $this->assertSame(['cotizacion', $alternativa->cotizacion_id], [$actividad->referencia_tipo, $actividad->referencia_id]);
        $this->assertSame($usuario->id, $actividad->usuario_id);

        $historial = app(CotizacionActividadController::class)->index((string) $alternativa->cotizacion_id)->getData(true)['actividades'];
        $this->assertSame('llamada', $historial[0]['tipo']);
        $this->assertSame('Pidió precio con hotel 4*', $historial[0]['nota']);
    }

    public function test_anotar_con_una_cotizacion_de_otro_cliente_da_422(): void
    {
        $this->usuario();
        $alternativa = $this->alternativa();

        $respuesta = $this->anotar($this->cliente(), ['tipo' => 'visita', 'referencia_tipo' => 'cotizacion', 'referencia_id' => $alternativa->cotizacion_id]);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertSame(0, CrmActividad::count());
    }

    public function test_anotar_desde_la_ficha_pide_editar_clientes(): void
    {
        $this->usuario(['cotizaciones.editar']);
        $clienteId = $this->cliente();

        try {
            $this->anotar($clienteId, ['tipo' => 'llamada']);
            $this->fail('Debía rechazar sin edit_client');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->usuario(['edit_client']);
        $this->assertSame(200, $this->anotar($clienteId, ['tipo' => 'llamada'])->getStatusCode());
        $this->assertNull(CrmActividad::sole()->referencia_tipo);
    }

    public function test_anotar_valida_tipo_y_exige_texto_en_una_nota(): void
    {
        $this->usuario(['edit_client']);
        $clienteId = $this->cliente();

        $this->assertSame(422, $this->anotar($clienteId, ['tipo' => 'envio'])->getStatusCode());
        $this->assertSame(422, $this->anotar($clienteId, ['tipo' => 'nota'])->getStatusCode());
        $this->assertSame(422, $this->anotar($clienteId, ['tipo' => 'llamada', 'nota' => str_repeat('a', 1001)])->getStatusCode());
        $this->assertSame(0, CrmActividad::count());
    }
}
