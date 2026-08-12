<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\GuiaTarifa;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Fix guia-como-item-real: hasta esta sesión un guía nunca generaba un
// AlternativaItem real con costo — ni cargado suelto (sin columna
// guia_tarifa_id en alternativa_items) ni al explotar un combo
// (desdePlantilla() solo avisaba en guiasPendientes, sin sumar al total).
// Mismo patrón que PrecioPorPasajeroTest: Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
class GuiaComoItemRealTest extends TestCase
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

    private function crearGuiaTarifa(float $costoDiario = 100, string $tipoMargen = 'porcentaje', float $margenValor = 20): GuiaTarifa
    {
        $destino = DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);
        $guia = Guia::create(['nombre' => 'Guía Test', 'documento' => '12345678', 'telefono' => '999999999', 'activo' => true]);

        return GuiaTarifa::create([
            'guia_id' => $guia->id,
            'destino_id' => $destino->id,
            'modalidad' => 'dia_local',
            'costo_diario' => $costoDiario,
            'tipo_margen' => $tipoMargen,
            'margen_valor' => $margenValor,
            'moneda' => 'PEN',
            'vigente_desde' => now()->toDateString(),
        ]);
    }

    private function crearAlternativa(): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '12345678', 'full_name' => 'Cliente Test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0002', 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId,
            'nombre' => 'Alternativa 1',
            'moneda_cotizacion' => 'PEN',
            'tipo_cambio_aplicado' => 1,
            'tipo_cambio_origen' => 'dia',
        ]);
    }

    public function test_crearItemGuia_suelto_genera_item_real_con_costo(): void
    {
        $alternativa = $this->crearAlternativa();
        $guiaTarifa = $this->crearGuiaTarifa(costoDiario: 100, margenValor: 20);

        $response = app(AlternativaItemController::class)->store(
            new Request(['origen_tipo' => 'guia', 'guia_tarifa_id' => $guiaTarifa->id, 'cantidad' => 2]),
            (string) $alternativa->id
        );

        $this->assertSame(200, $response->getStatusCode());
        $item = $response->getData()->alternativa_item;

        $this->assertSame('guia', $item->origen_tipo);
        $this->assertSame($guiaTarifa->id, $item->guia_tarifa_id);
        $this->assertSame('tarifa_fija', $item->modo_precio);
        $this->assertSame(2, $item->cantidad);
        // costo 100, margen 20% => venta 120, costo_total 100 (sin cargos).
        $this->assertEqualsWithDelta(100.0, (float) $item->costo_snapshot, 0.01);
        $this->assertEqualsWithDelta(120.0, (float) $item->precio_venta_snapshot, 0.01);

        // El total de la alternativa YA incluye el costo del guía (× cantidad=2,
        // tarifa_fija) — antes de este fix esto era estructuralmente imposible.
        $alternativa->refresh();
        $this->assertEqualsWithDelta(240.0, (float) $alternativa->total, 0.01);
    }

    public function test_crearItemGuia_rechaza_guia_tarifa_id_inexistente(): void
    {
        $alternativa = $this->crearAlternativa();

        $response = app(AlternativaItemController::class)->store(
            new Request(['origen_tipo' => 'guia', 'guia_tarifa_id' => 999999]),
            (string) $alternativa->id
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, AlternativaItem::count());
    }

    public function test_desdePlantilla_con_guia_crea_item_real_y_mantiene_aviso_pendiente(): void
    {
        $alternativa = $this->crearAlternativa();
        $guiaTarifa = $this->crearGuiaTarifa(costoDiario: 150, margenValor: 30);

        $tour = PaquetePlantilla::create([
            'categoria' => 'local',
            'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE,
            'nombre' => 'Tour con guía',
            'destino_atractivo_id' => $guiaTarifa->destino_id,
            'duracion_horas' => 8,
        ]);

        PaquetePlantillaItem::create([
            'paquete_plantilla_id' => $tour->id,
            'guia_tarifa_id' => $guiaTarifa->id,
            'orden' => 1,
        ]);

        $response = app(AlternativaItemController::class)->desdePlantilla(
            new Request(['paquete_plantilla_id' => $tour->id, 'dia_referencial' => 1]),
            (string) $alternativa->id
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData();

        // El aviso informativo se mantiene (recordatorio de asignar el guía
        // puntual a nivel reserva) ...
        $this->assertCount(1, $data->guias_pendientes);
        $this->assertSame($tour->id, $data->guias_pendientes[0]->tour_origen_id);

        // ... pero ahora TAMBIÉN se creó un AlternativaItem real con costo.
        $this->assertCount(1, $data->items_agregados);
        $item = $data->items_agregados[0];
        $this->assertSame('guia', $item->origen_tipo);
        $this->assertSame($guiaTarifa->id, $item->guia_tarifa_id);
        $this->assertSame('tarifa_fija', $item->modo_precio);
        $this->assertSame($tour->id, $item->tour_origen_id);
        // costo 150, margen 30% => venta 195.
        $this->assertEqualsWithDelta(150.0, (float) $item->costo_snapshot, 0.01);
        $this->assertEqualsWithDelta(195.0, (float) $item->precio_venta_snapshot, 0.01);

        $alternativa->refresh();
        $this->assertEqualsWithDelta(195.0, (float) $alternativa->total, 0.01);
        $this->assertSame(1, AlternativaItem::where('alternativa_id', $alternativa->id)->count());
    }
}
