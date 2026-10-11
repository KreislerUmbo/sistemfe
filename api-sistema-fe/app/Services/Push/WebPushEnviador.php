<?php

declare(strict_types=1);

namespace App\Services\Push;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Web Push estándar con claves VAPID (minishlink/web-push). Google, Mozilla y Apple entregan las
 * notificaciones sin costo; el mensaje va cifrado de punta a punta con las claves del navegador.
 */
final class WebPushEnviador implements EnviadorPush
{
    /** Si el celular está apagado, el servicio guarda el aviso hasta 12 h: después ya no sirve. */
    private const TTL_SEGUNDOS = 12 * 3600;

    public function configurado(): bool
    {
        return (bool) config('services.webpush.public_key') && (bool) config('services.webpush.private_key');
    }

    public function enviar(iterable $suscripciones, array $mensaje): ResultadoPush
    {
        $webPush = new WebPush(['VAPID' => [
            'subject' => (string) config('services.webpush.subject'),
            'publicKey' => (string) config('services.webpush.public_key'),
            'privateKey' => (string) config('services.webpush.private_key'),
        ]], ['TTL' => self::TTL_SEGUNDOS, 'urgency' => 'high'], 10);

        $porEndpoint = [];
        $payload = json_encode([
            'titulo' => $mensaje['titulo'],
            'cuerpo' => $mensaje['cuerpo'],
            'url' => $mensaje['url'],
            'etiqueta' => $mensaje['etiqueta'] ?? 'aviso',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        foreach ($suscripciones as $s) {
            $porEndpoint[$s->endpoint] = $s->id;
            $webPush->queueNotification(Subscription::create([
                'endpoint' => $s->endpoint,
                'publicKey' => $s->clave_p256dh,
                'authToken' => $s->clave_auth,
                'contentEncoding' => $s->codificacion ?: 'aes128gcm',
            ]), $payload);
        }

        $entregadas = 0;
        $vencidas = [];
        $errores = [];
        foreach ($webPush->flush() as $reporte) {
            $endpoint = $reporte->getEndpoint();
            if ($reporte->isSuccess()) {
                $entregadas++;
            } elseif ($reporte->isSubscriptionExpired()) {
                $vencidas[] = $porEndpoint[$endpoint];
            } else {
                $errores[] = mb_substr($reporte->getReason(), 0, 200);
            }
        }

        return new ResultadoPush($entregadas, $vencidas, $errores);
    }
}
