<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Fase 2 del fix Cotización↔Reserva (2026-08-19) — §1.3 del brief:
// reasignarDia()/moverBloque() ya no deben permitir mover dia_referencial
// sobre una alternativa 'aceptada' (ya generó una reserva a partir de un
// snapshot de esos valores — moverlos después desincroniza esa reserva en
// silencio, el mismo síntoma de fondo que motivó todo este fix). Mismo
// patrón de infraestructura que ReservaFechaAutoCompletadaTest: Postgres
// real (sistemafe_test_migrations), transacción por test revertida.
class AlternativaItemBloqueaMoverSiAceptadaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => env('DB_HOST', '127.0.0.1'),
            'database.connections.pgsql.port' => env('DB_PORT', '5432'),
            'database.connections.pgsql.database' => 'sistemafe_test_migrations',
            'database.connections.pgsql.username' => env('DB_USERNAME', 'root'),
            'database.connections.pgsql.password' => env('DB_PASSWORD', ''),
        ]);
        DB::purge('pgsql');
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function crearAlternativaConItemSuelto(string $estado): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '33445566', 'full_name' => 'Cliente Test Guard Mover',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0300', 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId,
            'nombre' => 'Alternativa 1',
            'estado' => $estado,
            'moneda_cotizacion' => 'PEN',
            'tipo_cambio_aplicado' => 1,
            'tipo_cambio_origen' => 'dia',
        ]);

        $itemSuelto = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 1,
            'descripcion_manual' => 'Ítem suelto', 'modo_precio' => 'tarifa_fija', 'cantidad' => 1,
            'moneda_costo' => 'PEN', 'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'precio_convertido' => 15,
        ]);

        $destinoAtractivoId = DB::table('destinos_atractivos')->insertGetId([
            'nombre' => 'Tarapoto', 'tipo' => 'lugar', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $tourOrigenId = DB::table('paquetes_plantilla')->insertGetId([
            'nombre' => 'Tour Test Guard Mover', 'categoria' => 'local',
            'destino_atractivo_id' => $destinoAtractivoId, 'duracion_horas' => 8,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $itemDeBloque = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 1,
            'tour_origen_id' => $tourOrigenId, 'descripcion_manual' => 'Ítem de bloque', 'modo_precio' => 'tarifa_fija',
            'cantidad' => 1, 'moneda_costo' => 'PEN', 'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'precio_convertido' => 15,
        ]);

        return [$alternativa, $itemSuelto, $itemDeBloque, $tourOrigenId];
    }

    public function test_reasignar_dia_rechaza_si_alternativa_ya_aceptada(): void
    {
        [$alternativa, $itemSuelto] = $this->crearAlternativaConItemSuelto('aceptada');

        $response = app(AlternativaItemController::class)->reasignarDia(
            new Request(['dia_referencial' => 5]),
            (string) $itemSuelto->id
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('ya fue aceptada', $response->getData(true)['message']);
        $this->assertSame(1, $itemSuelto->fresh()->dia_referencial, 'no debió moverse');
    }

    public function test_reasignar_dia_sigue_permitido_en_borrador(): void
    {
        [$alternativa, $itemSuelto] = $this->crearAlternativaConItemSuelto('borrador');

        $response = app(AlternativaItemController::class)->reasignarDia(
            new Request(['dia_referencial' => 5]),
            (string) $itemSuelto->id
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(5, $itemSuelto->fresh()->dia_referencial);
    }

    public function test_mover_bloque_rechaza_si_alternativa_ya_aceptada(): void
    {
        [$alternativa, , $itemDeBloque, $tourOrigenId] = $this->crearAlternativaConItemSuelto('aceptada');

        $response = app(AlternativaItemController::class)->moverBloque(
            new Request(['tour_origen_id' => $tourOrigenId, 'dia_referencial' => 7]),
            (string) $alternativa->id
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('ya fue aceptada', $response->getData(true)['message']);
        $this->assertSame(1, $itemDeBloque->fresh()->dia_referencial, 'no debió moverse');
    }

    public function test_mover_bloque_sigue_permitido_en_enviada(): void
    {
        [$alternativa, , $itemDeBloque, $tourOrigenId] = $this->crearAlternativaConItemSuelto('enviada');

        $response = app(AlternativaItemController::class)->moverBloque(
            new Request(['tour_origen_id' => $tourOrigenId, 'dia_referencial' => 7]),
            (string) $alternativa->id
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(7, $itemDeBloque->fresh()->dia_referencial);
    }
}
