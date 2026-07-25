<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

// Fase B.2.4 (plan-panel-superadmin.md) — checkpoint 3: se envía una sola vez, en el mismo
// momento en que tenants:check-overdue-payments transiciona tenants.status de 'activo' a
// 'suspendido'. Esa transición de estado es la propia garantía de idempotencia, mismo
// criterio que el recordatorio inicial (checkpoint 1).
class TenantSuspendedForNonPaymentMail extends Mailable
{
    public function __construct(public array $datos)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu cuenta ha sido suspendida por falta de pago',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.tenant-suspended',
            with: $this->datos,
        );
    }
}
