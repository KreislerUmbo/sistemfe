<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Tenant;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

// Fase B.2.4/B.2.5 (plan-panel-superadmin.md) — extraído de TenantOverduePaymentService
// (B.2.4) al necesitarse de nuevo en TenantSubscriptionManagementService (B.2.5, mismo
// patrón "un solo punto de verdad" ya usado en el resto del proyecto: CashCorrectionService,
// ExpectedCashCalculator, etc.). Resuelve el contacto de un tenant y envía un Mailable de
// forma segura, sin que un fallo de notificación bloquee la operación de negocio que la
// originó (la fila real ya se persiste antes de llamar acá).
class TenantContactMailer
{
    /**
     * Company vive en la BD propia del tenant, no en central — resolver el contacto exige
     * $tenant->run(). Nunca lanza dentro del closure: TenantRun::run() (vendor/stancl/
     * tenancy) no tiene try/finally, un throw ahí deja tenancy() pegada al tenant
     * equivocado por el resto del proceso (mismo gotcha ya documentado en
     * TenantSunatController).
     */
    public function resolverContacto(Tenant $tenant): array
    {
        return $tenant->run(function () use ($tenant) {
            try {
                $company = Company::first();

                return [
                    'email' => $company?->email,
                    'razon_social' => $company?->razon_social ?? $tenant->id,
                ];
            } catch (\Throwable $e) {
                report($e);

                return ['email' => null, 'razon_social' => $tenant->id];
            }
        });
    }

    public function enviar(array $contacto, Mailable $mail): void
    {
        if (! $contacto['email']) {
            return;
        }

        try {
            Mail::to($contacto['email'])->send($mail);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
