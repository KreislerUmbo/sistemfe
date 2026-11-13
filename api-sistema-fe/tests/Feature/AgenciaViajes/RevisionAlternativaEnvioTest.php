<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\CotizacionActividadController;
use App\Http\Controllers\AgenciaViajes\CotizacionEnvioController;
use App\Http\Controllers\AgenciaViajes\RevisionAlternativaController;
use App\Mail\AgenciaViajes\CotizacionClienteMail;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\Client\CrmActividad;
use App\Models\Central\TenantDominioCorreo;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AgenciaViajes\AlternativaPdfService;
use App\Services\AgenciaViajes\CondicionesGeneralesPdfService;
use App\Services\AgenciaViajes\CorreoAgenciaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;

// T-022 — la revisión manda en los dos caminos de envío (correo y marcar-enviada, que cubre
// WhatsApp y "otro medio") y se expone en GET alternativas/{id}/revision y en preparar().
// Mismo patrón de infraestructura que EnvioCorreoCotizacionTest.
class RevisionAlternativaEnvioTest extends TestCase
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
        $tenant->id = 'test-tenant-'.uniqid();
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

        ConfiguracionAgencia::query()->delete();
        ConfiguracionAgencia::create(['margen_minimo_aceptable_pct' => 20]);

        $user = User::factory()->create(['email' => 'vendedor-'.uniqid().'@agencia.test']);
        foreach (['cotizaciones.editar', 'cotizaciones.enviar_correo', 'cotizaciones.ver_todas'] as $permiso) {
            Permission::firstOrCreate(['guard_name' => 'api', 'name' => $permiso]);
            $user->givePermissionTo($permiso);
        }
        Auth::guard('api')->setUser($user->fresh());
    }

    protected function tearDown(): void
    {
        app(Tenancy::class)->tenant = null;
        DB::rollBack();
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    // Con $venta null no lleva ningún ítem (propuesta vacía).
    private function alternativa(?float $costo = 80, ?float $venta = 100, string $estado = 'borrador', array $extra = []): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10_000_000, 99_999_999),
            'full_name' => 'Ana Pérez', 'email' => 'ana@cliente.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'CDKM-'.uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-11-15', 'fecha_viaje_hasta' => '2026-11-18',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $alternativa = Alternativa::create($extra + [
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Opción 1', 'estado' => $estado,
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);

        if ($venta !== null) {
            AlternativaItem::create([
                'alternativa_id' => $alternativa->id, 'origen_tipo' => AlternativaItem::ORIGEN_MANUAL,
                'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
                'costo_snapshot' => $costo, 'precio_venta_snapshot' => $venta, 'precio_convertido' => $venta,
                'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
            ]);
        }

        return $alternativa;
    }

    private function enviarCorreo(Alternativa $alternativa)
    {
        return app(CotizacionEnvioController::class)->enviar(new Request([
            'para' => ['ana@cliente.test'], 'cc' => [], 'asunto' => 'Cotización', 'mensaje' => 'Hola',
            'adjuntar_cotizacion' => true, 'adjuntar_condiciones' => false, 'marcar_enviada' => true,
        ]), (string) $alternativa->id);
    }

    private function marcarEnviada(Alternativa $alternativa, string $canal)
    {
        return app(CotizacionActividadController::class)->marcarEnviada(new Request(['canal' => $canal]), (string) $alternativa->id);
    }

    // ── CA1 ──────────────────────────────────────────────────────────

    public function test_ca1_enviar_correo_sin_items_da_422_con_la_lista_y_no_envia(): void
    {
        $alternativa = $this->alternativa(null, null);
        Mail::fake();

        $respuesta = $this->enviarCorreo($alternativa);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertSame('sin_servicios', $respuesta->getData(true)['revision'][0]['codigo']);
        Mail::assertNothingSent();
        $this->assertSame('borrador', $alternativa->fresh()->estado);
    }

    // ── CA1b ─────────────────────────────────────────────────────────

    public function test_ca1b_marcar_enviada_sin_items_da_422_en_whatsapp_y_otro_sin_efectos(): void
    {
        $alternativa = $this->alternativa(null, null);

        foreach (['whatsapp', 'otro'] as $canal) {
            $respuesta = $this->marcarEnviada($alternativa, $canal);

            $this->assertSame(422, $respuesta->getStatusCode(), $canal);
            $this->assertSame('sin_servicios', $respuesta->getData(true)['revision'][0]['codigo']);
        }

        $this->assertSame(0, CrmActividad::count());
        $this->assertSame('borrador', $alternativa->fresh()->estado);
        $this->assertNull($alternativa->fresh()->fecha_envio);
    }

    public function test_ca1b_marcar_enviada_con_precio_cero_tambien_bloquea(): void
    {
        $alternativa = $this->alternativa(50, 0);

        $respuesta = $this->marcarEnviada($alternativa, 'whatsapp');

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertSame(0, CrmActividad::count());
    }

    public function test_ca1b_marcar_enviada_con_solo_avisos_responde_200_y_registra_como_hoy(): void
    {
        // Costo 100, venta 105: markup 5 % < 20 % → solo aviso.
        $alternativa = $this->alternativa(100, 105);

        $respuesta = $this->marcarEnviada($alternativa, 'whatsapp');

        $this->assertSame(200, $respuesta->getStatusCode(), json_encode($respuesta->getData(true)));
        $this->assertSame('enviada', $alternativa->fresh()->estado);
        $this->assertSame(1, CrmActividad::count());
    }

    public function test_ca1b_el_chequeo_de_estado_va_antes_que_la_revision(): void
    {
        $alternativa = $this->alternativa(null, null, 'descartada');

        $respuesta = $this->marcarEnviada($alternativa, 'otro');

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertStringContainsString('descartada', $respuesta->getData(true)['message']);
    }

    // ── CA10 ─────────────────────────────────────────────────────────

    public function test_ca10_con_solo_avisos_el_correo_se_envia(): void
    {
        $alternativa = $this->alternativa(100, 105);
        Mail::fake();

        $respuesta = $this->enviarCorreo($alternativa);

        $this->assertSame(200, $respuesta->getStatusCode(), json_encode($respuesta->getData(true)));
        Mail::assertSent(CotizacionClienteMail::class);
        $this->assertSame('enviada', $alternativa->fresh()->estado);
    }

    // ── Endpoint de revisión y formulario de envío ───────────────────

    public function test_get_revision_devuelve_la_lista(): void
    {
        $alternativa = $this->alternativa(100, 105);

        $respuesta = app(RevisionAlternativaController::class)->mostrar((string) $alternativa->id);

        $datos = $respuesta->getData(true);
        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame(['markup_bajo'], array_column($datos['revision'], 'codigo'));
        $this->assertFalse($datos['bloquea']);
    }

    public function test_get_revision_de_una_alternativa_vacia_marca_bloquea(): void
    {
        $alternativa = $this->alternativa(null, null);

        $datos = app(RevisionAlternativaController::class)->mostrar((string) $alternativa->id)->getData(true);

        $this->assertTrue($datos['bloquea']);
    }

    public function test_preparar_incluye_la_revision(): void
    {
        $alternativa = $this->alternativa(null, null);

        $datos = app(CotizacionEnvioController::class)->preparar((string) $alternativa->id)->getData(true);

        $this->assertSame('sin_servicios', $datos['revision'][0]['codigo']);
    }
}
