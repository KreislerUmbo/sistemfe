<?php

namespace App\Services;

use App\Mail\TenantBackupFailedMail;
use App\Models\Central\CentralUser;
use App\Models\Central\PlatformSetting;
use App\Models\Central\TenantBackup;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Fase C.1/C.2 (plan-panel-superadmin.md) — backups por tenant, único punto de verdad
// (comando/controller = wrapper delgado). Siempre pg_dump de UN tenant a la vez (nunca
// pg_dumpall — regla explícita del plan: un backup gigante de toda la instancia no sirve
// para restaurar un solo tenant, y expondría datos de todos los negocios en un solo
// archivo).
class TenantBackupService
{
    public function __construct(private AuditLogger $auditLogger)
    {
    }

    public function crearManual(Tenant $tenant): TenantBackup
    {
        return $this->ejecutarDump($tenant, 'manual');
    }

    /**
     * Fase C.3 — backup de seguridad obligatorio ANTES de cualquier restauración real.
     * Mismo método compartido que crearManual()/generarAutomaticoParaTodos() (con sus fixes
     * de SystemRoot/encoding ya incluidos) — si esto lanza, TenantRestoreService debe
     * abortar la restauración sin tocar nada más (regla no negociable del diseño de C.3).
     */
    public function crearPreRestore(Tenant $tenant): TenantBackup
    {
        return $this->ejecutarDump($tenant, 'pre_restore');
    }

    /**
     * Fase C.2 — corre para todos los tenants no archivados (backups es una preocupación
     * de infraestructura/continuidad, no de facturación — a diferencia de
     * TenantInvoiceService::generarMensualParaActivas(), no filtra por
     * facturacion_automatica ni por tenants.status='suspendido': un tenant suspendido por
     * falta de pago igual necesita que sus datos sigan respaldados). Idempotente por
     * diseño: si el tenant ya tiene un backup automático 'completado' creado HOY, no
     * genera un segundo — tolera reintentos/corridas dobles del cron, mismo criterio que
     * TenantInvoiceService/TenantOverduePaymentService.
     */
    public function generarAutomaticoParaTodos(): array
    {
        $hoy = Carbon::today();
        $resumen = ['nuevos' => 0, 'ya_existia' => 0, 'fallidos' => 0];

        $tenants = Tenant::where('status', '!=', 'archivado')->get();

        foreach ($tenants as $tenant) {
            $yaExiste = TenantBackup::where('tenant_id', $tenant->id)
                ->where('tipo', 'automatico')
                ->where('estado', 'completado')
                ->whereDate('created_at', $hoy)
                ->exists();

            if ($yaExiste) {
                $resumen['ya_existia']++;
            } else {
                try {
                    $this->ejecutarDump($tenant, 'automatico');
                    $resumen['nuevos']++;
                } catch (\Throwable $e) {
                    // ejecutarDump() ya dejó la fila 'fallido' + el audit log — acá solo
                    // se evita que el fallo de UN tenant frene el resto del lote, y se
                    // dispara la notificación ("notificación en caso de fallo", regla
                    // explícita del plan para C.2).
                    $resumen['fallidos']++;
                    $this->notificarFallo($tenant, $e->getMessage());
                }
            }

            $this->aplicarRetencion($tenant);
        }

        return $resumen;
    }

    private function ejecutarDump(Tenant $tenant, string $tipo): TenantBackup
    {
        $backup = TenantBackup::create([
            'tenant_id' => $tenant->id,
            'tipo' => $tipo,
            'estado' => 'en_proceso',
        ]);

        $dbName = $tenant->database()->getName();
        $relativePath = sprintf(
            'backups/%s/%s_%s.dump',
            $tenant->id,
            $tenant->id,
            now()->format('Ymd_His')
        );

        Storage::disk('private')->makeDirectory('backups/' . $tenant->id);
        $absolutePath = Storage::disk('private')->path($relativePath);

        $result = Process::timeout(300)
            ->env([
                'PGPASSWORD' => config('database.connections.central.password'),
                // Bajo el SAPI del servidor embebido (`php artisan serve`), $_SERVER no
                // trae SystemRoot (sí lo trae bajo CLI) — Symfony\Process solo hereda al
                // hijo las variables presentes en getenv() ∩ $_SERVER
                // (Process::getDefaultEnv()), así que sin esto pg_dump.exe queda sin
                // SystemRoot y Winsock falla resolviendo "localhost" ("Non-recoverable
                // failure in name resolution") — bug real, reproducido y diagnosticado
                // con byte-dump del stderr antes de identificar la causa.
                'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
            ])
            ->run([
                $this->pgDumpPath(),
                '-h', (string) config('database.connections.central.host'),
                '-p', (string) config('database.connections.central.port'),
                '-U', (string) config('database.connections.central.username'),
                '-Fc',
                '-f', $absolutePath,
                $dbName,
            ]);

        if ($result->failed()) {
            // Nunca dejar un .dump parcial/corrupto en disco confundible con uno real.
            if (Storage::disk('private')->exists($relativePath)) {
                Storage::disk('private')->delete($relativePath);
            }

            // iconv(...IGNORE) descarta bytes inválidos en vez de asumir una codepage de
            // origen concreta — pg_dump.exe en Windows emite errores en la codepage de
            // consola del sistema (no UTF-8); guardar el byte crudo revienta el INSERT
            // (Postgres exige UTF-8 válido) y esconde el error real detrás de una
            // excepción de encoding — confirmado real, no hipotético (0xAB de «localhost»).
            $rawError = $result->errorOutput() ?: $result->output();
            $safeError = @iconv('UTF-8', 'UTF-8//IGNORE', $rawError) ?: '(mensaje de error no representable)';

            $backup->update([
                'estado' => 'fallido',
                'error_message' => substr($safeError, 0, 1000),
            ]);

            $this->auditLogger->log('tenant.backup.failed', TenantBackup::class, (string) $backup->id, [
                'tenant_id' => $tenant->id,
                'tipo' => $tipo,
            ]);

            throw new HttpException(500, 'El backup falló: ' . $backup->error_message);
        }

        $backup->update([
            'path' => $relativePath,
            'size_bytes' => Storage::disk('private')->size($relativePath),
            'estado' => 'completado',
        ]);

        // Fase C.4 — verificar apenas termina, no recién cuando alguien intente
        // restaurar. Si pg_dump "tuvo éxito" pero el archivo resultante no es un dump
        // legible (disco lleno a mitad de la escritura, corte de luz), tratarlo como
        // fallo real — nunca dejar un backup corrupto con estado='completado'.
        $backup = $this->verificarIntegridad($backup->fresh());

        if ($backup->integridad_verificada === false) {
            if (Storage::disk('private')->exists($relativePath)) {
                Storage::disk('private')->delete($relativePath);
            }

            $backup->update([
                'estado' => 'fallido',
                'path' => null,
                'error_message' => 'El backup se generó pero no pasó la verificación de integridad (pg_restore --list falló) — probablemente quedó truncado o corrupto.',
            ]);

            $this->auditLogger->log('tenant.backup.failed', TenantBackup::class, (string) $backup->id, [
                'tenant_id' => $tenant->id,
                'tipo' => $tipo,
                'fase' => 'verificacion_integridad',
            ]);

            throw new HttpException(500, 'El backup falló: ' . $backup->error_message);
        }

        $this->auditLogger->log('tenant.backup.created', TenantBackup::class, (string) $backup->id, [
            'tenant_id' => $tenant->id,
            'tipo' => $tipo,
            'size_bytes' => $backup->size_bytes,
        ]);

        return $backup->fresh();
    }

    /**
     * Fase C.4 — único punto de verdad para "¿este dump es legible?" (pg_restore --list,
     * nunca toca ninguna base). Usado automáticamente al crear cada backup (arriba), por
     * TenantRestoreService::crearPreview() (revalida en el momento de restaurar, no solo
     * confía en el valor guardado — el archivo pudo corromperse en disco DESPUÉS de
     * creado), y por reverificar() para re-chequear un backup viejo bajo demanda.
     */
    public function verificarIntegridad(TenantBackup $backup): TenantBackup
    {
        if (! $backup->path || ! Storage::disk('private')->exists($backup->path)) {
            $backup->update(['integridad_verificada' => false, 'verificado_at' => now()]);

            return $backup->fresh();
        }

        $absolutePath = Storage::disk('private')->path($backup->path);
        $listado = Process::timeout(60)->run([$this->pgRestorePath(), '--list', $absolutePath]);

        $backup->update([
            'integridad_verificada' => $listado->successful(),
            'verificado_at' => now(),
        ]);

        return $backup->fresh();
    }

    /**
     * Re-chequeo bajo demanda de un backup ya existente (bit rot, disco degradado
     * después de creado) — a diferencia del chequeo automático de ejecutarDump(), NO
     * marca el backup 'fallido' ni borra el archivo solo por encontrarlo corrupto: es
     * una consulta explícita de un central_user, que decide qué hacer con el hallazgo
     * (el archivo puede servir para inspección forense aunque ya no sea restaurable).
     */
    public function reverificar(TenantBackup $backup): TenantBackup
    {
        if ($backup->estado !== 'completado') {
            throw new HttpException(422, 'Solo se puede re-verificar un backup completado.');
        }

        $backup = $this->verificarIntegridad($backup);

        $this->auditLogger->log('tenant.backup.integrity_checked', TenantBackup::class, (string) $backup->id, [
            'tenant_id' => $backup->tenant_id,
            'integridad_verificada' => $backup->integridad_verificada,
        ]);

        return $backup;
    }

    /**
     * Solo poda backups AUTOMÁTICOS más viejos que el umbral — nunca 'manual' (esos son
     * una acción deliberada de un central_user, no algo que un cron deba borrar en
     * silencio).
     */
    private function aplicarRetencion(Tenant $tenant): void
    {
        $limite = Carbon::today()->subDays($this->retencionDiasDefault());

        $viejos = TenantBackup::where('tenant_id', $tenant->id)
            ->where('tipo', 'automatico')
            ->where('created_at', '<', $limite)
            ->get();

        foreach ($viejos as $backup) {
            if ($backup->path && Storage::disk('private')->exists($backup->path)) {
                Storage::disk('private')->delete($backup->path);
            }

            $backup->delete();
        }

        if ($viejos->isNotEmpty()) {
            // auditable = Tenant, no TenantBackup: la poda borra VARIAS filas a la vez,
            // sin un solo backup al que referenciar — el sujeto real del evento es "a
            // este tenant se le podaron N backups viejos", no un backup puntual.
            $this->auditLogger->log('tenant.backup.pruned', Tenant::class, $tenant->id, [
                'cantidad' => $viejos->count(),
                'ids_borrados' => $viejos->pluck('id')->all(),
            ]);
        }
    }

    private function notificarFallo(Tenant $tenant, string $mensaje): void
    {
        $emails = CentralUser::whereNotNull('email')->pluck('email')->filter()->all();

        if (empty($emails)) {
            return;
        }

        try {
            Mail::to($emails)->send(new TenantBackupFailedMail([
                'tenant_id' => $tenant->id,
                'razon_social' => $tenant->razon_social ?? $tenant->id,
                'mensaje' => $mensaje,
            ]));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function pgDumpPath(): string
    {
        return PlatformSetting::where('key', 'pg_dump_path')->value('value') ?? 'pg_dump';
    }

    private function pgRestorePath(): string
    {
        return PlatformSetting::where('key', 'pg_restore_path')->value('value') ?? 'pg_restore';
    }

    private function retencionDiasDefault(): int
    {
        $valor = PlatformSetting::where('key', 'dias_retencion_backups_default')->value('value');

        return $valor !== null ? (int) $valor : 30;
    }
}
