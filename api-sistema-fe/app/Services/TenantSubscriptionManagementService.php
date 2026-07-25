<?php

namespace App\Services;

use App\Mail\TenantReactivatedMail;
use App\Mail\TenantSuspendedManuallyMail;
use App\Models\Central\TenantInvoice;
use App\Models\Central\TenantPaymentVoucher;
use App\Models\Central\TenantSubscription;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Fase B.2.5 (plan-panel-superadmin.md) — gestión manual de suscripciones/pagos desde el
// panel superadmin, mismo criterio que TenantInvoiceService/TenantOverduePaymentService
// (servicio = único punto de verdad, controller = wrapper delgado).
class TenantSubscriptionManagementService
{
    public function __construct(
        private AuditLogger $auditLogger,
        private TenantContactMailer $mailer,
    ) {
    }

    public function marcarPagado(TenantInvoice $invoice): TenantInvoice
    {
        if ($invoice->estado === 'pagado') {
            throw new HttpException(422, 'Este invoice ya está marcado como pagado.');
        }

        $invoice->estado = 'pagado';
        $invoice->fecha_pago = now()->toDateString();
        $invoice->save();

        $this->auditLogger->log('tenant.invoice.paid_manually', TenantInvoice::class, (string) $invoice->id, [
            'tenant_id' => $invoice->tenant_id,
            'folio_interno' => $invoice->folio_interno,
        ]);

        $this->intentarReactivarPorPago($invoice->tenant);

        return $invoice->fresh();
    }

    public function subirVoucher(TenantInvoice $invoice, UploadedFile $file): TenantPaymentVoucher
    {
        // Disco 'private' fuera de $tenant->run(): tenant_payment_vouchers es un recurso
        // central (billing de la plataforma, no dato propio del negocio del tenant), así
        // que NO debe caer en la partición por tenant de FilesystemTenancyBootstrapper —
        // sin tenancy inicializada acá (panel vive fuera de esa resolución), el disco
        // 'private' resuelve directo a la carpeta central compartida. Mismo criterio ya
        // documentado en la migración: nunca en 'public' (datos bancarios).
        $path = Storage::disk('private')->putFile('billing/vouchers', $file);

        $voucher = TenantPaymentVoucher::create([
            'tenant_invoice_id' => $invoice->id,
            'path' => $path,
            'estado' => 'pendiente_verificacion',
        ]);

        $this->auditLogger->log('tenant.invoice.voucher_uploaded', TenantInvoice::class, (string) $invoice->id, [
            'tenant_id' => $invoice->tenant_id,
            'folio_interno' => $invoice->folio_interno,
            'voucher_id' => $voucher->id,
        ]);

        return $voucher;
    }

    public function verificarVoucher(TenantPaymentVoucher $voucher): TenantPaymentVoucher
    {
        if ($voucher->estado !== 'pendiente_verificacion') {
            throw new HttpException(422, 'Este voucher ya fue procesado (verificado o rechazado).');
        }

        $voucher->estado = 'verificado';
        $voucher->save();

        $this->auditLogger->log('tenant.invoice.voucher_verified', TenantInvoice::class, (string) $voucher->tenant_invoice_id, [
            'voucher_id' => $voucher->id,
        ]);

        // Verificar el voucher ES la confirmación de pago — mismo camino que marcarPagado()
        // manual, incluida la reactivación automática si corresponde.
        $this->marcarPagado($voucher->invoice);

        return $voucher->fresh();
    }

    public function rechazarVoucher(TenantPaymentVoucher $voucher, string $motivo): TenantPaymentVoucher
    {
        if ($voucher->estado !== 'pendiente_verificacion') {
            throw new HttpException(422, 'Este voucher ya fue procesado (verificado o rechazado).');
        }

        $voucher->estado = 'rechazado';
        $voucher->motivo_rechazo = $motivo;
        $voucher->save();

        $this->auditLogger->log('tenant.invoice.voucher_rejected', TenantInvoice::class, (string) $voucher->tenant_invoice_id, [
            'voucher_id' => $voucher->id,
            'motivo' => $motivo,
        ]);

        return $voucher->fresh();
    }

    public function suspenderManual(Tenant $tenant, string $motivo): Tenant
    {
        if ($tenant->status === 'archivado') {
            throw new HttpException(422, 'No se puede suspender un tenant archivado.');
        }

        if ($tenant->status === 'suspendido') {
            throw new HttpException(422, 'Este tenant ya está suspendido.');
        }

        $tenant->status = 'suspendido';
        $tenant->save();

        $contacto = $this->mailer->resolverContacto($tenant);
        $this->mailer->enviar($contacto, new TenantSuspendedManuallyMail([
            'razon_social' => $contacto['razon_social'],
            'motivo' => $motivo,
        ]));

        $this->auditLogger->log('tenant.subscription.suspended_manually', Tenant::class, $tenant->id, [
            'motivo' => $motivo,
        ]);

        return $tenant->fresh();
    }

    public function reactivarManual(Tenant $tenant): Tenant
    {
        if ($tenant->status !== 'suspendido') {
            throw new HttpException(422, 'Este tenant no está suspendido.');
        }

        return $this->reactivar($tenant, automatica: false);
    }

    public function toggleAutomatica(TenantSubscription $subscription): TenantSubscription
    {
        $subscription->facturacion_automatica = ! $subscription->facturacion_automatica;
        $subscription->save();

        $this->auditLogger->log('tenant.subscription.automatic_billing_toggled', TenantSubscription::class, (string) $subscription->id, [
            'tenant_id' => $subscription->tenant_id,
            'facturacion_automatica' => $subscription->facturacion_automatica,
        ]);

        return $subscription->fresh();
    }

    /**
     * Decisión de negocio confirmada (2026-07-20): si un tenant suspendido paga (manual o
     * por voucher) y ya no le queda NINGÚN invoice vencido sin pagar, se reactiva solo —
     * no hace falta una acción manual aparte. Evita dejar a un cliente que ya pagó
     * bloqueado hasta que alguien note el ticket. Si todavía le queda otro invoice vencido
     * (deuda parcial), sigue suspendido.
     */
    private function intentarReactivarPorPago(Tenant $tenant): void
    {
        if ($tenant->status !== 'suspendido') {
            return;
        }

        $quedanVencidos = TenantInvoice::where('tenant_id', $tenant->id)
            ->where('estado', '!=', 'pagado')
            ->where('fecha_vencimiento', '<=', now()->toDateString())
            ->exists();

        if ($quedanVencidos) {
            return;
        }

        $this->reactivar($tenant, automatica: true);
    }

    private function reactivar(Tenant $tenant, bool $automatica): Tenant
    {
        $tenant->status = 'activo';
        $tenant->save();

        $contacto = $this->mailer->resolverContacto($tenant);
        $this->mailer->enviar($contacto, new TenantReactivatedMail([
            'razon_social' => $contacto['razon_social'],
        ]));

        $this->auditLogger->log(
            $automatica ? 'tenant.subscription.reactivated_automatically' : 'tenant.subscription.reactivated_manually',
            Tenant::class,
            $tenant->id
        );

        return $tenant->fresh();
    }
}
