<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ReservaItem;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// fix/reserva-items-fecha-y-campos-por-tipo: crearReservaDesdeAlternativa()
// nunca tocaba fecha/hora de los reserva_items nuevos — quedaban en null
// hasta que alguien las escribía a mano en reservas/detalle.vue. Ahora
// auto-completa fecha = fecha_viaje_desde + (dia_referencial - 1) días,
// solo cuando ambos datos existen (los dos son nullable en el modelo real).
// Mismo patrón que GuiaComoItemRealTest: Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
class ReservaFechaAutoCompletadaTest extends TestCase
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

    private function crearAlternativa(?string $fechaViajeDesde): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '87654321', 'full_name' => 'Cliente Test Fecha',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0099', 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => $fechaViajeDesde,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId,
            'nombre' => 'Alternativa 1',
            'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN',
            'tipo_cambio_aplicado' => 1,
            'tipo_cambio_origen' => 'dia',
        ]);
    }

    private function crearItem(Alternativa $alternativa, string $origenTipo, ?int $diaReferencial): AlternativaItem
    {
        return AlternativaItem::create([
            'alternativa_id' => $alternativa->id,
            'origen_tipo' => $origenTipo,
            'dia_referencial' => $diaReferencial,
            'descripcion_manual' => $origenTipo === 'manual' ? 'Traslado suelto' : null,
            'modo_precio' => 'tarifa_fija',
            'cantidad' => 1,
            'moneda_costo' => 'PEN',
            'costo_snapshot' => 10,
            'precio_venta_snapshot' => 15,
            'precio_convertido' => 15,
        ]);
    }

    public function test_fecha_se_calcula_desde_fecha_viaje_desde_y_dia_referencial(): void
    {
        $alternativa = $this->crearAlternativa('2026-09-01');
        $itemDia1 = $this->crearItem($alternativa, 'manual', 1);
        $itemDia3 = $this->crearItem($alternativa, 'manual', 3);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        $reservaItemDia1 = ReservaItem::where('alternativa_item_id', $itemDia1->id)->first();
        $reservaItemDia3 = ReservaItem::where('alternativa_item_id', $itemDia3->id)->first();

        $this->assertSame('2026-09-01', $reservaItemDia1->fecha->toDateString());
        $this->assertSame('2026-09-03', $reservaItemDia3->fecha->toDateString());
        $this->assertSame($reserva->id, $reservaItemDia1->reserva_id);
    }

    public function test_fecha_queda_null_si_cotizacion_no_tiene_fecha_viaje_desde(): void
    {
        $alternativa = $this->crearAlternativa(null);
        $item = $this->crearItem($alternativa, 'manual', 1);

        app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        $reservaItem = ReservaItem::where('alternativa_item_id', $item->id)->first();
        $this->assertNull($reservaItem->fecha);
    }

    public function test_fecha_queda_null_si_item_no_tiene_dia_referencial(): void
    {
        $alternativa = $this->crearAlternativa('2026-09-01');
        $item = $this->crearItem($alternativa, 'manual', null);

        app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        $reservaItem = ReservaItem::where('alternativa_item_id', $item->id)->first();
        $this->assertNull($reservaItem->fecha);
    }

    public function test_hora_nunca_se_autocompleta(): void
    {
        $alternativa = $this->crearAlternativa('2026-09-01');
        $item = $this->crearItem($alternativa, 'manual', 1);

        app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        $reservaItem = ReservaItem::where('alternativa_item_id', $item->id)->first();
        $this->assertNull($reservaItem->hora);
    }
}
