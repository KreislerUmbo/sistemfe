<?php

declare(strict_types=1);

namespace Tests\Feature\Creditos;

use App\Http\Controllers\Creditos\AvisoCobranzaController;
use App\Models\Creditos\CreditoAviso;
use App\Models\Creditos\CreditoAvisoPreferencia;
use App\Models\PushSuscripcion;
use App\Models\User;
use App\Services\Creditos\Avisos\AvisoCobranzaService;
use App\Services\Push\EnviadorPush;
use App\Services\Push\ResultadoPush;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Fase 5, entrega 2 — avisos push en el celular. El envío real (WebPushEnviador) se reemplaza por
 * uno falso que registra los mensajes; el resto (qué se manda, a quién, idempotencia, limpieza de
 * dispositivos vencidos) es el código real. Dataset de CreditosTestCase: 2 créditos con la cuota 1
 * (600 c/u) el sábado 31/01/2026.
 */
final class AvisoPushTest extends CreditosTestCase
{
    private const URL = 'https://demo.umbosystem.com/creditos/agenda?rango=hoy&atrasados=1';

    private User $admin;
    private EnviadorPushFalso $push;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->push = new EnviadorPushFalso();
        $this->app->instance(EnviadorPush::class, $this->push);
        $this->admin = $this->usuario();
        $this->abrirCaja($this->admin);
        $this->activo($this->admin);
        $this->activo($this->admin);
        DB::table('credito_configuracion')->update(['avisos_activo' => true, 'avisos_hora' => '06:45', 'avisos_dias' => null]);
    }

    private function dispositivo(User $usuario, string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc'): PushSuscripcion
    {
        return PushSuscripcion::create([
            'user_id' => $usuario->id, 'endpoint' => $endpoint, 'endpoint_hash' => PushSuscripcion::hash($endpoint),
            'clave_p256dh' => 'BClave', 'clave_auth' => 'secreto',
        ]);
    }

    private function avisoPush(User $usuario): ?CreditoAviso
    {
        return CreditoAviso::where('user_id', $usuario->id)->where('canal', CreditoAviso::CANAL_PUSH)->first();
    }

    public function test_manda_solo_totales_con_el_enlace_a_la_agenda_y_una_sola_vez(): void
    {
        $this->dispositivo($this->admin);
        $this->hoy('2026-01-31', '06:50');

        app(AvisoCobranzaService::class)->ejecutar(self::URL);

        $this->assertCount(1, $this->push->mensajes);
        $aviso = $this->avisoPush($this->admin);
        $this->assertSame([
            'titulo' => 'Hoy: 2 cobros · S/ 1,200.00',
            'cuerpo' => 'Toca para abrir la Agenda',
            'url' => self::URL . '&aviso=' . $aviso->id,
            'etiqueta' => 'cobranza-dia',
        ], $this->push->mensajes[0]);
        $this->assertSame(CreditoAviso::ESTADO_ENVIADO, $aviso->estado);
        $this->assertNotNull(PushSuscripcion::where('user_id', $this->admin->id)->value('ultimo_envio_en'));

        $this->hoy('2026-01-31', '07:05');
        app(AvisoCobranzaService::class)->ejecutar(self::URL);
        $this->assertCount(1, $this->push->mensajes);
    }

    public function test_al_cobrador_le_habla_de_sus_cobros_y_cuenta_los_atrasados(): void
    {
        $this->hoy('2026-02-05', '07:00');   // las 2 cuotas 1 ya vencidas
        $mensaje = app(AvisoCobranzaService::class)->mensajePush(
            $this->usuario(['creditos.ver', 'creditos.cobrar']),
            ['cuotas' => 2, 'clientes' => 2, 'monto' => '1400.00', 'atrasados' => 2],
        );

        $this->assertSame(['titulo' => 'Tienes 2 cobros hoy · S/ 1,400.00', 'cuerpo' => '2 atrasados · Toca para abrir la Agenda'], $mensaje);
    }

    public function test_sin_cobros_no_hay_push_aunque_el_admin_reciba_su_correo(): void
    {
        $this->dispositivo($this->admin);
        CreditoAvisoPreferencia::create(['user_id' => $this->admin->id, 'correo' => 'dueno@negocio.pe', 'correo_verificado_en' => now()]);
        $this->hoy('2026-01-20', '07:00');

        app(AvisoCobranzaService::class)->ejecutar(self::URL);

        $this->assertSame([], $this->push->mensajes);
        $this->assertSame('sin cobros hoy', $this->avisoPush($this->admin)->detalle);
        Mail::assertSent(\App\Mail\Creditos\AvisoCobranzaDiaMail::class, 1);
    }

    public function test_omite_sin_dispositivos_con_push_apagado_o_sin_claves_vapid(): void
    {
        $this->hoy('2026-01-31', '07:00');
        app(AvisoCobranzaService::class)->ejecutar(self::URL);
        $this->assertSame('sin dispositivos con avisos activados', $this->avisoPush($this->admin)->detalle);

        $otro = $this->usuario();
        $this->dispositivo($otro, 'https://fcm.googleapis.com/fcm/send/otro');
        CreditoAvisoPreferencia::create(['user_id' => $otro->id, 'recibir_push' => false]);
        $this->hoy('2026-01-31', '07:15');
        app(AvisoCobranzaService::class)->ejecutar(self::URL);
        $this->assertSame('push apagado', $this->avisoPush($otro)->detalle);

        $sinClaves = $this->usuario();
        $this->dispositivo($sinClaves, 'https://fcm.googleapis.com/fcm/send/sin-claves');
        $this->push->configurado = false;
        $this->hoy('2026-01-31', '07:30');
        app(AvisoCobranzaService::class)->ejecutar(self::URL);
        $this->assertSame('push no configurado en el servidor', $this->avisoPush($sinClaves)->detalle);
        $this->assertSame([], $this->push->mensajes);
    }

    public function test_un_dispositivo_vencido_se_borra_y_el_aviso_queda_fallido(): void
    {
        $vencido = $this->dispositivo($this->admin);
        $this->push->resultado = new ResultadoPush(0, [$vencido->id]);
        $this->hoy('2026-01-31', '07:00');

        app(AvisoCobranzaService::class)->ejecutar(self::URL);

        $this->assertFalse(PushSuscripcion::whereKey($vencido->id)->exists());
        $aviso = $this->avisoPush($this->admin);
        $this->assertSame(CreditoAviso::ESTADO_FALLIDO, $aviso->estado);
        $this->assertSame('ningún dispositivo seguía activo', $aviso->detalle);
    }

    public function test_activar_desactivar_y_marcar_abierto_desde_la_api(): void
    {
        Auth::guard('api')->setUser($this->admin);
        $controller = app(AvisoCobranzaController::class);
        $suscripcion = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/xyz', 'keys' => ['p256dh' => 'BClave', 'auth' => 'secreto']];
        CreditoAvisoPreferencia::create(['user_id' => $this->admin->id, 'recibir_push' => false]);

        $datos = $controller->suscribirPush(new Request($suscripcion))->getData(true);
        $this->assertCount(1, $datos['push_dispositivos']);
        $this->assertTrue($datos['recibir_push']);   // activar en un dispositivo enciende el canal
        $this->assertSame('pk-publica', $datos['push_clave_publica']);

        // El mismo navegador con otro usuario: el dispositivo pasa a ese usuario, no se duplica.
        $cobrador = $this->usuario(['creditos.ver', 'creditos.cobrar']);
        Auth::guard('api')->setUser($cobrador);
        app(AvisoCobranzaController::class)->suscribirPush(new Request($suscripcion));
        $this->assertSame($cobrador->id, PushSuscripcion::where('endpoint_hash', PushSuscripcion::hash($suscripcion['endpoint']))->sole()->user_id);

        app(AvisoCobranzaController::class)->desuscribirPush(new Request(['endpoint' => $suscripcion['endpoint']]));
        $this->assertSame(0, PushSuscripcion::count());

        // Abierto: solo el dueño del aviso, y solo la primera vez.
        $aviso = CreditoAviso::create(['user_id' => $cobrador->id, 'tipo' => 'cobranza_dia', 'fecha' => '2026-01-31', 'canal' => 'push', 'estado' => 'enviado']);
        Auth::guard('api')->setUser($this->admin);
        app(AvisoCobranzaController::class)->abierto($aviso->id);
        $this->assertNull($aviso->fresh()->abierto_en);
        Auth::guard('api')->setUser($cobrador);
        app(AvisoCobranzaController::class)->abierto($aviso->id);
        $this->assertNotNull($aviso->fresh()->abierto_en);
    }

    public function test_la_prueba_de_push_llega_aunque_no_haya_cobros(): void
    {
        $this->dispositivo($this->admin);
        $this->hoy('2026-01-20', '07:00');
        Auth::guard('api')->setUser($this->admin);

        app(AvisoCobranzaController::class)->pruebaPush();

        $this->assertSame('[Prueba] Hoy: 0 cobros · S/ 0.00', $this->push->mensajes[0]['titulo']);
        $this->assertStringEndsWith('/creditos/agenda?rango=hoy&atrasados=1', $this->push->mensajes[0]['url']);   // sin ?aviso: la prueba no se registra
        $this->assertNull($this->avisoPush($this->admin));
    }
}

/** Enviador push de prueba: guarda los mensajes y devuelve el resultado que se le indique. */
final class EnviadorPushFalso implements EnviadorPush
{
    public bool $configurado = true;
    public ?ResultadoPush $resultado = null;
    /** @var list<array<string, string>> */
    public array $mensajes = [];

    public function configurado(): bool
    {
        if ($this->configurado) {
            config(['services.webpush.public_key' => 'pk-publica']);
        }

        return $this->configurado;
    }

    public function enviar(iterable $suscripciones, array $mensaje): ResultadoPush
    {
        $this->mensajes[] = $mensaje;

        return $this->resultado ?? new ResultadoPush(count(is_array($suscripciones) ? $suscripciones : iterator_to_array($suscripciones)));
    }
}
