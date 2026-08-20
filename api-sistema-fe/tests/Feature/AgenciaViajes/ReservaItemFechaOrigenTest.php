<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaItemController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ReservaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Fase 1 del fix Cotización↔Reserva (2026-08-18) — §3.5 del brief:
// ReservaItemController::update() debe marcar fecha_origen='manual' en
// cualquier request que traiga 'fecha' explícita (incluso null), y NUNCA
// tocar fecha_origen si el request no incluye 'fecha'. Mismo patrón de
// infraestructura que ReservaFechaAutoCompletadaTest: Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
class ReservaItemFechaOrigenTest extends TestCase
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

    private function crearReservaItemAuto(): ReservaItem
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '99887766', 'full_name' => 'Cliente Test Fecha Origen',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0102', 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId,
            'nombre' => 'Alternativa 1',
            'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN',
            'tipo_cambio_aplicado' => 1,
            'tipo_cambio_origen' => 'dia',
        ]);

        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id,
            'origen_tipo' => 'manual',
            'dia_referencial' => 1,
            'descripcion_manual' => 'Traslado suelto',
            'modo_precio' => 'tarifa_fija',
            'cantidad' => 1,
            'moneda_costo' => 'PEN',
            'costo_snapshot' => 10,
            'precio_venta_snapshot' => 15,
            'precio_convertido' => 15,
        ]);

        app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        return ReservaItem::where('alternativa_item_id', $item->id)->first();
    }

    public function test_editar_fecha_marca_fecha_origen_manual(): void
    {
        $reservaItem = $this->crearReservaItemAuto();
        $this->assertSame(ReservaItem::FECHA_ORIGEN_AUTO, $reservaItem->fecha_origen);

        app(ReservaItemController::class)->update(
            new Request(['fecha' => '2026-09-10']),
            (string) $reservaItem->id
        );

        $this->assertSame(ReservaItem::FECHA_ORIGEN_MANUAL, $reservaItem->fresh()->fecha_origen);
        $this->assertSame('2026-09-10', $reservaItem->fresh()->fecha->toDateString());
    }

    public function test_vaciar_fecha_a_null_tambien_marca_fecha_origen_manual(): void
    {
        $reservaItem = $this->crearReservaItemAuto();

        app(ReservaItemController::class)->update(
            new Request(['fecha' => null]),
            (string) $reservaItem->id
        );

        $this->assertSame(ReservaItem::FECHA_ORIGEN_MANUAL, $reservaItem->fresh()->fecha_origen);
        $this->assertNull($reservaItem->fresh()->fecha);
    }

    public function test_editar_otro_campo_sin_fecha_no_toca_fecha_origen(): void
    {
        $reservaItem = $this->crearReservaItemAuto();

        app(ReservaItemController::class)->update(
            new Request(['hora' => '08:30']),
            (string) $reservaItem->id
        );

        $fresh = $reservaItem->fresh();
        $this->assertSame(ReservaItem::FECHA_ORIGEN_AUTO, $fresh->fecha_origen);
        $this->assertSame('08:30', substr($fresh->hora, 0, 5));
    }
}
