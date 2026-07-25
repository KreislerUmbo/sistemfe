<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Panel superadmin (plan-panel-superadmin.md, Fase B.2.3) — sin worker de colas en este
// proyecto ("Nota de infraestructura" del plan), todo lo recurrente va por Scheduler +
// cron del sistema. ->daily() en vez de "el día del corte": la generación es idempotente
// por (tenant_subscription_id, periodo), así que correr todos los días tolera que el cron
// se caiga justo el día 1 sin perder el período — se genera igual apenas vuelve a correr,
// sin duplicar nada para los tenants que ya lo tenían.
Schedule::command('tenants:generate-monthly-invoices')->daily();

// Fase B.2.4 — corre después de la generación de invoices, mismo mecanismo (Scheduler +
// cron del sistema, sin worker de colas). Idempotente por diseño (ver
// TenantOverduePaymentService), tolera reintentos y corridas dobles el mismo día.
Schedule::command('tenants:check-overdue-payments')->daily();

// Fase C.2 — backups automáticos, mismo mecanismo (Scheduler + cron del sistema, sin
// worker de colas). ->dailyAt() en horario de baja actividad, no medianoche exacta —
// evita competir con el corte de las otras dos tareas diarias de arriba (pg_dump de
// varios tenants puede tardar). Idempotente por diseño (un backup automático por tenant
// por día, ver TenantBackupService::generarAutomaticoParaTodos()).
Schedule::command('tenants:run-automatic-backups')->dailyAt('02:00');
