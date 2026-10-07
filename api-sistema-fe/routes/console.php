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
// 07-oct-2026: la app corre en UTC; sin ->timezone() estas horas eran UTC (00:00 UTC = 19:00
// de Lima del día anterior, y "vencido" se evaluaba con la fecha UTC, ya del día siguiente).
// Todas las tareas de este archivo se escriben en hora de Lima.
Schedule::command('tenants:generate-monthly-invoices')->daily()->timezone('America/Lima');

// Fase B.2.4 — corre después de la generación de invoices, mismo mecanismo (Scheduler +
// cron del sistema, sin worker de colas). Idempotente por diseño (ver
// TenantOverduePaymentService), tolera reintentos y corridas dobles el mismo día.
Schedule::command('tenants:check-overdue-payments')->daily()->timezone('America/Lima');

// Fase C.2 — backups automáticos, mismo mecanismo (Scheduler + cron del sistema, sin
// worker de colas). ->dailyAt() en horario de baja actividad, no medianoche exacta —
// evita competir con el corte de las otras dos tareas diarias de arriba (pg_dump de
// varios tenants puede tardar). Idempotente por diseño (un backup automático por tenant
// por día, ver TenantBackupService::generarAutomaticoParaTodos()).
// 03:00 de Lima (decisión del usuario 07-oct-2026): sin usuarios operando y después del castigo
// automático de créditos (02:30). Antes "02:00" sin zona corría a las 21:00 de Lima.
Schedule::command('tenants:run-automatic-backups')->dailyAt('03:00')->timezone('America/Lima');

// Plan — Integración API Tipo de Cambio SUNAT, Fase 3. El tipo de cambio
// SBS/SUNAT es el cierre del día hábil anterior — cambia una sola vez al
// día, no tiene sentido consultarlo más seguido. 05:00 (antes de que
// arranque operación) para no competir con las 3 tareas de arriba.
// Idempotente por diseño (TipoCambioSunatSyncService::sincronizar() usa
// updateOrCreate por fecha) — tolera reintentos y corridas dobles el mismo
// día. Tabla central (db_tenant_central), un solo dato para todo el
// sistema — no depende de qué tenant dispare el cron.
Schedule::command('tipo-cambio:sincronizar-sunat')->dailyAt('05:00')->timezone('America/Lima');
// Módulo Créditos (00 1.11): castigo automático por atraso, en la madrugada de Lima (la app corre en UTC).
Schedule::command('creditos:escalamiento')->dailyAt('02:30')->timezone('America/Lima');
// Módulo Créditos (04d): foto diaria de cartera al cierre del día de Lima (tendencias e históricos).
Schedule::command('creditos:foto-cartera')->dailyAt('23:50')->timezone('America/Lima');
