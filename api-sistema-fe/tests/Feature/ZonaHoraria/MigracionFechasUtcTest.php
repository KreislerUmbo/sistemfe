<?php

declare(strict_types=1);

namespace Tests\Feature\ZonaHoraria;

use App\Services\ZonaHoraria\MigracionFechasUtcService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Creditos\CreditosTestCase;

/**
 * F3 de la homogenización de fechas: +5 h solo a lo que quedó en hora Lima, y reversible.
 * Fechas en 2032 para no cruzarse con otras filas de la base de pruebas.
 */
final class MigracionFechasUtcTest extends CreditosTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable(MigracionFechasUtcService::TABLA_REGISTRO)) {
            Schema::create(MigracionFechasUtcService::TABLA_REGISTRO, function (Blueprint $t) {
                $t->id();
                $t->string('tabla');
                $t->json('columnas');
                $t->json('ids');
                $t->unsignedInteger('filas');
                $t->timestamp('created_at')->nullable();
            });
        }
    }

    private function valor(string $tabla, int $id, string $col = 'created_at'): string
    {
        return substr((string) DB::table($tabla)->where('id', $id)->value($col), 0, 19);
    }

    private function auditoria(string $createdAt): int
    {
        return DB::table('role_audit_logs')->insertGetId(['target_type' => 'role', 'target_id' => 1, 'accion' => 'test', 'created_at' => $createdAt, 'updated_at' => $createdAt]);
    }

    public function test_corrige_solo_lo_guardado_en_lima_y_se_puede_revertir(): void
    {
        // Petición a las 20:00 de Perú (01:00 UTC del día siguiente) con un modelo Lima:
        $sede = DB::table('branches')->insertGetId(['name' => 'Sede F3', 'created_at' => '2032-01-10 20:00:00', 'updated_at' => '2032-01-10 20:00:00']);
        $contagiada = $this->auditoria('2032-01-10 20:00:01');
        $enUtc = $this->auditoria('2032-01-11 01:00:02');
        // Ambigua: huellas Lima a X y a X + 5 h.
        DB::table('branches')->insert(['name' => 'Sede A', 'created_at' => '2032-02-02 05:00:00']);
        DB::table('branches')->insert(['name' => 'Sede B', 'created_at' => '2032-02-02 10:00:00']);
        $ambigua = $this->auditoria('2032-02-02 10:00:01');

        $servicio = app(MigracionFechasUtcService::class);
        $servicio->aplicar(DB::connection());

        $this->assertSame('2032-01-11 01:00:00', $this->valor('branches', $sede));
        $this->assertSame('2032-01-11 01:00:00', $this->valor('branches', $sede, 'updated_at'));
        $this->assertSame('2032-01-11 01:00:01', $this->valor('role_audit_logs', $contagiada));
        $this->assertSame('2032-01-10 20:00:01', $this->valor('role_audit_logs', $contagiada, 'updated_at'));  // updated_at de tablas UTC no se toca
        $this->assertSame('2032-01-11 01:00:02', $this->valor('role_audit_logs', $enUtc));
        $this->assertSame('2032-02-02 10:00:01', $this->valor('role_audit_logs', $ambigua));

        $servicio->revertir(DB::connection());

        $this->assertSame('2032-01-10 20:00:00', $this->valor('branches', $sede));
        $this->assertSame('2032-01-10 20:00:01', $this->valor('role_audit_logs', $contagiada));
        $this->assertSame('2032-01-11 01:00:02', $this->valor('role_audit_logs', $enUtc));
    }

    public function test_en_una_base_ya_migrada_la_simulacion_no_propone_nada(): void
    {
        DB::table('branches')->insert(['name' => 'Sede ya migrada', 'created_at' => '2032-04-04 20:00:00']);

        // setUp() creó ajustes_zona_horaria: es lo que deja la migración al correr.
        $this->assertSame([], app(MigracionFechasUtcService::class)->plan(DB::connection()));
    }

    public function test_en_ventas_corrige_sunat_sent_at_pero_nunca_la_fecha_de_emision(): void
    {
        $venta = \App\Models\Sale\Sale::factory()->create(['type_payment' => 1, 'date' => '2032-03-03']);
        DB::table('sales')->where('id', $venta->id)->update([
            'created_at' => '2032-03-03 21:00:00', 'updated_at' => '2032-03-03 21:05:00', 'sunat_sent_at' => '2032-03-03 21:05:00',
        ]);

        app(MigracionFechasUtcService::class)->aplicar(DB::connection());

        $this->assertSame('2032-03-04 02:00:00', $this->valor('sales', $venta->id));
        $this->assertSame('2032-03-04 02:05:00', $this->valor('sales', $venta->id, 'sunat_sent_at'));
        $this->assertSame('2032-03-03', substr((string) DB::table('sales')->where('id', $venta->id)->value('date'), 0, 10));
    }
}
