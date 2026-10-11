<?php

declare(strict_types=1);

namespace Tests\Feature\Creditos;

use App\Http\Controllers\Creditos\AvisoCobranzaController;
use App\Mail\Creditos\AvisoCobranzaDiaMail;
use App\Mail\Creditos\VerificarCorreoAvisosMail;
use App\Models\Creditos\CreditoAviso;
use App\Models\Creditos\CreditoAvisoPreferencia;
use App\Models\Creditos\CreditoConfiguracion;
use App\Models\User;
use App\Services\Creditos\Avisos\AvisoCobranzaService;
use App\Services\Creditos\Reloj;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Fase 5 — aviso diario de cobranza (05-avisos-cobranza.md). Dataset de CreditosTestCase: 5,000
 * en 10 cuotas de 600 cada 30 días desde el 01/01/2026 (cuota 1 vence el sábado 31/01).
 */
final class AvisoCobranzaTest extends CreditosTestCase
{
    private const URL = 'https://demo.umbosystem.com/creditos/agenda?rango=hoy&atrasados=1';
    private const PERMISOS_ADMIN = [...self::TODOS_LOS_PERMISOS, AvisoCobranzaService::PERMISO_ADJUNTO];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = $this->usuario(self::PERMISOS_ADMIN);
        $this->abrirCaja($this->admin);
        $this->activo($this->admin);
        $this->activo($this->admin);
        DB::table('credito_configuracion')->update(['avisos_activo' => true, 'avisos_hora' => '06:45', 'avisos_dias' => null]);
    }

    private function servicio(): AvisoCobranzaService
    {
        return app(AvisoCobranzaService::class);
    }

    private function correoVerificado(User $usuario, string $correo): void
    {
        CreditoAvisoPreferencia::create(['user_id' => $usuario->id, 'correo' => $correo, 'correo_verificado_en' => now()]);
    }

    private function avisoDe(User $usuario): ?CreditoAviso
    {
        return CreditoAviso::where('user_id', $usuario->id)->where('canal', CreditoAviso::CANAL_CORREO)->first();
    }

    public function test_solo_corre_encendido_en_dia_de_aviso_y_desde_la_hora(): void
    {
        $config = CreditoConfiguracion::actual();
        $lima = static fn (string $momento): CarbonImmutable => CarbonImmutable::parse($momento, Reloj::ZONA);

        $this->assertNull($this->servicio()->motivoParaNoCorrer($config, $lima('2026-01-31 06:45')));
        $this->assertStringContainsString('hora', (string) $this->servicio()->motivoParaNoCorrer($config, $lima('2026-01-31 06:30')));
        // Domingo: fuera de los días por defecto (lunes a sábado).
        $this->assertSame('hoy no es día de aviso', $this->servicio()->motivoParaNoCorrer($config, $lima('2026-02-01 08:00')));

        $config->avisos_dias = [7];
        $this->assertNull($this->servicio()->motivoParaNoCorrer($config, $lima('2026-02-01 08:00')));

        $config->avisos_activo = false;
        $this->assertSame('avisos apagados', $this->servicio()->motivoParaNoCorrer($config, $lima('2026-01-31 08:00')));
    }

    public function test_el_administrador_recibe_su_agenda_con_pdf_adjunto_una_sola_vez(): void
    {
        $this->correoVerificado($this->admin, 'dueno@negocio.pe');
        $this->hoy('2026-01-31', '06:50');

        $r = $this->servicio()->ejecutar(self::URL);
        $this->assertTrue($r['corrio']);

        Mail::assertSent(AvisoCobranzaDiaMail::class, function (AvisoCobranzaDiaMail $m): bool {
            return $m->hasTo('dueno@negocio.pe')
                && $m->resumen === ['cuotas' => 2, 'clientes' => 2, 'monto' => '1200.00', 'atrasados' => 0]
                && $m->pdf !== null && str_starts_with($m->pdf, '%PDF')
                && $m->porCobrador !== []
                && $m->urlAgenda === self::URL;
        });
        // Mail::fake no dibuja la plantilla: se renderiza aparte para atrapar errores del HTML.
        $html = Mail::sent(AvisoCobranzaDiaMail::class)->first()->render();
        // Total y desglose por cobrador, ambos con formato (el desglose salía sin coma de miles).
        $this->assertSame(2, substr_count($html, 'S/ 1,200.00'));
        $this->assertStringContainsString('Sábado 31 de enero', $html);
        $this->assertStringContainsString(self::URL, str_replace('&amp;', '&', $html));

        $aviso = $this->avisoDe($this->admin);
        $this->assertSame(CreditoAviso::ESTADO_ENVIADO, $aviso->estado);
        $this->assertSame(2, $aviso->cantidad_cobros);
        $this->assertSame('1200.00', $aviso->monto_esperado);

        // La corrida de 15 minutos después no lo repite.
        $this->hoy('2026-01-31', '07:05');
        $this->servicio()->ejecutar(self::URL);
        Mail::assertSent(AvisoCobranzaDiaMail::class, 1);
    }

    public function test_suma_lo_atrasado_con_su_mora(): void
    {
        $this->correoVerificado($this->admin, 'dueno@negocio.pe');
        $this->hoy('2026-02-05', '07:00');   // cuota 1 de ambos vencida hace 5 días: 100 de mora cada uno

        $this->servicio()->ejecutar(self::URL);

        Mail::assertSent(AvisoCobranzaDiaMail::class, fn (AvisoCobranzaDiaMail $m): bool => $m->resumen['atrasados'] === 2
            && $m->resumen['monto'] === '1400.00');
    }

    public function test_sin_permiso_de_adjunto_recibe_solo_el_enlace(): void
    {
        $cajero = $this->usuario(self::TODOS_LOS_PERMISOS);   // ve toda la cartera, sin el permiso de adjunto
        $this->correoVerificado($cajero, 'cajero@negocio.pe');
        $this->hoy('2026-01-31', '07:00');

        $this->servicio()->ejecutar(self::URL);

        Mail::assertSent(AvisoCobranzaDiaMail::class, fn (AvisoCobranzaDiaMail $m): bool => $m->hasTo('cajero@negocio.pe')
            && $m->pdf === null && $m->resumen['cuotas'] === 2);
    }

    public function test_omite_sin_correo_verificado_y_al_cobrador_sin_cobros(): void
    {
        CreditoAvisoPreferencia::create(['user_id' => $this->admin->id, 'correo' => 'dueno@negocio.pe']);   // sin verificar
        $cobrador = $this->usuario(['creditos.ver', 'creditos.cobrar']);   // sin clientes asignados
        $this->correoVerificado($cobrador, 'cobrador@negocio.pe');
        $this->hoy('2026-01-31', '07:00');

        $this->servicio()->ejecutar(self::URL);

        Mail::assertNothingSent();
        $this->assertSame(CreditoAviso::ESTADO_OMITIDO, $this->avisoDe($this->admin)->estado);
        $this->assertStringContainsString('correo', $this->avisoDe($this->admin)->detalle);
        $this->assertSame('sin cobros hoy', $this->avisoDe($cobrador)->detalle);
    }

    public function test_un_dia_sin_cobros_el_administrador_igual_recibe_el_correo(): void
    {
        $this->correoVerificado($this->admin, 'dueno@negocio.pe');
        $this->hoy('2026-01-20', '07:00');   // martes sin vencimientos ni atrasos

        $this->servicio()->ejecutar(self::URL);

        Mail::assertSent(AvisoCobranzaDiaMail::class, fn (AvisoCobranzaDiaMail $m): bool => $m->resumen['cuotas'] === 0 && $m->pdf === null);

        DB::table('credito_configuracion')->update(['avisos_sin_cobros' => false]);
        $this->hoy('2026-01-21', '07:00');
        $this->servicio()->ejecutar(self::URL);
        Mail::assertSent(AvisoCobranzaDiaMail::class, 1);
    }

    public function test_no_avisa_al_super_admin_ni_a_inactivos(): void
    {
        $inactivo = $this->usuario(self::PERMISOS_ADMIN);
        $inactivo->update(['state' => User::STATE_INACTIVO]);
        $soporte = User::factory()->create();
        $soporte->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Super-Admin', 'guard_name' => 'api']));
        $ids = $this->servicio()->destinatarios()->pluck('id');

        $this->assertContains($this->admin->id, $ids);
        $this->assertNotContains($inactivo->id, $ids);
        $this->assertNotContains($soporte->id, $ids);
    }

    public function test_un_envio_fallido_se_reintenta_hasta_el_maximo(): void
    {
        $this->correoVerificado($this->admin, 'dueno@negocio.pe');
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP caído'));

        foreach (['06:50', '07:05', '07:20', '07:35'] as $hora) {
            $this->hoy('2026-01-31', $hora);
            $this->servicio()->ejecutar(self::URL);
        }

        $aviso = $this->avisoDe($this->admin);
        $this->assertSame(CreditoAviso::ESTADO_FALLIDO, $aviso->estado);
        $this->assertSame(CreditoAviso::MAXIMO_INTENTOS, $aviso->intentos);
        $this->assertSame('SMTP caído', $aviso->detalle);
    }

    public function test_el_correo_para_avisos_se_confirma_por_enlace_y_un_cambio_lo_invalida(): void
    {
        Auth::guard('api')->setUser($this->admin);
        $controller = app(AvisoCobranzaController::class);

        $controller->update(new Request(['correo' => 'Dueno@Negocio.pe']));
        $url = null;
        Mail::assertSent(VerificarCorreoAvisosMail::class, function (VerificarCorreoAvisosMail $m) use (&$url): bool {
            $url = $m->url;

            return $m->hasTo('dueno@negocio.pe');
        });
        $this->assertNull(CreditoAvisoPreferencia::de($this->admin)->correoUtilizable());
        $this->assertStringContainsString('Confirmar mi correo', Mail::sent(VerificarCorreoAvisosMail::class)->first()->render());

        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);
        $respuesta = $controller->verificar(new Request($query));
        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame('dueno@negocio.pe', CreditoAvisoPreferencia::de($this->admin)->correoUtilizable());

        // Otro correo: vuelve a quedar sin confirmar y el enlace viejo ya no sirve.
        $controller->update(new Request(['correo' => 'otro@negocio.pe']));
        $this->assertNull(CreditoAvisoPreferencia::de($this->admin)->correoUtilizable());
        $this->assertSame(410, $controller->verificar(new Request($query))->getStatusCode());
    }

    public function test_enviar_prueba_llega_aunque_no_haya_cobros_y_no_cuenta_como_el_del_dia(): void
    {
        $cobrador = $this->usuario(['creditos.ver', 'creditos.cobrar']);
        $this->correoVerificado($cobrador, 'cobrador@negocio.pe');
        $this->hoy('2026-01-31', '07:00');
        Auth::guard('api')->setUser($cobrador);

        app(AvisoCobranzaController::class)->prueba();

        Mail::assertSent(AvisoCobranzaDiaMail::class, fn (AvisoCobranzaDiaMail $m): bool => $m->prueba && $m->hasTo('cobrador@negocio.pe'));
        $this->assertNull($this->avisoDe($cobrador));
    }
}
