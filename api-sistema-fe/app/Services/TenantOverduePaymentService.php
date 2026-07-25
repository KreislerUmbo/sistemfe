<?php

namespace App\Services;

use App\Mail\InvoiceGraceMidpointWarningMail;
use App\Mail\InvoiceOverdueReminderMail;
use App\Mail\TenantSuspendedForNonPaymentMail;
use App\Models\Central\PlatformSetting;
use App\Models\Central\TenantInvoice;
use App\Models\Tenant;
use Carbon\Carbon;

// Fase B.2.4 (plan-panel-superadmin.md) — único punto de verdad de
// tenants:check-overdue-payments, mismo criterio que TenantInvoiceService en B.2.3
// (comando = wrapper delgado sobre este servicio). Pipeline por invoice vencido:
// vencimiento -> recordatorio -> mitad de gracia -> suspensión, tal como lo describe el
// plan. Los 3 checkpoints son idempotentes: recordatorio y suspensión se apoyan en su
// propia transición de estado (pendiente->vencido, activo->suspendido — una corrida
// repetida ya los encuentra en el estado nuevo y no repite nada); el aviso de mitad de
// gracia no tiene una transición de estado propia (el invoice sigue 'vencido' toda la
// ventana de gracia), así que se apoya en tenant_invoices.aviso_gracia_enviado_at.
class TenantOverduePaymentService
{
    public function __construct(
        private AuditLogger $auditLogger,
        private TenantContactMailer $mailer,
    ) {
    }

    public function procesar(): array
    {
        $hoy = Carbon::today();
        $diasGraciaDefault = $this->diasGraciaDefault();

        $resumen = ['recordatorios' => 0, 'avisos_gracia' => 0, 'suspensiones' => 0];

        $invoices = TenantInvoice::with(['subscription', 'tenant'])
            ->where('estado', '!=', 'pagado')
            ->where('fecha_vencimiento', '<=', $hoy)
            // Un tenant archivado ya está fuera de servicio por completo — no tiene
            // sentido seguir notificándolo ni "suspenderlo" (concepto distinto, ver
            // TenantSubscriptionMiddleware).
            ->whereHas('tenant', fn ($q) => $q->where('status', '!=', 'archivado'))
            ->get();

        foreach ($invoices as $invoice) {
            $tenant = $invoice->tenant;
            $subscription = $invoice->subscription;

            // absolute: true explícito — Carbon 3 cambió el default de diffInDays() a
            // valores con signo (según dirección cronológica), a diferencia de Carbon 2.
            // Sin esto, fecha_vencimiento en el pasado devuelve un número negativo y
            // ningún checkpoint de gracia/suspensión llega a dispararse nunca.
            $diasVencido = $hoy->diffInDays($invoice->fecha_vencimiento->copy()->startOfDay(), true);
            $diasGracia = $subscription->dias_gracia_suspension ?? $diasGraciaDefault;
            $contacto = $this->mailer->resolverContacto($tenant);

            if ($invoice->estado === 'pendiente') {
                $invoice->estado = 'vencido';
                $invoice->save();

                $this->mailer->enviar($contacto, new InvoiceOverdueReminderMail([
                    'razon_social' => $contacto['razon_social'],
                    'folio_interno' => $invoice->folio_interno,
                    'monto' => $invoice->monto,
                    'fecha_vencimiento' => $invoice->fecha_vencimiento->toDateString(),
                    'dias_gracia' => $diasGracia,
                ]));

                $this->auditLogger->log('tenant.invoice.overdue_reminder_sent', TenantInvoice::class, (string) $invoice->id, [
                    'tenant_id' => $tenant->id,
                    'folio_interno' => $invoice->folio_interno,
                ]);

                $resumen['recordatorios']++;
            }

            $puntoMedio = intdiv($diasGracia, 2);

            if ($diasVencido >= $puntoMedio && is_null($invoice->aviso_gracia_enviado_at)) {
                $this->mailer->enviar($contacto, new InvoiceGraceMidpointWarningMail([
                    'razon_social' => $contacto['razon_social'],
                    'folio_interno' => $invoice->folio_interno,
                    'monto' => $invoice->monto,
                    'fecha_vencimiento' => $invoice->fecha_vencimiento->toDateString(),
                    'dias_restantes' => max($diasGracia - $diasVencido, 0),
                ]));

                $invoice->aviso_gracia_enviado_at = now();
                $invoice->save();

                $this->auditLogger->log('tenant.invoice.grace_midpoint_notified', TenantInvoice::class, (string) $invoice->id, [
                    'tenant_id' => $tenant->id,
                    'folio_interno' => $invoice->folio_interno,
                    'dias_vencido' => $diasVencido,
                ]);

                $resumen['avisos_gracia']++;
            }

            if ($diasVencido >= $diasGracia && $tenant->status === 'activo') {
                $tenant->status = 'suspendido';
                $tenant->save();

                $this->mailer->enviar($contacto, new TenantSuspendedForNonPaymentMail([
                    'razon_social' => $contacto['razon_social'],
                    'folio_interno' => $invoice->folio_interno,
                    'fecha_vencimiento' => $invoice->fecha_vencimiento->toDateString(),
                ]));

                $this->auditLogger->log('tenant.subscription.suspended_for_nonpayment', Tenant::class, $tenant->id, [
                    'folio_interno' => $invoice->folio_interno,
                    'dias_vencido' => $diasVencido,
                    'dias_gracia' => $diasGracia,
                ]);

                $resumen['suspensiones']++;
            }
        }

        return $resumen;
    }

    private function diasGraciaDefault(): int
    {
        $valor = PlatformSetting::where('key', 'dias_gracia_suspension_default')->value('value');

        return $valor !== null ? (int) $valor : 60;
    }
}
