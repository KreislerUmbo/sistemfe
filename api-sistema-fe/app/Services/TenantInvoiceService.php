<?php

namespace App\Services;

use App\Models\Central\PlatformSetting;
use App\Models\Central\TenantInvoice;
use App\Models\Central\TenantSubscription;
use Carbon\Carbon;

// Fase B.2.3 (plan-panel-superadmin.md) — único punto de verdad para generar un
// tenant_invoice, compartido por el endpoint manual (TenantSubscriptionController) y el
// scheduled command (tenants:generate-monthly-invoices), mismo criterio que
// TenantProvisioningService en Fase A.
class TenantInvoiceService
{
    /**
     * Idempotente por diseño: UNIQUE(tenant_subscription_id, periodo) en BD es la
     * garantía real, pero se chequea antes también para devolver la fila existente en
     * vez de reventar contra el constraint (tolera reintentos/corridas dobles del cron,
     * ver "Casos de negocio" del plan).
     */
    public function generarParaPeriodo(TenantSubscription $subscription, string $periodo): TenantInvoice
    {
        $existente = TenantInvoice::where('tenant_subscription_id', $subscription->id)
            ->where('periodo', $periodo)
            ->first();

        if ($existente) {
            return $existente;
        }

        $monto = $subscription->monto_mensual_override ?? $subscription->plan->precio_mensual;
        $diaCorte = $subscription->dia_corte ?? $this->diaCorteDefault();

        return TenantInvoice::create([
            'tenant_subscription_id' => $subscription->id,
            'tenant_id' => $subscription->tenant_id,
            'periodo' => $periodo,
            // Determinístico a partir del par único (tenant_subscription_id, periodo) —
            // sin necesitar un contador separado ni un update post-create.
            'folio_interno' => 'INV-' . $subscription->id . '-' . str_replace('-', '', $periodo),
            'monto' => $monto,
            'fecha_vencimiento' => $this->calcularFechaVencimiento($periodo, $diaCorte),
            'estado' => 'pendiente',
        ]);
    }

    /**
     * Solo suscripciones 'activa' + facturacion_automatica=true (Fase B.2.3, "manual por
     * defecto, automática opcional por tenant"). No filtra por tenants.status — si un
     * tenant se archiva, su suscripción debería cancelarse aparte (no automatizado
     * todavía, gap documentado en el plan).
     */
    public function generarMensualParaActivas(string $periodo): array
    {
        $subscriptions = TenantSubscription::where('estado', 'activa')
            ->where('facturacion_automatica', true)
            ->get();

        $nuevas = 0;
        $existentes = 0;
        $invoices = [];

        foreach ($subscriptions as $subscription) {
            $yaExistia = TenantInvoice::where('tenant_subscription_id', $subscription->id)
                ->where('periodo', $periodo)
                ->exists();

            $invoice = $this->generarParaPeriodo($subscription, $periodo);
            $invoices[] = $invoice;

            $yaExistia ? $existentes++ : $nuevas++;
        }

        return [
            'nuevas' => $nuevas,
            'existentes' => $existentes,
            'invoices' => $invoices,
        ];
    }

    private function diaCorteDefault(): int
    {
        $valor = PlatformSetting::where('key', 'dia_corte_default')->value('value');

        return $valor !== null ? (int) $valor : 28;
    }

    private function calcularFechaVencimiento(string $periodo, int $diaCorte): string
    {
        [$anio, $mes] = explode('-', $periodo);

        // Clamp al último día real del mes (ej. dia_corte=31 en febrero -> 28/29).
        $diasEnMes = Carbon::createFromDate((int) $anio, (int) $mes, 1)->daysInMonth;
        $dia = min($diaCorte, $diasEnMes);

        return Carbon::createFromDate((int) $anio, (int) $mes, $dia)->toDateString();
    }
}
