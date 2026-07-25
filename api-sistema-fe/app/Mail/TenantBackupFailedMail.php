<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

// Fase C.2 (plan-panel-superadmin.md) — "notificación en caso de fallo", regla explícita
// del plan. Va a los central_users (equipo de operación de la plataforma), no al tenant —
// un backup automático fallido es un problema interno, el tenant no tiene nada que hacer
// al respecto.
class TenantBackupFailedMail extends Mailable
{
    public function __construct(public array $datos)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Backup automático falló — {$this->datos['tenant_id']}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.tenant-backup-failed',
            with: $this->datos,
        );
    }
}
