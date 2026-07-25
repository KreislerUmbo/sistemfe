<?php

namespace App\Services;

use App\Models\Central\PlatformSetting;
use App\Models\Central\TenantBackup;
use App\Models\Central\TenantRestore;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Fase C.3 (plan-panel-superadmin.md) — restauración, con fricción intencional por diseño
// explícito (no es un descuido, es la regla del plan): confirmación en 2 pasos con token,
// backup de seguridad obligatorio que GATEA la restauración real, y auditoría en cada
// checkpoint (preview, confirmación, resultado).
//
// Mecanismo de atomicidad, verificado con evidencia real antes de escribir este servicio
// (no asumido): `pg_restore --clean --if-exists --single-transaction` envuelve TODO el
// restore en una única transacción de Postgres — un fallo en cualquier punto (error de SQL
// real, o el propio proceso pg_restore muerto de un kill -9 mid-transacción) hace que
// Postgres revierta todo, dejando la base exactamente como estaba antes. Confirmado con 2
// pruebas reales aparte de este código: un fallo real de constraint FK a mitad de la
// secuencia de 363 entradas TOC, y 4 corridas matando el proceso pg_restore real con
// kill -9 (2 de ellas atrapadas en 'idle in transaction') — las 4 dejaron 0 backends/locks
// huérfanos y los datos originales intactos.
class TenantRestoreService
{
    private const TOKEN_TTL_MINUTOS = 10;

    public function __construct(
        private AuditLogger $auditLogger,
        private TenantBackupService $backupService,
    ) {
    }

    public function crearPreview(Tenant $tenant, TenantBackup $backup): TenantRestore
    {
        if ($backup->tenant_id !== $tenant->id) {
            throw new HttpException(404, 'Backup no encontrado para este tenant.');
        }

        if ($backup->estado !== 'completado') {
            throw new HttpException(422, 'Solo se puede restaurar desde un backup completado.');
        }

        if (! $backup->path || ! Storage::disk('private')->exists($backup->path)) {
            throw new HttpException(422, 'El archivo de este backup no existe en disco.');
        }

        $this->verificarSinRestauracionEnCurso($tenant);

        // Fase C.4 — revalida EN EL MOMENTO (no confía a ciegas en un
        // integridad_verificada guardado hace días/meses: el archivo pudo corromperse en
        // disco DESPUÉS de creado), reusando el mismo chequeo que ya corre automáticamente
        // al crear cada backup — un solo punto de verdad para "¿este dump es legible?",
        // sin duplicar la llamada a pg_restore --list acá.
        $backup = $this->backupService->verificarIntegridad($backup);

        if ($backup->integridad_verificada === false) {
            throw new HttpException(422, 'El backup no es un dump válido o está corrupto (falló pg_restore --list).');
        }

        $restore = TenantRestore::create([
            'tenant_id' => $tenant->id,
            'backup_id' => $backup->id,
            'estado' => 'pendiente_confirmacion',
            'confirm_token' => Str::random(40),
            'confirm_token_expires_at' => now()->addMinutes(self::TOKEN_TTL_MINUTOS),
        ]);

        $this->auditLogger->log('tenant.restore.preview_requested', TenantRestore::class, (string) $restore->id, [
            'tenant_id' => $tenant->id,
            'backup_id' => $backup->id,
        ]);

        return $restore->fresh();
    }

    public function confirmarYEjecutar(TenantRestore $restore): TenantRestore
    {
        if ($restore->estado !== 'pendiente_confirmacion') {
            throw new HttpException(422, 'Esta restauración ya fue confirmada o ya no está pendiente.');
        }

        if ($restore->confirm_token_expires_at->isPast()) {
            throw new HttpException(422, 'El token de confirmación expiró — pedí un nuevo preview.');
        }

        $tenant = $restore->tenant;
        $backup = $restore->backup;

        // Revalidación por si algo cambió entre el preview y la confirmación (TOCTOU): el
        // archivo pudo haberse borrado, el backup pudo haber sido podado por retención.
        if ($backup->estado !== 'completado' || ! $backup->path || ! Storage::disk('private')->exists($backup->path)) {
            $restore->update(['estado' => 'fallido', 'error_message' => 'El backup ya no está disponible al momento de confirmar.']);
            $this->auditLogger->log('tenant.restore.failed', TenantRestore::class, (string) $restore->id, [
                'tenant_id' => $tenant->id,
                'fase' => 'revalidacion',
            ]);

            throw new HttpException(422, 'El backup ya no está disponible — el archivo o el registro cambiaron desde el preview.');
        }

        $restore->update(['estado' => 'en_proceso', 'confirmed_at' => now()]);

        $this->auditLogger->log('tenant.restore.confirmed', TenantRestore::class, (string) $restore->id, [
            'tenant_id' => $tenant->id,
            'backup_id' => $backup->id,
        ]);

        $statusPrevio = $tenant->status;
        $dbName = $tenant->database()->getName();

        // Suspender corta acceso NUEVO (reusa TenantSubscriptionMiddleware de B.2.2, no un
        // mecanismo de "mantenimiento" aparte) — necesario porque pg_restore -1 sostiene
        // locks exclusivos durante toda la transacción; cualquier request concurrente
        // tocando esas tablas se colgaría esperando el lock, no fallaría con error.
        if ($statusPrevio !== 'suspendido') {
            $tenant->update(['status' => 'suspendido']);
        }

        try {
            // Limpia conexiones sueltas que hayan quedado abiertas contra la base del
            // tenant ANTES de suspenderlo (una request que ya estaba en curso) — evita que
            // el restore se cuelgue esperando un lock que sostiene una conexión viva.
            DB::connection('central')->select(
                'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()',
                [$dbName]
            );

            // Regla no negociable: si el backup de seguridad falla, la restauración real
            // NUNCA arranca. crearPreRestore() ya deja su propia fila 'fallido' + audit log
            // si algo sale mal (mismo camino que crearManual()/generarAutomaticoParaTodos()).
            try {
                $preRestoreBackup = $this->backupService->crearPreRestore($tenant);
            } catch (\Throwable $e) {
                $restore->update([
                    'estado' => 'fallido',
                    'error_message' => 'El backup de seguridad previo falló — la restauración NO se ejecutó: ' . $e->getMessage(),
                ]);

                $this->auditLogger->log('tenant.restore.failed', TenantRestore::class, (string) $restore->id, [
                    'tenant_id' => $tenant->id,
                    'fase' => 'backup_de_seguridad',
                ]);

                throw new HttpException(500, $restore->error_message);
            }

            $restore->update(['pre_restore_backup_id' => $preRestoreBackup->id]);

            $absolutePath = Storage::disk('private')->path($backup->path);

            $result = Process::timeout(600)
                ->env([
                    'PGPASSWORD' => config('database.connections.central.password'),
                    // Mismo bug de B.2 (Fase C.1) — Process::run() bajo el SAPI del
                    // servidor embebido queda sin SystemRoot, rompe Winsock.
                    'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
                    // Defensa en profundidad para el escenario de partición de red que NO
                    // pudimos ejercitar en la prueba real (kill -9 de un proceso local sí
                    // se detecta al instante vía cierre de socket — una muerte silenciosa
                    // de red, no). Sin esto, idle_in_transaction_session_timeout del
                    // servidor es 0 (sin límite) — acá lo acotamos por conexión.
                    'PGOPTIONS' => '-c idle_in_transaction_session_timeout=300000',
                ])
                ->run([
                    $this->pgRestorePath(),
                    '-h', (string) config('database.connections.central.host'),
                    '-p', (string) config('database.connections.central.port'),
                    '-U', (string) config('database.connections.central.username'),
                    '-d', $dbName,
                    '--clean', '--if-exists', '-1',
                    $absolutePath,
                ]);

            if ($result->failed()) {
                // -1/--single-transaction ya garantizó que Postgres revirtió todo — esto
                // solo registra el fallo, no repara nada (no hay nada que reparar).
                $rawError = $result->errorOutput() ?: $result->output();
                $safeError = @iconv('UTF-8', 'UTF-8//IGNORE', $rawError) ?: '(mensaje de error no representable)';

                $restore->update([
                    'estado' => 'fallido',
                    'error_message' => substr($safeError, 0, 1000),
                ]);

                $this->auditLogger->log('tenant.restore.failed', TenantRestore::class, (string) $restore->id, [
                    'tenant_id' => $tenant->id,
                    'fase' => 'restore',
                    'backup_id' => $backup->id,
                    'pre_restore_backup_id' => $preRestoreBackup->id,
                ]);

                throw new HttpException(500, 'La restauración falló y fue revertida por completo: ' . $restore->error_message);
            }

            $restore->update(['estado' => 'completado']);

            $this->auditLogger->log('tenant.restore.completed', TenantRestore::class, (string) $restore->id, [
                'tenant_id' => $tenant->id,
                'backup_id' => $backup->id,
                'pre_restore_backup_id' => $preRestoreBackup->id,
            ]);

            return $restore->fresh();
        } finally {
            // Pase lo que pase (éxito, fallo del backup de seguridad, fallo del restore),
            // el tenant vuelve exactamente al status que tenía antes — nunca lo reactivamos
            // si ya estaba suspendido por falta de pago antes de empezar.
            if ($statusPrevio !== 'suspendido') {
                $tenant->update(['status' => $statusPrevio]);
            }
        }
    }

    private function verificarSinRestauracionEnCurso(Tenant $tenant): void
    {
        $bloqueante = TenantRestore::where('tenant_id', $tenant->id)
            ->where(function ($q) {
                $q->where('estado', 'en_proceso')
                    ->orWhere(function ($q2) {
                        $q2->where('estado', 'pendiente_confirmacion')
                            ->where('confirm_token_expires_at', '>', now());
                    });
            })
            ->exists();

        if ($bloqueante) {
            throw new HttpException(422, 'Ya hay una restauración pendiente o en curso para este tenant.');
        }
    }

    private function pgRestorePath(): string
    {
        return PlatformSetting::where('key', 'pg_restore_path')->value('value') ?? 'pg_restore';
    }
}
