<?php

declare(strict_types=1);

namespace Tests\Feature\ZonaHoraria;

use App\Services\ZonaHoraria\DiagnosticoZonasService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Creditos\CreditosTestCase;

/**
 * F1 de la homogenización de fechas: clasificación de created_at por vecinos de zona conocida.
 * Fechas en 2031 para no cruzarse con otras filas de la base de pruebas.
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

    public function test_clasifica_cada_fila_por_sus_vecinos_de_la_misma_peticion(): void
    {
        // Una petición a las 19:30:00 de Perú (= 00:30:00 UTC del día siguiente):
        DB::table('permissions')->insert(['name' => 'zona.test', 'guard_name' => 'api', 'created_at' => '2031-03-11 00:30:00']); // UTC
        DB::table('branches')->insert(['name' => 'Sede zona test', 'created_at' => '2031-03-10 19:30:01']);                       // Lima
        $enLima = $this->auditoria('2031-03-10 19:30:02');   // tabla "UTC" contagiada por la sede
        $enUtc = $this->auditoria('2031-03-11 00:30:01');
        $aislada = $this->auditoria('2031-06-01 12:00:00');

        $t = $this->porTabla();

        $this->assertSame('LIMA', $t['role_audit_logs']['filas'][$enLima]);
        $this->assertSame('UTC', $t['role_audit_logs']['filas'][$enUtc]);
        $this->assertSame('SIN_VECINO', $t['role_audit_logs']['filas'][$aislada]);
        $this->assertSame([], $t['branches']['contradicciones']);
    }

    public function test_marca_contradiccion_si_una_tabla_utc_tiene_una_fila_en_lima(): void
    {
        DB::table('branches')->insert(['name' => 'Sede ancla', 'created_at' => '2031-04-02 21:00:00']);   // Lima = 02:00 UTC del 03
        $id = DB::table('permissions')->insertGetId(['name' => 'zona.lima', 'guard_name' => 'api', 'created_at' => '2031-04-02 21:00:01']);

        $t = $this->porTabla();

        $this->assertContains($id, $t['permissions']['contradicciones']);
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
