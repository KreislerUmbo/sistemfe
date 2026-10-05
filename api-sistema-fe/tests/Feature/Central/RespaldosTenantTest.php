<?php

declare(strict_types=1);

namespace Tests\Feature\Central;

use App\Models\Central\TenantBackup;
use App\Models\Tenant;
use App\Services\TenantBackupService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Respaldos por tenant (panel superadmin), revisión 05-oct-2026 con dos fallas reales de producción:
 * - La poda de un tenant borraba un backup referenciado por tenant_restores (FK RESTRICT); la
 *   excepción abortaba el comando y los tenants siguientes (dakamu) quedaban sin backup cada noche.
 * - El manual y el previo a restaurar fallaban al hacer chmod de una carpeta ajena (web = www-data,
 *   cron = umbo) y la fila quedaba 'en_proceso' para siempre.
 * pg_dump/pg_restore simulados (Process::fake) y disco 'private' falso.
 */
final class RespaldosTenantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'sistemafe_test_migrations',
            'database.connections.central.database' => 'sistemafe_test_migrations',
        ]);
        DB::purge('pgsql');
        DB::purge('central');
        DB::beginTransaction();
        DB::connection('central')->beginTransaction();

        Storage::fake('private');
        Mail::fake();
        // pg_dump escribe el archivo que se le pide con -f; pg_restore --list responde OK.
        Process::fake(function (PendingProcess $proceso) {
            $comando = (array) $proceso->command;
            $i = array_search('-f', $comando, true);
            if ($i !== false) {
                file_put_contents($comando[$i + 1], 'PGDMP simulado');
            }

            return Process::result('ok');
        });
    }

    protected function tearDown(): void
    {
        DB::connection('central')->rollBack();
        DB::rollBack();
        parent::tearDown();
    }

    private function tenant(string $id): Tenant
    {
        // Fila directa: Tenant::create dispararía la creación real de la base del tenant.
        DB::connection('central')->table('tenants')->insert(['id' => $id, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        return Tenant::findOrFail($id);
    }

    /** Backup automático viejo (fuera de la retención de 30 días) con su archivo. */
    private function backupViejo(string $tenantId, int $dias): TenantBackup
    {
        $path = "backups/{$tenantId}/{$tenantId}_viejo_{$dias}.dump";
        Storage::disk('private')->put($path, 'PGDMP viejo');
        $backup = TenantBackup::create(['tenant_id' => $tenantId, 'tipo' => 'automatico', 'estado' => 'completado', 'path' => $path]);
        $backup->forceFill(['created_at' => now()->subDays($dias)])->save();

        return $backup;
    }

    private function restaurado(TenantBackup $backup): void
    {
        DB::connection('central')->table('tenant_restores')->insert([
            'tenant_id' => $backup->tenant_id, 'backup_id' => $backup->id, 'estado' => 'completado',
            'confirm_token' => Str::random(40), 'confirm_token_expires_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function automaticosDeHoy(string $tenantId): int
    {
        return TenantBackup::where('tenant_id', $tenantId)->where('tipo', 'automatico')->where('estado', 'completado')->whereDate('created_at', today())->count();
    }

    public function test_un_backup_usado_en_una_restauracion_no_frena_el_lote_ni_se_poda(): void
    {
        $this->tenant('resp-market');
        $this->tenant('resp-dakamu');
        // Los dos tenants tienen un backup viejo restaurado: con el código anterior, el primero que
        // se procesara abortaba el comando y el otro se quedaba sin backup, sin importar el orden.
        $restauradoMarket = $this->backupViejo('resp-market', 40);
        $this->restaurado($restauradoMarket);
        $this->restaurado($this->backupViejo('resp-dakamu', 40));
        $comun = $this->backupViejo('resp-market', 45);

        $resumen = app(TenantBackupService::class)->generarAutomaticoParaTodos();

        $this->assertSame(0, $resumen['fallidos']);
        $this->assertSame(1, $this->automaticosDeHoy('resp-market'));
        $this->assertSame(1, $this->automaticosDeHoy('resp-dakamu'));
        // El restaurado se conserva (fila y archivo); el viejo sin restauración se poda entero.
        $this->assertNotNull($restauradoMarket->fresh());
        Storage::disk('private')->assertExists($restauradoMarket->path);
        $this->assertNull($comun->fresh());
        Storage::disk('private')->assertMissing($comun->path);
    }

    public function test_una_falla_en_la_poda_de_un_tenant_no_deja_a_los_demas_sin_backup(): void
    {
        $this->tenant('resp-market');
        $this->tenant('resp-dakamu');
        $this->backupViejo('resp-market', 40);
        $this->backupViejo('resp-dakamu', 40);
        // Una falla cualquiera en la poda (aquí: el disco no deja borrar) solo se registra.
        $disco = \Mockery::mock(Storage::disk('private'))->makePartial();
        $disco->shouldReceive('delete')->andThrow(new \RuntimeException('Disco sin permiso de borrado.'));
        Storage::set('private', $disco);

        $resumen = app(TenantBackupService::class)->generarAutomaticoParaTodos();

        $this->assertSame(0, $resumen['fallidos']);
        $this->assertSame(1, $this->automaticosDeHoy('resp-market'));
        $this->assertSame(1, $this->automaticosDeHoy('resp-dakamu'));
    }

    public function test_el_manual_no_cambia_permisos_de_una_carpeta_que_ya_existe(): void
    {
        $tenant = $this->tenant('resp-market');
        Storage::disk('private')->makeDirectory('backups/resp-market');   // la creó el cron (otro usuario)
        $disco = \Mockery::mock(Storage::disk('private'))->makePartial();
        $disco->shouldNotReceive('makeDirectory');
        Storage::set('private', $disco);

        $backup = app(TenantBackupService::class)->crearManual($tenant);

        $this->assertSame('completado', $backup->estado);
    }

    public function test_un_error_inesperado_deja_el_backup_fallido_y_no_en_proceso(): void
    {
        $tenant = $this->tenant('resp-market');
        $disco = \Mockery::mock(Storage::disk('private'))->makePartial();
        $disco->shouldReceive('directoryExists')->andReturn(false);
        $disco->shouldReceive('makeDirectory')->andThrow(new \RuntimeException('Unable to set visibility for file backups/resp-market.'));
        Storage::set('private', $disco);

        try {
            app(TenantBackupService::class)->crearManual($tenant);
            $this->fail('Se esperaba la falla al crear la carpeta.');
        } catch (\RuntimeException) {
        }

        $backup = TenantBackup::where('tenant_id', 'resp-market')->sole();
        $this->assertSame('fallido', $backup->estado);
        $this->assertStringContainsString('Unable to set visibility', $backup->error_message);
    }
}
