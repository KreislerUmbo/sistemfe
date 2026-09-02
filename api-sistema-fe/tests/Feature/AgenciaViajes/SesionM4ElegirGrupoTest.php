<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaController;
use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\AlternativaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Sesión M4 — brief PEGAR-EN-CLAUDE-CODE-matriz-hoteles-m4-frontend.md
// (backend: crear con grupo_opcion_id + elegir-grupo). Mismo patrón de
// infraestructura que el resto de la suite: Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
class SesionM4ElegirGrupoTest extends TestCase
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

    private function crearAlternativa(): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '99001122', 'full_name' => 'Cliente Test M4',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-M4-' . random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa M4', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
    }

    private function crearProveedorTarifaHotel(float $precioVenta, float $costo): int
    {
        $destinoAtractivoId = DB::table('destinos_atractivos')->insertGetId([
            'nombre' => 'Tarapoto M4 Test ' . random_int(1000, 9999), 'tipo' => 'lugar', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $servicioId = DB::table('servicios')->insertGetId(['nombre' => 'Hospedaje M4 Test', 'created_at' => now(), 'updated_at' => now()]);
        $destinoServicioId = DB::table('destino_servicio')->insertGetId([
            'destino_atractivo_id' => $destinoAtractivoId, 'servicio_id' => $servicioId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorId = DB::table('proveedores')->insertGetId([
            'razon_social' => 'Hotel Test M4 SAC ' . random_int(1000, 9999), 'estado' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorServicioId = DB::table('proveedor_servicios')->insertGetId([
            'proveedor_id' => $proveedorId, 'destino_servicio_id' => $destinoServicioId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('proveedor_tarifas')->insertGetId([
            'proveedor_servicio_id' => $proveedorServicioId, 'tipo_tarifa' => 'publica', 'modalidad' => 'privado', 'moneda' => 'PEN',
            'precio_costo' => $costo, 'margen_tipo' => 'fijo', 'margen_valor' => $precioVenta - $costo, 'precio_venta_adulto' => $precioVenta,
            'tipo_habitacion' => 'doble', 'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── crearItemProveedor()/crearItemMayorista() aceptan grupo_opcion_id ──

    public function test_crear_item_proveedor_con_grupo_opcion_id(): void
    {
        $alternativa = $this->crearAlternativa();
        $tarifaId = $this->crearProveedorTarifaHotel(150, 100);
        $grupo = (string) Str::uuid();

        $response = app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'proveedor', 'proveedor_tarifa_id' => $tarifaId, 'modo_precio' => 'tarifa_fija', 'grupo_opcion_id' => $grupo,
        ]), (string) $alternativa->id);

        $this->assertSame(200, $response->getStatusCode());
        $item = $response->getData(true)['alternativa_item'];
        $this->assertSame($grupo, $item['grupo_opcion_id']);
        $this->assertFalse((bool) AlternativaItem::find($item['id'])->opcion_elegida, 'un item nuevo de grupo nace sin elegir');
    }

    // Regresión — sin grupo_opcion_id, se comporta exactamente igual.
    public function test_crear_item_proveedor_regresion_sin_grupo(): void
    {
        $alternativa = $this->crearAlternativa();
        $tarifaId = $this->crearProveedorTarifaHotel(150, 100);

        $response = app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'proveedor', 'proveedor_tarifa_id' => $tarifaId, 'modo_precio' => 'tarifa_fija',
        ]), (string) $alternativa->id);

        $item = $response->getData(true)['alternativa_item'];
        $this->assertNull($item['grupo_opcion_id']);
    }

    // ── elegirOpcionGrupo() ──────────────────────────────────────────────

    public function test_elegir_grupo_marca_esta_fila_y_desmarca_las_demas(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $itemA = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'descripcion_manual' => 'Hotel A',
            'grupo_opcion_id' => $grupo, 'opcion_elegida' => false,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN', 'costo_snapshot' => 100, 'precio_venta_snapshot' => 150, 'precio_convertido' => 150,
        ]);
        $itemB = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'descripcion_manual' => 'Hotel B',
            'grupo_opcion_id' => $grupo, 'opcion_elegida' => true,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN', 'costo_snapshot' => 120, 'precio_venta_snapshot' => 180, 'precio_convertido' => 180,
        ]);

        $response = app(AlternativaItemController::class)->elegirOpcionGrupo((string) $itemA->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($itemA->fresh()->opcion_elegida);
        $this->assertFalse($itemB->fresh()->opcion_elegida);
    }

    public function test_elegir_grupo_rechaza_item_sin_grupo(): void
    {
        $alternativa = $this->crearAlternativa();
        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'descripcion_manual' => 'Suelto',
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN', 'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'precio_convertido' => 15,
        ]);

        $response = app(AlternativaItemController::class)->elegirOpcionGrupo((string) $item->id);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_elegir_grupo_rechaza_si_alternativa_ya_aceptada(): void
    {
        $alternativa = $this->crearAlternativa();
        $alternativa->update(['estado' => 'aceptada']);
        $grupo = (string) Str::uuid();
        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'descripcion_manual' => 'Hotel A',
            'grupo_opcion_id' => $grupo, 'opcion_elegida' => false,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN', 'costo_snapshot' => 100, 'precio_venta_snapshot' => 150, 'precio_convertido' => 150,
        ]);

        $response = app(AlternativaItemController::class)->elegirOpcionGrupo((string) $item->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($item->fresh()->opcion_elegida);
    }

    public function test_elegir_grupo_reaplica_el_descuento_global_vigente(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $itemA = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'descripcion_manual' => 'Hotel A',
            'grupo_opcion_id' => $grupo, 'opcion_elegida' => false,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN', 'costo_snapshot' => 100, 'precio_venta_snapshot' => 200, 'precio_convertido' => 200,
        ]);
        AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'descripcion_manual' => 'Hotel B',
            'grupo_opcion_id' => $grupo, 'opcion_elegida' => false,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN', 'costo_snapshot' => 120, 'precio_venta_snapshot' => 250, 'precio_convertido' => 250,
        ]);

        // Descuento global ya seteado ANTES de resolver el grupo.
        app(AlternativaController::class)->update(new Request(['descuento_global_pct' => 10]), (string) $alternativa->id);
        $this->assertEquals(0, $itemA->fresh()->descuento_pct, 'grupo abierto — sin descuento todavía');

        app(AlternativaItemController::class)->elegirOpcionGrupo((string) $itemA->id);

        $this->assertEquals(10, $itemA->fresh()->descuento_pct, 'al resolver, la elegida entra al reparto vigente sin que el vendedor tenga que re-tocar el campo');
        $this->assertEquals(180.0, (float) $itemA->fresh()->precio_convertido); // 200 * 0.9
    }

    // Regresión cruzada 12f-2 — encontrada en verificación en vivo de M4
    // (no es un bug de la matriz de hoteles en sí): eliminarCascada()
    // nunca borraba alternativa_destinos, así que CUALQUIER alternativa
    // con un destino asociado (con o sin grupos de hotel) tiraba 500
    // (FK alternativa_destinos_alternativa_id_foreign) al intentar
    // eliminarla.
    public function test_eliminar_alternativa_con_destino_no_rompe_por_fk(): void
    {
        $alternativa = $this->crearAlternativa();
        AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Tarapoto']);

        AlternativaController::eliminarCascada($alternativa->fresh());

        $this->assertDatabaseMissing('alternativas', ['id' => $alternativa->id]);
        $this->assertDatabaseMissing('alternativa_destinos', ['alternativa_id' => $alternativa->id]);
    }
}
