<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Genera el par de claves VAPID de los avisos push (Fase 5 de Créditos). Solo las imprime: se
 * copian al .env una vez por servidor. Cambiarlas invalida todas las suscripciones existentes
 * (cada usuario tendría que volver a activar los avisos en su celular).
 */
class WebPushClaves extends Command
{
    protected $signature = 'webpush:claves';

    protected $description = 'Genera las claves VAPID para los avisos push (se copian al .env).';

    public function handle(): int
    {
        if (config('services.webpush.public_key')) {
            $this->warn('Este servidor ya tiene VAPID_PUBLIC_KEY. Si la cambias, todos tendrán que volver a activar los avisos push.');
        }
        $claves = VAPID::createVapidKeys();
        $this->line('Copia estas líneas al .env y luego corre php artisan config:cache:');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY=' . $claves['publicKey']);
        $this->line('VAPID_PRIVATE_KEY=' . $claves['privateKey']);
        $this->line('VAPID_SUBJECT=mailto:avisos@umbosystem.com');

        return self::SUCCESS;
    }
}
