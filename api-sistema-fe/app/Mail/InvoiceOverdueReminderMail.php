<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

// Fase B.2.4 (plan-panel-superadmin.md) — checkpoint 1: se envía una sola vez, en el
// mismo momento en que tenants:check-overdue-payments transiciona el invoice de
// 'pendiente' a 'vencido'. Esa transición de estado es la propia garantía de idempotencia
// (no hay columna de tracking dedicada, a diferencia del checkpoint de aviso de gracia).
class InvoiceOverdueReminderMail extends Mailable
{
    public function __construct(public array $datos)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Recordatorio de pago vencido — {$this->datos['folio_interno']}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice-overdue-reminder',
            with: $this->datos,
        );
    }
}
