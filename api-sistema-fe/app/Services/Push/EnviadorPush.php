<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Models\PushSuscripcion;

/**
 * Envía un aviso push a los dispositivos de un usuario. Interfaz para poder reemplazarlo en los
 * tests: la implementación real (WebPushEnviador) habla con el servicio de push del navegador.
 */
interface EnviadorPush
{
    /** ¿Están las claves VAPID? Sin ellas no se puede enviar push. */
    public function configurado(): bool;

    /**
     * @param iterable<PushSuscripcion> $suscripciones
     * @param array{titulo: string, cuerpo: string, url: string, etiqueta?: string} $mensaje
     */
    public function enviar(iterable $suscripciones, array $mensaje): ResultadoPush;
}
