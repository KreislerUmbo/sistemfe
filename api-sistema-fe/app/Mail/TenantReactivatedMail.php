<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

// Fase B.2.5 (plan-panel-superadmin.md) — "confirmación al suspender/reactivar" (checkpoint
// ya anticipado en el diseño de B.2, cerrado recién acá). Usado tanto por la reactivación
// manual como por la reactivación automática al pagar (ver
// TenantSubscriptionManagementService::intentarReactivarPorPago()) — el tenant recibe el
// mismo mensaje sin importar quién disparó la reactivación.
class TenantReactivatedMail extends Mailable
{
    public function __construct(public array $datos)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu cuenta ha sido reactivada',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.tenant-reactivated',
            with: $this->datos,
        );
    }
}
