<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\OpcionHotelController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\ProveedorTipo;
use App\Models\AgenciaViajes\ProveedorTipoConfig;
use App\Models\AgenciaViajes\Servicio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Sesión M3 — brief PEGAR-EN-CLAUDE-CODE-matriz-hoteles-m3-adhoc-local.md
// (plan-matriz-hoteles-cotizador.md Ronda 4/P11-P12, Ronda 6/P16).
// Mismo patrón de infraestructura que el resto de la suite: Postgres
// real (sistemafe_test_migrations), transacción por test revertida.
class SesionM3HotelAdhocLocalTest extends TestCase
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
            'type_document' => 'DNI', 'n_document' => '77889911', 'full_name' => 'Cliente Test M3',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-M3-' . random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa M3', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
    }

    // ── §2 — alta de hotel ad-hoc standalone ─────────────────────────────

    public function test_crea_hotel_adhoc_standalone_sin_opcion_mayorista(): void
    {
        $response = app(OpcionHotelController::class)->store(new Request([
            'nombre_hotel' => 'Hotel Ad-hoc Test', 'moneda' => 'PEN',
            'tarifas' => [
                ['tipo_habitacion' => 'doble', 'precio_costo' => 80, 'precio_venta' => 120],
                ['tipo_habitacion' => 'simple', 'precio_costo' => 60, 'precio_venta' => 90, 'tip_afe_igv' => '20', 'destino_tributario' => 'amazonia'],
            ],
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getData(true)['opcion_hotel'];
        $this->assertNull($body['opcion_mayorista_id']);
        $this->assertCount(2, $body['opciones_hotel_tarifas']);

        $tarifaDoble = collect($body['opciones_hotel_tarifas'])->firstWhere('tipo_habitacion', 'doble');
        $this->assertSame('10', $tarifaDoble['tip_afe_igv'], 'sin tributario explícito, cae al default de agencia (10/nacional)');
        $this->assertSame('nacional', $tarifaDoble['destino_tributario']);

        $tarifaSimple = collect($body['opciones_hotel_tarifas'])->firstWhere('tipo_habitacion', 'simple');
        $this->assertSame('20', $tarifaSimple['tip_afe_igv'], 'tributario explícito respetado, no pisado por el default');
        $this->assertSame('amazonia', $tarifaSimple['destino_tributario']);
    }

    // ── §3 — consumir el hotel ad-hoc como AlternativaItem ──────────────

    public function test_crea_item_proveedor_desde_opcion_hotel_tarifa_adhoc(): void
    {
        $alternativa = $this->crearAlternativa();
        $hotel = OpcionHotel::create(['nombre_hotel' => 'Hotel Ad-hoc M3', 'moneda' => 'PEN']);
        $tarifa = OpcionHotelTarifa::create([
            'opcion_hotel_id' => $hotel->id, 'tipo_habitacion' => 'doble', 'precio_costo' => 80, 'precio_venta' => 120,
            'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ]);

        $response = app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'proveedor', 'opcion_hotel_tarifa_id' => $tarifa->id, 'modo_precio' => 'por_persona', 'cantidad' => 1,
        ]), (string) $alternativa->id);

        $this->assertSame(200, $response->getStatusCode());
        $item = $response->getData(true)['alternativa_item'];
        $this->assertSame($tarifa->id, $item['opcion_hotel_tarifa_id']);
        $this->assertNull($item['proveedor_tarifa_id']);
        $this->assertSame('tarifa_fija', $item['modo_precio'], 'siempre por habitación, aunque se haya pedido por_persona');
        $this->assertEquals(80.0, (float) $item['costo_snapshot']);
        $this->assertEquals(120.0, (float) $item['precio_venta_snapshot']);
        $this->assertSame('10', $item['tip_afe_igv']);
    }

    // Regresión — proveedor_tarifa_id y "precio de referencia" (ninguno de
    // los dos) siguen funcionando exactamente igual que antes de M3.
    public function test_crear_item_proveedor_regresion_precio_de_referencia_sin_cambios(): void
    {
        $alternativa = $this->crearAlternativa();

        $response = app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'proveedor', 'modo_precio' => 'tarifa_fija', 'moneda_costo' => 'PEN',
            'precio_venta_snapshot' => 50, 'costo_snapshot' => 30,
        ]), (string) $alternativa->id);

        $this->assertSame(200, $response->getStatusCode());
        $item = $response->getData(true)['alternativa_item'];
        $this->assertNull($item['proveedor_tarifa_id']);
        $this->assertNull($item['opcion_hotel_tarifa_id']);
        $this->assertEquals(50.0, (float) $item['precio_venta_snapshot']);
    }

    // ── §4 — promover matriz completa a Proveedor ───────────────────────

    private function crearServicioAlojamientoHabilitado(): Servicio
    {
        $tipoAlojamiento = ProveedorTipo::where('slug', ProveedorTipo::SLUG_ALOJAMIENTO)->first();
        if (! $tipoAlojamiento) {
            $this->markTestSkipped('Catálogo central proveedor_tipos sin el slug alojamiento-hoteles en este entorno.');
        }

        ProveedorTipoConfig::firstOrCreate(['proveedor_tipo_id' => $tipoAlojamiento->id], ['habilitado' => true]);

        return Servicio::create(['nombre' => 'Hospedaje Test M3 ' . random_int(1000, 9999), 'tipo_proveedor_id' => $tipoAlojamiento->id]);
    }

    public function test_promover_crea_proveedor_y_una_tarifa_por_tipo_habitacion(): void
    {
        $servicio = $this->crearServicioAlojamientoHabilitado();
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Tarapoto Test M3', 'tipo' => 'lugar']);
        $destinoServicio = \App\Models\AgenciaViajes\DestinoServicio::create(['destino_atractivo_id' => $destino->id, 'servicio_id' => $servicio->id]);

        $hotel = OpcionHotel::create(['nombre_hotel' => 'Hotel Promover Test', 'moneda' => 'PEN']);
        OpcionHotelTarifa::create(['opcion_hotel_id' => $hotel->id, 'tipo_habitacion' => 'doble', 'precio_costo' => 80, 'precio_venta' => 120, 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional']);
        OpcionHotelTarifa::create(['opcion_hotel_id' => $hotel->id, 'tipo_habitacion' => 'simple', 'precio_costo' => 60, 'precio_venta' => 90, 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional']);

        $response = app(OpcionHotelController::class)->promover(new Request([
            'destino_servicio_id' => $destinoServicio->id, 'razon_social' => 'Hotel Promover Test SAC',
        ]), (string) $hotel->id);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getData(true);
        $this->assertSame('Hotel Promover Test SAC', $body['proveedor']['razon_social']);
        $this->assertCount(2, $body['proveedor_tarifas']);
        $this->assertSame($body['proveedor']['id'], $hotel->fresh()->proveedor_promovido_id);
    }

    public function test_promover_no_relinkea_items_ya_creados(): void
    {
        $alternativa = $this->crearAlternativa();
        $servicio = $this->crearServicioAlojamientoHabilitado();
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Tarapoto Test M3', 'tipo' => 'lugar']);
        $destinoServicio = \App\Models\AgenciaViajes\DestinoServicio::create(['destino_atractivo_id' => $destino->id, 'servicio_id' => $servicio->id]);

        $hotel = OpcionHotel::create(['nombre_hotel' => 'Hotel No Relink Test', 'moneda' => 'PEN']);
        $tarifa = OpcionHotelTarifa::create(['opcion_hotel_id' => $hotel->id, 'tipo_habitacion' => 'doble', 'precio_costo' => 80, 'precio_venta' => 120, 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional']);

        $respItem = app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'proveedor', 'opcion_hotel_tarifa_id' => $tarifa->id, 'modo_precio' => 'tarifa_fija',
        ]), (string) $alternativa->id);
        $itemId = $respItem->getData(true)['alternativa_item']['id'];

        app(OpcionHotelController::class)->promover(new Request([
            'destino_servicio_id' => $destinoServicio->id, 'razon_social' => 'Hotel No Relink Test SAC',
        ]), (string) $hotel->id);

        $item = \App\Models\AgenciaViajes\AlternativaItem::find($itemId);
        $this->assertSame($tarifa->id, $item->opcion_hotel_tarifa_id, 'sigue apuntando a la tarifa ad-hoc de siempre');
        $this->assertNull($item->proveedor_tarifa_id, 'no se relinkeó al ProveedorTarifa nuevo');
    }

    public function test_promover_dos_veces_el_mismo_hotel_rechaza(): void
    {
        $servicio = $this->crearServicioAlojamientoHabilitado();
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Tarapoto Test M3', 'tipo' => 'lugar']);
        $destinoServicio = \App\Models\AgenciaViajes\DestinoServicio::create(['destino_atractivo_id' => $destino->id, 'servicio_id' => $servicio->id]);

        $hotel = OpcionHotel::create(['nombre_hotel' => 'Hotel Doble Promocion Test', 'moneda' => 'PEN']);
        OpcionHotelTarifa::create(['opcion_hotel_id' => $hotel->id, 'tipo_habitacion' => 'doble', 'precio_costo' => 80, 'precio_venta' => 120]);

        app(OpcionHotelController::class)->promover(new Request([
            'destino_servicio_id' => $destinoServicio->id, 'razon_social' => 'Primera Promocion SAC',
        ]), (string) $hotel->id);

        $response = app(OpcionHotelController::class)->promover(new Request([
            'destino_servicio_id' => $destinoServicio->id, 'razon_social' => 'Segunda Promocion SAC',
        ]), (string) $hotel->id);

        $this->assertSame(422, $response->getStatusCode());
    }
}
