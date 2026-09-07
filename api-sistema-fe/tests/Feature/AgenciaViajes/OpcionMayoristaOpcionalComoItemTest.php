<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaController;
use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\OpcionMayoristaOpcional;
use App\Models\AgenciaViajes\Proveedor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Hueco real documentado desde 04-sep-2026 (memoria de proyecto
// project_agencia_viajes_opcionales_mayorista_lienzo_gap), cerrado
// 07-sep-2026: un OpcionMayoristaOpcional (San Blas, Taboga, Colón...)
// solo existía como info de referencia en el PDF — sin forma de que el
// cliente lo elija de verdad y sume al total. Mismo patrón de
// infraestructura que el resto de la suite: Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
class OpcionMayoristaOpcionalComoItemTest extends TestCase
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

    private function crearAlternativaConMayoristaElegida(): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '77009988', 'full_name' => 'Cliente Test Opcional',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-OPL-' . random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Panamá', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa Opcional Test', 'estado' => 'borrador',
            'moneda_cotizacion' => 'USD', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
        DB::table('cotizacion_pasajeros')->insert([
            ['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 30, 'created_at' => now(), 'updated_at' => now()],
            ['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 28, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $proveedor = Proveedor::create(['razon_social' => 'Mayorista Test Opcional SAC']);
        $opcion = OpcionMayorista::create([
            'alternativa_id' => $alternativa->id, 'proveedor_id' => $proveedor->id, 'moneda' => 'USD', 'estado' => 'elegida',
        ]);

        return [$alternativa, $opcion];
    }

    public function test_agregar_opcional_al_lienzo_crea_item_real_que_suma_al_total(): void
    {
        [$alternativa, $opcion] = $this->crearAlternativaConMayoristaElegida();
        $opcional = OpcionMayoristaOpcional::create([
            'opcion_mayorista_id' => $opcion->id, 'nombre' => 'Excursión a las islas de San Blas',
            'precio_por_persona' => 170, 'moneda' => 'USD',
        ]);

        $response = app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'mayorista', 'opcion_mayorista_id' => $opcion->id,
            'opcion_mayorista_opcional_id' => $opcional->id,
        ]), (string) $alternativa->id);

        $this->assertSame(200, $response->getStatusCode());
        $item = $response->getData(true)['alternativa_item'];
        $this->assertSame($opcional->id, $item['opcion_mayorista_opcional_id']);
        $this->assertNull($item['opcion_hotel_tarifa_id']);
        $this->assertEquals(170.0, (float) $item['precio_venta_snapshot']);
        $this->assertEquals(0.0, (float) $item['costo_snapshot'], 'un opcional no tiene costo registrado — 0 explícito, no inventado');

        // El total de la alternativa (2 adultos, cantidad default=1 si no
        // se manda explícito) refleja el opcional sumado — antes de este
        // fix, un opcional NUNCA llegaba a afectar este total.
        $this->assertEquals(170.0, (float) $alternativa->fresh()->total);
    }

    public function test_agregar_opcional_por_persona_multiplica_por_cantidad(): void
    {
        [$alternativa, $opcion] = $this->crearAlternativaConMayoristaElegida();
        $opcional = OpcionMayoristaOpcional::create([
            'opcion_mayorista_id' => $opcion->id, 'nombre' => 'La isla de Taboga - Full Day',
            'precio_por_persona' => 96, 'moneda' => 'USD',
        ]);

        $response = app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'mayorista', 'opcion_mayorista_id' => $opcion->id,
            'opcion_mayorista_opcional_id' => $opcional->id, 'cantidad' => 2,
        ]), (string) $alternativa->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertEquals(192.0, (float) $alternativa->fresh()->total, '2 pax × USD 96');
    }

    public function test_no_se_puede_agregar_opcional_de_otra_opcion_mayorista(): void
    {
        [$alternativa, $opcion] = $this->crearAlternativaConMayoristaElegida();
        $proveedorOtro = Proveedor::create(['razon_social' => 'Otro Mayorista SAC']);
        $otraOpcion = OpcionMayorista::create([
            'alternativa_id' => $alternativa->id, 'proveedor_id' => $proveedorOtro->id, 'moneda' => 'USD', 'estado' => 'candidata',
        ]);
        $opcionalDeOtra = OpcionMayoristaOpcional::create([
            'opcion_mayorista_id' => $otraOpcion->id, 'nombre' => 'Opcional de otra opción', 'precio_por_persona' => 50, 'moneda' => 'USD',
        ]);

        $response = app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'mayorista', 'opcion_mayorista_id' => $opcion->id,
            'opcion_mayorista_opcional_id' => $opcionalDeOtra->id,
        ]), (string) $alternativa->id);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_nombre_del_item_resuelve_al_nombre_del_opcional_no_al_generico(): void
    {
        [$alternativa, $opcion] = $this->crearAlternativaConMayoristaElegida();
        $opcional = OpcionMayoristaOpcional::create([
            'opcion_mayorista_id' => $opcion->id, 'nombre' => 'Compras en la Zona Libre de Colón',
            'precio_por_persona' => 85, 'moneda' => 'USD',
        ]);

        $response = app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'mayorista', 'opcion_mayorista_id' => $opcion->id,
            'opcion_mayorista_opcional_id' => $opcional->id,
        ]), (string) $alternativa->id);
        $itemId = $response->getData(true)['alternativa_item']['id'];

        $item = AlternativaItem::find($itemId);
        $this->assertSame('Compras en la Zona Libre de Colón', ReservaController::resolverNombreItem($item));
        $this->assertSame('Compras en la Zona Libre de Colón', ReservaController::resolverNombreItem($item, null, 'cliente'));
    }

    public function test_pdf_excluye_de_tours_opcionales_el_que_ya_se_agrego_al_lienzo(): void
    {
        [$alternativa, $opcion] = $this->crearAlternativaConMayoristaElegida();
        $opcionalAgregado = OpcionMayoristaOpcional::create([
            'opcion_mayorista_id' => $opcion->id, 'nombre' => 'Ya agregado', 'precio_por_persona' => 60, 'moneda' => 'USD',
        ]);
        $opcionalPendiente = OpcionMayoristaOpcional::create([
            'opcion_mayorista_id' => $opcion->id, 'nombre' => 'Todavía opcional', 'precio_por_persona' => 40, 'moneda' => 'USD',
        ]);

        app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'mayorista', 'opcion_mayorista_id' => $opcion->id,
            'opcion_mayorista_opcional_id' => $opcionalAgregado->id,
        ]), (string) $alternativa->id);

        $controller = app(AlternativaController::class);
        $reflMayoristas = new \ReflectionMethod($controller, 'mayoristasReferenciados');
        $reflMayoristas->setAccessible(true);
        $mayoristas = $reflMayoristas->invoke($controller, $alternativa->fresh('items'));

        $reflPendientes = new \ReflectionMethod($controller, 'mayoristasOpcionalesPendientes');
        $reflPendientes->setAccessible(true);
        $pendientes = $reflPendientes->invoke($controller, $alternativa->fresh('items'), $mayoristas);

        $this->assertTrue($pendientes->contains('id', $opcionalPendiente->id));
        $this->assertFalse($pendientes->contains('id', $opcionalAgregado->id), 'ya está cobrado en el total — no debe seguir apareciendo como "puede agregar aparte"');
    }

    public function test_duplicar_remapea_opcion_mayorista_opcional_id_a_la_copia(): void
    {
        [$alternativa, $opcion] = $this->crearAlternativaConMayoristaElegida();
        $opcional = OpcionMayoristaOpcional::create([
            'opcion_mayorista_id' => $opcion->id, 'nombre' => 'Opcional Duplicar Test', 'precio_por_persona' => 75, 'moneda' => 'USD',
        ]);
        $resItem = app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'mayorista', 'opcion_mayorista_id' => $opcion->id,
            'opcion_mayorista_opcional_id' => $opcional->id,
        ]), (string) $alternativa->id);
        $itemOriginalId = $resItem->getData(true)['alternativa_item']['id'];

        $response = app(AlternativaController::class)->duplicar((string) $alternativa->id);
        $this->assertSame(200, $response->getStatusCode());
        $copiaId = $response->getData(true)['alternativa']['id'];

        $itemCopia = AlternativaItem::where('alternativa_id', $copiaId)->first();
        $this->assertNotNull($itemCopia->opcion_mayorista_opcional_id);
        $this->assertNotSame($opcional->id, $itemCopia->opcion_mayorista_opcional_id, 'apunta al opcional clonado, no al original');

        $opcionalClonado = OpcionMayoristaOpcional::find($itemCopia->opcion_mayorista_opcional_id);
        $this->assertSame('Opcional Duplicar Test', $opcionalClonado->nombre);

        // El original queda intacto.
        $this->assertSame($opcional->id, AlternativaItem::find($itemOriginalId)->opcion_mayorista_opcional_id);
    }
}
