<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

// Fase B.2.5 (plan-panel-superadmin.md) — suspensión manual (acción de un central_user
// desde el panel, no la automática por vencimiento de gracia de B.2.4/
// TenantSuspendedForNonPaymentMail). Motivo libre a cargo de quien suspende.
class TenantSuspendedManuallyMail extends Mailable
{
    public function __construct(public array $datos)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu cuenta ha sido suspendida',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.tenant-suspended-manually',
            with: $this->datos,
        );
    }
}
