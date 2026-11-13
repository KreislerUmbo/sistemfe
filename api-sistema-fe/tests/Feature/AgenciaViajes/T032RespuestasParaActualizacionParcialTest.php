<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// T-032 (R5): el cotizador ya no vuelve a pedir toda la cotización después de editar el precio,
// agregar o quitar un ítem; la pantalla actualiza solo lo que cambió. Estos tests fijan lo que
// cada respuesta debe traer para poder hacerlo: la alternativa con su total vigente y, en el
// alta, el ítem con las relaciones que lee el lienzo.
class T032RespuestasParaActualizacionParcialTest extends TestCase
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

    private function alternativaConDosItems(): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '33445577', 'full_name' => 'Cliente Test T032',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0320', 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
        $crear = fn (string $nombre, float $precio) => AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 1,
            'descripcion_manual' => $nombre, 'modo_precio' => 'tarifa_fija', 'cantidad' => 1,
            'moneda_costo' => 'PEN', 'costo_snapshot' => 10, 'precio_venta_snapshot' => $precio, 'precio_convertido' => $precio,
        ]);

        return [$alternativa, $crear('Uno', 100), $crear('Dos', 50)];
    }

    public function test_editar_precio_devuelve_la_alternativa_con_el_total_nuevo(): void
    {
        [$alternativa, $uno] = $this->alternativaConDosItems();

        $res = app(AlternativaItemController::class)->update(new Request(['precio_convertido' => 80]), (string) $uno->id);

        $datos = $res->getData(true);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame($alternativa->id, $datos['alternativa']['id']);
        $this->assertEquals(130, $datos['alternativa']['total']); // 80 + 50
        $this->assertEquals(80, $datos['alternativa_item']['precio_convertido']);
    }

    public function test_eliminar_item_devuelve_la_alternativa_con_los_items_que_quedan(): void
    {
        [$alternativa, $uno, $dos] = $this->alternativaConDosItems();

        $res = app(AlternativaItemController::class)->destroy((string) $uno->id);

        $datos = $res->getData(true);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertEquals(50, $datos['alternativa']['total']);
        $this->assertSame([$dos->id], array_column($datos['alternativa']['items'], 'id'));
    }

    public function test_agregar_item_devuelve_el_item_completo_y_la_alternativa_con_su_total(): void
    {
        [$alternativa] = $this->alternativaConDosItems();

        $res = app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'manual', 'descripcion_manual' => 'Tres', 'dia_referencial' => 1, 'modo_precio' => 'tarifa_fija',
            'cantidad' => 1, 'moneda_costo' => 'PEN', 'costo_snapshot' => 10, 'precio_venta_snapshot' => 20, 'tip_afe_igv' => '10',
            'destino_tributario' => 'nacional',
        ]), (string) $alternativa->id);

        $datos = $res->getData(true);
        $this->assertSame(200, $res->getStatusCode(), json_encode($datos));
        $this->assertSame('Tres', $datos['alternativa_item']['descripcion_manual']);
        // Las relaciones del lienzo viajan aunque estén vacías: la pantalla no vuelve a pedirlas.
        $this->assertArrayHasKey('tour_origen', $datos['alternativa_item']);
        $this->assertArrayHasKey('proveedor_tarifa', $datos['alternativa_item']);
        $this->assertSame($alternativa->id, $datos['alternativa']['id']);
        $this->assertEquals(AlternativaItem::calcularTotalEfectivo($alternativa->items()->get())['total'], $datos['alternativa']['total']);
    }

    public function test_un_alta_rechazada_no_agrega_nada_a_la_respuesta(): void
    {
        [$alternativa] = $this->alternativaConDosItems();

        $res = app(AlternativaItemController::class)->store(new Request(['origen_tipo' => 'inventado']), (string) $alternativa->id);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertArrayNotHasKey('alternativa', $res->getData(true));
    }
}
