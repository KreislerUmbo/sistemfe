<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaController;
use App\Http\Controllers\AgenciaViajes\ConfiguracionCorreoController;
use App\Http\Controllers\AgenciaViajes\CotizacionEnvioController;
use App\Mail\AgenciaViajes\CotizacionClienteMail;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\ConfiguracionCorreo;
use App\Models\AgenciaViajes\CotizacionEnvio;
use App\Models\Central\TenantDominioCorreo;
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
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;

// Envío de la cotización por correo (plan-modulo-envio-correo-cotizacion.md,
// etapa E1). Postgres real (sistemafe_test_migrations), transacción por test
// revertida, tenant en memoria (mismo patrón que ReservaFacturacionExternaTest).
// Los PDFs se reemplazan por dobles: acá se prueba el flujo de envío, no el
// diseño del PDF (ya cubierto por sus propios tests).
class EnvioCorreoCotizacionTest extends TestCase
{
    private ?TenantDominioCorreo $dominioFalso = null;

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

        // El dominio vive en la base central: en los tests se simula sin
        // tocarla, reemplazando solo CorreoAgenciaService::dominio().
        $test = $this;
        $this->app->bind(CorreoAgenciaService::class, fn () => new class($test) extends CorreoAgenciaService {
            public function __construct(private $test)
            {
            }

            public function dominio(): ?TenantDominioCorreo
            {
                return $this->test->dominioFalso();
            }
        });

        $this->mock(AlternativaPdfService::class, function ($mock) {
            $mock->shouldReceive('contenido')->andReturn(['bytes' => '%PDF-cotizacion', 'nombre' => 'Cotizacion-TEST.pdf']);
        });
        $this->mock(CondicionesGeneralesPdfService::class, function ($mock) {
            $mock->shouldReceive('contenido')->andReturn(['bytes' => '%PDF-condiciones', 'nombre' => 'Condiciones-Generales-del-Servicio.pdf']);
        });
    }

    protected function tearDown(): void
    {
        app(Tenancy::class)->tenant = null;
        Carbon::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function dominioFalso(): ?TenantDominioCorreo
    {
        return $this->dominioFalso;
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private function tenant(bool $habilitado = true, int $limite = 50): void
    {
        $tenant = new Tenant();
        $tenant->id = 'test-tenant-' . uniqid();
        $tenant->correo_cotizaciones_habilitado = $habilitado;
        $tenant->correo_limite_diario = $limite;

        app(Tenancy::class)->tenant = $tenant;
    }

    private function usuario(array $permisos = ['cotizaciones.enviar_correo', 'cotizaciones.ver_todas']): User
    {
        $user = User::factory()->create(['email' => 'vendedor-' . uniqid() . '@agencia.test']);
        foreach ($permisos as $permiso) {
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => $permiso]);
            $user->givePermissionTo($permiso);
        }
        Auth::guard('api')->setUser($user->fresh());

        return $user;
    }

    private function alternativa(?int $vendedorId = null, string $estado = 'borrador'): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10_000_000, 99_999_999),
            'full_name' => 'Ana Pérez', 'email' => 'ana@cliente.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'CDKM-' . uniqid(), 'cliente_id' => $clienteId,
            'vendedor_id' => $vendedorId, 'destino' => 'Tarapoto',
            'fecha_viaje_desde' => '2026-11-15', 'fecha_viaje_hasta' => '2026-11-18',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Opción 1', 'estado' => $estado,
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
    }

    private function payload(array $cambios = []): Request
    {
        return new Request($cambios + [
            'para' => ['ana@cliente.test'],
            'cc' => [],
            'asunto' => 'Cotización de prueba',
            'mensaje' => "Hola Ana:\nTe enviamos tu cotización.",
            'adjuntar_cotizacion' => true,
            'adjuntar_condiciones' => true,
            'marcar_enviada' => true,
        ]);
    }

    private function controller(): CotizacionEnvioController
    {
        return app(CotizacionEnvioController::class);
    }

    // ── Remitente ────────────────────────────────────────────────────

    public function test_sin_dominio_verificado_usa_el_respaldo_de_la_plataforma_con_reply_to_de_la_agencia(): void
    {
        $this->tenant();
        ConfiguracionCorreo::create(['remitente_nombre' => 'Turismo XYZ', 'remitente_local' => 'reservas', 'reply_to' => 'reservas@xyz.test']);

        $remitente = app(CorreoAgenciaService::class)->remitente();

        $this->assertSame('cotizaciones@umbosystem.com', $remitente['email']);
        $this->assertSame('Turismo XYZ', $remitente['nombre']);
        $this->assertSame(CotizacionEnvio::MODO_PLATAFORMA, $remitente['modo']);
        $this->assertSame('reservas@xyz.test', $remitente['reply_to']);
    }

    public function test_con_dominio_verificado_sale_desde_el_dominio_de_la_agencia(): void
    {
        $this->tenant();
        ConfiguracionCorreo::create(['remitente_nombre' => 'Dakamu', 'remitente_local' => 'Reservas', 'reply_to' => null]);
        $this->dominioFalso = new TenantDominioCorreo(['dominio' => 'DKMXPLORE.com', 'brevo_estado' => TenantDominioCorreo::ESTADO_VERIFICADO]);

        $remitente = app(CorreoAgenciaService::class)->remitente();

        $this->assertSame('reservas@dkmxplore.com', $remitente['email']);
        $this->assertSame(CotizacionEnvio::MODO_DOMINIO_AGENCIA, $remitente['modo']);
    }

    public function test_dominio_pendiente_de_dns_sigue_usando_el_respaldo(): void
    {
        $this->tenant();
        ConfiguracionCorreo::create(['remitente_local' => 'reservas']);
        $this->dominioFalso = new TenantDominioCorreo(['dominio' => 'dkmxplore.com', 'brevo_estado' => TenantDominioCorreo::ESTADO_PENDIENTE]);

        $this->assertSame(CotizacionEnvio::MODO_PLATAFORMA, app(CorreoAgenciaService::class)->remitente()['modo']);
    }

    // ── Límite diario (hora de Perú) ─────────────────────────────────

    public function test_limite_diario_cuenta_solo_los_enviados_de_hoy_en_hora_de_peru(): void
    {
        $this->tenant();
        // 14-oct 01:00 UTC = 13-oct 20:00 en Perú (ayer); 14-oct 10:00 UTC = hoy.
        Carbon::setTestNow(Carbon::parse('2026-10-14 15:00:00', 'UTC'));
        $base = ['para' => ['x@y.test'], 'remitente' => 'A <a@b.test>', 'modo_remitente' => 'plataforma', 'asunto' => 'x', 'cuerpo' => 'x'];

        CotizacionEnvio::create($base + ['estado' => 'enviado'])->forceFill(['created_at' => '2026-10-14 01:00:00'])->save();
        CotizacionEnvio::create($base + ['estado' => 'enviado'])->forceFill(['created_at' => '2026-10-14 10:00:00'])->save();
        CotizacionEnvio::create($base + ['estado' => 'fallido'])->forceFill(['created_at' => '2026-10-14 11:00:00'])->save();

        $this->assertSame(1, app(CorreoAgenciaService::class)->enviadosHoy());
    }

    public function test_bloquea_al_superar_el_limite_diario(): void
    {
        $this->tenant(true, 1);
        $this->usuario();
        $alternativa = $this->alternativa();
        CotizacionEnvio::create(['para' => ['x@y.test'], 'remitente' => 'A <a@b.test>', 'modo_remitente' => 'plataforma', 'asunto' => 'x', 'cuerpo' => 'x', 'estado' => 'enviado']);
        Mail::fake();

        $respuesta = $this->controller()->enviar($this->payload(), (string) $alternativa->id);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertStringContainsString('límite', $respuesta->getData(true)['message']);
        Mail::assertNothingSent();
    }

    // ── Envío ────────────────────────────────────────────────────────

    public function test_tenant_sin_la_funcion_habilitada_no_envia_ni_deja_fila(): void
    {
        $this->tenant(false);
        $this->usuario();
        $alternativa = $this->alternativa();
        Mail::fake();

        $respuesta = $this->controller()->enviar($this->payload(), (string) $alternativa->id);

        $this->assertSame(403, $respuesta->getStatusCode());
        Mail::assertNothingSent();
        $this->assertSame(0, CotizacionEnvio::count());
    }

    public function test_envia_con_adjuntos_deja_historial_y_marca_enviada_con_vencimiento(): void
    {
        $this->tenant();
        $usuario = $this->usuario();
        $alternativa = $this->alternativa();
        ConfiguracionCorreo::create(['remitente_nombre' => 'Turismo XYZ', 'reply_to' => 'reservas@xyz.test', 'copia_oculta_activa' => true, 'copia_oculta_email' => 'copia@xyz.test']);
        ConfiguracionAgencia::query()->delete();
        ConfiguracionAgencia::create(['dias_vigencia_cotizacion' => 7]);
        Mail::fake();

        $respuesta = $this->controller()->enviar($this->payload(['cc' => ['papa@cliente.test']]), (string) $alternativa->id);

        $this->assertSame(200, $respuesta->getStatusCode(), json_encode($respuesta->getData(true)));
        Mail::assertSent(CotizacionClienteMail::class, function (CotizacionClienteMail $mail) {
            return $mail->hasTo('ana@cliente.test')
                && $mail->hasCc('papa@cliente.test')
                && $mail->hasBcc('copia@xyz.test')
                && $mail->hasFrom('cotizaciones@umbosystem.com', 'Turismo XYZ')
                && $mail->hasReplyTo('reservas@xyz.test')
                && count($mail->attachments()) === 2;
        });

        $envio = CotizacionEnvio::sole();
        $this->assertSame('enviado', $envio->estado);
        $this->assertSame($usuario->id, $envio->usuario_id);
        $this->assertSame(['Cotizacion-TEST.pdf', 'Condiciones-Generales-del-Servicio.pdf'], array_column($envio->adjuntos, 'nombre'));

        $alternativa->refresh();
        $this->assertSame('enviada', $alternativa->estado);
        $this->assertNotNull($alternativa->fecha_envio);
        $this->assertSame(7, (int) round($alternativa->fecha_envio->diffInDays($alternativa->fecha_vencimiento, true)));
    }

    public function test_sin_marcar_enviada_la_alternativa_sigue_en_borrador(): void
    {
        $this->tenant();
        $this->usuario();
        $alternativa = $this->alternativa();
        Mail::fake();

        $this->controller()->enviar($this->payload(['marcar_enviada' => false]), (string) $alternativa->id);

        $this->assertSame('borrador', $alternativa->fresh()->estado);
    }

    public function test_alternativa_que_no_se_puede_imprimir_no_envia_y_deja_fila_fallida(): void
    {
        $this->tenant();
        $this->usuario();
        $alternativa = $this->alternativa();
        $this->mock(AlternativaPdfService::class, function ($mock) {
            $mock->shouldReceive('contenido')->andThrow(ValidationException::withMessages([
                'grupo_opcion' => 'No se puede generar el PDF: falta elegir una opción en este grupo de hotel.',
            ]));
        });
        Mail::fake();

        $respuesta = $this->controller()->enviar($this->payload(), (string) $alternativa->id);

        $this->assertSame(422, $respuesta->getStatusCode());
        Mail::assertNothingSent();
        $envio = CotizacionEnvio::sole();
        $this->assertSame('fallido', $envio->estado);
        $this->assertStringContainsString('grupo de hotel', $envio->error);
        $this->assertSame('borrador', $alternativa->fresh()->estado);
    }

    public function test_falla_del_transporte_deja_fila_fallida_y_no_marca_enviada(): void
    {
        $this->tenant();
        $this->usuario();
        $alternativa = $this->alternativa();
        $this->app->bind(CorreoAgenciaService::class, fn () => new class extends CorreoAgenciaService {
            public function dominio(): ?TenantDominioCorreo
            {
                return null;
            }

            public function enviar(CotizacionClienteMail $mail, array $para, array $cc = [], array $bcc = []): ?string
            {
                throw new RuntimeException('Connection could not be established with host smtp-relay.brevo.com');
            }
        });

        $respuesta = $this->controller()->enviar($this->payload(), (string) $alternativa->id);

        $this->assertSame(502, $respuesta->getStatusCode());
        $envio = CotizacionEnvio::sole();
        $this->assertSame('fallido', $envio->estado);
        $this->assertStringContainsString('smtp-relay', $envio->error);
        $this->assertSame('borrador', $alternativa->fresh()->estado);
    }

    public function test_valida_correos_y_maximo_de_destinatarios(): void
    {
        $this->tenant();
        $this->usuario();
        $alternativa = $this->alternativa();
        Mail::fake();

        $invalido = $this->controller()->enviar($this->payload(['para' => ['no-es-un-correo']]), (string) $alternativa->id);
        $demasiados = $this->controller()->enviar($this->payload(['para' => array_map(fn ($i) => "c{$i}@x.test", range(1, 6))]), (string) $alternativa->id);

        $this->assertSame(422, $invalido->getStatusCode());
        $this->assertSame(422, $demasiados->getStatusCode());
        Mail::assertNothingSent();
    }

    public function test_alternativa_descartada_no_se_envia(): void
    {
        $this->tenant();
        $this->usuario();
        $alternativa = $this->alternativa(null, 'descartada');
        Mail::fake();

        $this->assertSame(422, $this->controller()->enviar($this->payload(), (string) $alternativa->id)->getStatusCode());
        Mail::assertNothingSent();
    }

    public function test_vendedor_sin_ver_todas_no_puede_enviar_una_cotizacion_ajena(): void
    {
        $this->tenant();
        $otro = User::factory()->create();
        $this->usuario(['cotizaciones.enviar_correo', 'cotizaciones.ver']);
        $ajena = $this->alternativa($otro->id);
        Mail::fake();

        $this->expectException(AuthorizationException::class);
        $this->controller()->enviar($this->payload(), (string) $ajena->id);
    }

    public function test_vendedor_sin_ver_todas_si_puede_enviar_su_propia_cotizacion(): void
    {
        $this->tenant();
        $vendedor = $this->usuario(['cotizaciones.enviar_correo', 'cotizaciones.ver']);
        $propia = $this->alternativa($vendedor->id);
        Mail::fake();

        $this->assertSame(200, $this->controller()->enviar($this->payload(), (string) $propia->id)->getStatusCode());
    }

    // ── Plantilla y configuración ────────────────────────────────────

    public function test_preparar_propone_destinatario_y_mensaje_desde_la_plantilla(): void
    {
        $this->tenant();
        $this->usuario();
        $alternativa = $this->alternativa();
        ConfiguracionCorreo::create(['asunto_plantilla' => 'Tu viaje {codigo}', 'cuerpo_plantilla' => 'Hola {cliente}, salida {fecha_viaje}.', 'firma' => 'Equipo {agencia}']);

        $datos = $this->controller()->preparar((string) $alternativa->id)->getData(true);
        $codigo = $alternativa->cotizacion->codigo;

        $this->assertSame(['ana@cliente.test'], $datos['para']);
        $this->assertSame("Tu viaje {$codigo}", $datos['asunto']);
        $this->assertStringStartsWith('Hola Ana Pérez, salida 15/11/2026 al 18/11/2026.', $datos['mensaje']);
        $this->assertStringContainsString('Equipo ', $datos['mensaje']);
        $this->assertTrue($datos['puede_marcar_enviada']);
    }

    public function test_el_mensaje_se_escapa_como_texto_plano(): void
    {
        $mail = new CotizacionClienteMail('a@b.test', 'Agencia', null, 'Asunto', "<script>alert(1)</script>\nlinea 2");

        $html = $mail->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<br />', $html);
    }

    public function test_configuracion_solo_acepta_la_parte_antes_de_la_arroba(): void
    {
        $this->tenant();
        $this->usuario(['agencia.configuracion']);

        $respuesta = app(ConfiguracionCorreoController::class)->update(new Request([
            'remitente_local' => 'reservas@otrodominio.com',
            'copia_oculta_activa' => false,
            'adjuntar_condiciones_por_defecto' => true,
        ]));

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertSame(0, ConfiguracionCorreo::count());
    }

    public function test_marcar_enviada_a_mano_tambien_calcula_el_vencimiento(): void
    {
        $this->tenant();
        $this->usuario(['cotizaciones.editar', 'cotizaciones.ver_todas']);
        $alternativa = $this->alternativa();
        ConfiguracionAgencia::query()->delete();
        ConfiguracionAgencia::create(['dias_vigencia_cotizacion' => 10]);

        app(AlternativaController::class)->update(new Request(['estado' => 'enviada']), (string) $alternativa->id);

        $alternativa->refresh();
        $this->assertSame('enviada', $alternativa->estado);
        $this->assertNotNull($alternativa->fecha_vencimiento);
        $this->assertSame(10, (int) round($alternativa->fecha_envio->diffInDays($alternativa->fecha_vencimiento, true)));
    }
}
