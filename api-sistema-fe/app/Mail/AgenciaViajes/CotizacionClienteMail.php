<?php

declare(strict_types=1);

namespace App\Mail\AgenciaViajes;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Cotización enviada al cliente (plan-modulo-envio-correo-cotizacion.md §5.4).
 *
 * El mensaje es TEXTO PLANO escrito por el usuario: la vista lo escapa y solo
 * convierte los saltos de línea. Nunca se acepta HTML libre (mismo criterio que
 * la auditoría de seguridad sobre v-html).
 */
class CotizacionClienteMail extends Mailable
{
    /**
     * @param  array<int, array{bytes: string, nombre: string}>  $adjuntos
     */
    public function __construct(
        public readonly string $remitenteEmail,
        public readonly string $remitenteNombre,
        public readonly ?string $replyToEmail,
        public readonly string $asunto,
        public readonly string $mensaje,
        public readonly array $adjuntos = [],
        public readonly bool $prueba = false,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->remitenteEmail, $this->remitenteNombre),
            replyTo: $this->replyToEmail ? [new Address($this->replyToEmail, $this->remitenteNombre)] : [],
            subject: ($this->prueba ? '[Prueba] ' : '') . $this->asunto,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.agencia-viajes.cotizacion-cliente',
            text: 'emails.agencia-viajes.cotizacion-cliente-texto',
            with: [
                'mensaje' => $this->mensaje,
                'agencia' => $this->remitenteNombre,
                'adjuntos' => array_map(static fn (array $a): string => $a['nombre'], $this->adjuntos),
                'prueba' => $this->prueba,
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return array_map(
            static fn (array $a): Attachment => Attachment::fromData(static fn (): string => $a['bytes'], $a['nombre'])
                ->withMime('application/pdf'),
            $this->adjuntos,
        );
    }
}
