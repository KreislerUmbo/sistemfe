<?php

declare(strict_types=1);

namespace Tests\Feature\ZonaHoraria;

use App\Services\ZonaHoraria\DiagnosticoZonasService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Creditos\CreditosTestCase;

/**
 * F1 de la homogenización de fechas: decisión de zona de cada created_at por huellas de modelos
 * "Lima" (created_at de tablas con mutador) en la misma petición. Validado contra los logs de
 * Postgres locales: 878 filas correctas, 0 incorrectas, 172 ambiguas (no se tocan; las 172
 * eran UTC). Fechas en 2031 para no cruzarse con otras filas de la base de pruebas.
 */
final class DiagnosticoZonasTest extends CreditosTestCase
{
    /** @return array<string, array<string, mixed>> */
    private function porTabla(): array
    {
        return collect(app(DiagnosticoZonasService::class)->diagnosticar(DB::connection()))->keyBy('tabla')->all();
    }

    private function auditoria(string $createdAt): int
    {
        return DB::table('role_audit_logs')->insertGetId(['target_type' => 'role', 'target_id' => 1, 'accion' => 'test', 'created_at' => $createdAt]);
    }

    public function test_decide_cada_fila_por_la_huella_del_modelo_lima_de_la_misma_peticion(): void
    {
        // Petición a las 19:30 de Perú (00:30 UTC del día siguiente) que guarda una sede (mutador Lima).
        DB::table('branches')->insert(['name' => 'Sede zona test', 'created_at' => '2031-03-10 19:30:01']);
        $despues = $this->auditoria('2031-03-10 19:30:02');   // escrita después de la sede: contagiada
        $antes = $this->auditoria('2031-03-11 00:30:00');     // escrita antes de la sede: UTC
        $otraPeticion = $this->auditoria('2031-06-01 12:00:00');

        $t = $this->porTabla()['role_audit_logs'];

        $this->assertSame('LIMA', $t['filas'][$despues]);
        $this->assertSame('UTC', $t['filas'][$antes]);
        $this->assertSame('UTC', $t['filas'][$otraPeticion]);   // sin modelo Lima cerca: no hubo contagio
        $this->assertContains($despues, $t['a_corregir']);
        $this->assertNotContains($antes, $t['a_corregir']);
    }

    public function test_una_tabla_lima_sin_huellas_ajenas_queda_en_lima_y_una_insertada_en_utc_es_anomalia(): void
    {
        $sola = DB::table('branches')->insertGetId(['name' => 'Sede sola', 'created_at' => '2031-08-01 10:00:00']);
        DB::table('branches')->insert(['name' => 'Sede ancla', 'created_at' => '2031-04-02 21:00:00']);            // = 02:00 UTC del 03
        $enUtc = DB::table('branches')->insertGetId(['name' => 'Sede en UTC', 'created_at' => '2031-04-03 02:00:01']); // sin mutador

        $t = $this->porTabla()['branches'];

        $this->assertContains($sola, $t['a_corregir']);
        $this->assertContains($enUtc, $t['anomalias']);
        $this->assertNotContains($enUtc, $t['a_corregir']);
    }

    public function test_una_fila_ambigua_no_se_corrige(): void
    {
        // Huella Lima a la vez en X (antes) y en X + 5 h (después): no se puede decidir.
        DB::table('branches')->insert(['name' => 'Sede A', 'created_at' => '2031-05-05 05:00:00']);   // = 10:00 UTC
        DB::table('branches')->insert(['name' => 'Sede B', 'created_at' => '2031-05-05 10:00:00']);   // = 15:00 UTC
        $id = $this->auditoria('2031-05-05 10:00:01');

        $t = $this->porTabla()['role_audit_logs'];

        $this->assertContains($id, $t['ambiguas']);
        $this->assertNotContains($id, $t['a_corregir']);
    }

    public function test_el_comando_no_modifica_nada(): void
    {
        $antes = DB::table('branches')->count();

        $this->artisan('fechas:diagnosticar', ['--tenant' => ['no-existe'], '--central' => true])
            ->expectsOutputToContain('Este comando NO modificó ningún dato.')
            ->assertSuccessful();

        $this->assertSame($antes, DB::table('branches')->count());
    }
}
