<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// T-027 (H12): "Eliminar todo el tour" de un bloque clásico (ítems con el
// mismo tour_origen_id, sin carga_tour) mandaba un DELETE por ítem en
// paralelo; cada uno recalculaba alternativas.total con lo que quedaba en
// ese momento y el último en guardar podía dejar un total viejo. Ahora un
// solo endpoint borra el bloque en una transacción y recalcula una vez.
//
// Contrato: POST alternativas/{id}/items/eliminar-bloque, cuerpo
// { item_ids: [...] } — los ítems que el vendedor ve en el bloque (el lienzo
// muestra un bloque por día/destino, por eso se mandan los ids y no solo el
// tour_origen_id).
class T027EliminarBloqueTourTest extends TestCase
{
    use ConAccesoATodasLasReservas;

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

        $this->verTodasLasReservas();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function alternativa(): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10_000_000, 99_999_999), 'full_name' => 'Cliente T027',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-T027-'.uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa T027', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
    }

    private function tour(): PaquetePlantilla
    {
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);

        return PaquetePlantilla::create([
            'categoria' => 'local', 'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE, 'nombre' => 'Tour T027 '.uniqid(),
            'destino_atractivo_id' => $destino->id, 'duracion_horas' => 8,
        ]);
    }

    private function item(Alternativa $alternativa, float $venta, ?int $tourOrigenId = null, int $dia = 1): AlternativaItem
    {
        return AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'descripcion_manual' => 'Servicio '.$venta,
            'tour_origen_id' => $tourOrigenId, 'dia_referencial' => $dia,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => $venta / 2, 'precio_venta_snapshot' => $venta, 'precio_convertido' => $venta,
        ]);
    }

    private function eliminarBloque(Alternativa $alternativa, array $ids)
    {
        return app(AlternativaItemController::class)->eliminarBloque(
            new Request(['item_ids' => array_map(fn (AlternativaItem $i) => $i->id, $ids)]),
            (string) $alternativa->id
        );
    }

    public function test_ca1_borra_todo_el_bloque_y_el_total_es_lo_que_queda(): void
    {
        $alternativa = $this->alternativa();
        $tour = $this->tour();
        $bloque = [$this->item($alternativa, 40, $tour->id), $this->item($alternativa, 60, $tour->id), $this->item($alternativa, 100, $tour->id)];
        $suelto = $this->item($alternativa, 25);
        $alternativa->update(['total' => 225]);

        $respuesta = $this->eliminarBloque($alternativa, $bloque);

        $this->assertSame(200, $respuesta->getStatusCode(), json_encode($respuesta->getData(true)));
        $this->assertSame(0, AlternativaItem::whereIn('id', collect($bloque)->pluck('id'))->count());
        $this->assertNotNull($suelto->fresh());
        $this->assertEqualsWithDelta(25.0, (float) $alternativa->fresh()->total, 0.001);
    }

    public function test_ca2_si_un_item_tiene_reserva_no_borra_ninguno(): void
    {
        $alternativa = $this->alternativa();
        $tour = $this->tour();
        $bloque = [$this->item($alternativa, 40, $tour->id), $this->item($alternativa, 60, $tour->id)];
        $reserva = Reserva::create(['alternativa_id' => $alternativa->id, 'estado' => 'activa']);
        ReservaItem::create(['reserva_id' => $reserva->id, 'alternativa_item_id' => $bloque[1]->id]);

        $respuesta = $this->eliminarBloque($alternativa, $bloque);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertSame(2, AlternativaItem::whereIn('id', collect($bloque)->pluck('id'))->count());
    }

    public function test_ca3_alternativa_aceptada_rechaza(): void
    {
        $alternativa = $this->alternativa();
        $tour = $this->tour();
        $bloque = [$this->item($alternativa, 40, $tour->id)];
        $alternativa->update(['estado' => 'aceptada']);

        $respuesta = $this->eliminarBloque($alternativa, $bloque);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertNotNull($bloque[0]->fresh());
    }

    public function test_solo_borra_los_ids_mandados_aunque_el_tour_este_en_otro_dia(): void
    {
        // El mismo tour cargado en el día 1 y en el día 3: el bloque del día 1
        // no se lleva el del día 3.
        $alternativa = $this->alternativa();
        $tour = $this->tour();
        $dia1 = [$this->item($alternativa, 40, $tour->id, 1)];
        $dia3 = $this->item($alternativa, 50, $tour->id, 3);

        $this->assertSame(200, $this->eliminarBloque($alternativa, $dia1)->getStatusCode());
        $this->assertNotNull($dia3->fresh());
        $this->assertEqualsWithDelta(50.0, (float) $alternativa->fresh()->total, 0.001);
    }

    public function test_rechaza_ids_que_no_son_un_bloque_clasico_de_esa_alternativa(): void
    {
        $alternativa = $this->alternativa();
        $otra = $this->alternativa();
        $tour = $this->tour();
        $otroTour = $this->tour();
        $propio = $this->item($alternativa, 40, $tour->id);
        $casos = [
            'de otra alternativa' => [$propio, $this->item($otra, 10, $tour->id)],
            'sin tour' => [$propio, $this->item($alternativa, 10)],
            'de dos tours distintos' => [$propio, $this->item($alternativa, 10, $otroTour->id)],
        ];
        $lineaTour = $this->item($alternativa, 10, $tour->id);
        $lineaTour->update(['carga_tour' => (string) Str::uuid()]);
        $casos['línea de tour del modo nuevo (va por eliminarCarga)'] = [$propio, $lineaTour];

        foreach ($casos as $caso => $ids) {
            $respuesta = $this->eliminarBloque($alternativa, $ids);
            $this->assertContains($respuesta->getStatusCode(), [404, 422], "Debió rechazar: {$caso}");
            $this->assertNotNull($propio->fresh(), "No debió borrar nada: {$caso}");
        }

        $this->assertSame(422, app(AlternativaItemController::class)->eliminarBloque(new Request(['item_ids' => []]), (string) $alternativa->id)->getStatusCode());
    }
}
