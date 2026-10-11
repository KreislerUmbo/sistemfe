<?php

declare(strict_types=1);

namespace App\Mail\Creditos;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Confirma el correo para avisos (Fase 5): sin verificar no se envía la cartera a ese correo. */
class VerificarCorreoAvisosMail extends Mailable
{
    public function __construct(
        public readonly string $empresa,
        public readonly string $usuario,
        public readonly string $url,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->empresa),
            subject: 'Confirma tu correo para los avisos de cobranza',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.creditos.verificar-correo-avisos', with: [
            'empresa' => $this->empresa,
            'usuario' => $this->usuario,
            'url' => $this->url,
        ]);
    }
}
