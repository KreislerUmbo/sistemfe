<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

// Fase B.2.4 (plan-panel-superadmin.md) — checkpoint 2: se envía una sola vez por invoice,
// al cruzar la mitad del período de gracia. Idempotencia vía
// tenant_invoices.aviso_gracia_enviado_at (el invoice se queda 'vencido' varios días
// seguidos sin cambiar de estado, así que a diferencia del recordatorio inicial y de la
// suspensión, no hay una transición de estado natural que lo cubra).
class InvoiceGraceMidpointWarningMail extends Mailable
{
    public function __construct(public array $datos)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Aviso: tu cuenta será suspendida pronto — {$this->datos['folio_interno']}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice-grace-midpoint',
            with: $this->datos,
        );
    }
}
