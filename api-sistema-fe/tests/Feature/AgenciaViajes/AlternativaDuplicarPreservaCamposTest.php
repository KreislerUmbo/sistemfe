<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\GuiaTarifa;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\Proveedor;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Auditoría de mantenibilidad (05-sep-2026, ver memoria de proyecto
// project_agencia_viajes_auditoria_mantenibilidad_2026-09-05) — hallazgo
// más grave: AlternativaController::duplicar() perdía 6 campos reales al
// clonar (grupo_opcion_id, opcion_elegida, opcion_hotel_tarifa_id,
// guia_tarifa_id, tip_afe_igv, destino_tributario). Verificando el código
// real (07-sep-2026) encontré un séptimo hueco, más grave todavía: los
// OpcionHotel "ad-hoc" (Local/Nacional, "Hotel sin catálogo" — nacen SIN
// opcion_mayorista_id) nunca se clonaban EN ABSOLUTO, ni siquiera parcial.
// Mismo patrón de infraestructura que el resto de la suite: Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
class AlternativaDuplicarPreservaCamposTest extends TestCase
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
            'type_document' => 'DNI', 'n_document' => '77556633', 'full_name' => 'Cliente Test Duplicar',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-DUP-' . random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa Duplicar Test', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
    }

    public function test_duplicar_preserva_grupo_hotel_adhoc_local_completo(): void
    {
        $alternativa = $this->crearAlternativa();

        $hotelA = OpcionHotel::create(['nombre_hotel' => 'Hotel Ad-hoc A', 'moneda' => 'PEN']);
        $tarifaA = OpcionHotelTarifa::create([
            'opcion_hotel_id' => $hotelA->id, 'tipo_habitacion' => 'doble',
            'precio_costo' => 80, 'precio_venta' => 120,
            'tip_afe_igv' => '20', 'destino_tributario' => 'amazonia',
        ]);
        $hotelB = OpcionHotel::create(['nombre_hotel' => 'Hotel Ad-hoc B', 'moneda' => 'PEN']);
        $tarifaB = OpcionHotelTarifa::create([
            'opcion_hotel_id' => $hotelB->id, 'tipo_habitacion' => 'doble',
            'precio_costo' => 90, 'precio_venta' => 140,
            'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ]);

        $grupoOpcionId = (string) \Illuminate\Support\Str::uuid();
        $itemA = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor',
            'opcion_hotel_tarifa_id' => $tarifaA->id, 'grupo_opcion_id' => $grupoOpcionId, 'opcion_elegida' => true,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 80, 'precio_venta_snapshot' => 120, 'precio_convertido' => 120,
            'tip_afe_igv' => '20', 'destino_tributario' => 'amazonia',
        ]);
        $itemB = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor',
            'opcion_hotel_tarifa_id' => $tarifaB->id, 'grupo_opcion_id' => $grupoOpcionId, 'opcion_elegida' => false,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 90, 'precio_venta_snapshot' => 140, 'precio_convertido' => 140,
            'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ]);

        $response = app(AlternativaController::class)->duplicar((string) $alternativa->id);
        $this->assertSame(200, $response->getStatusCode());
        $copiaId = $response->getData(true)['alternativa']['id'];

        $itemsCopia = AlternativaItem::where('alternativa_id', $copiaId)->orderBy('id')->get();
        $this->assertCount(2, $itemsCopia, 'los 2 ítems del grupo se clonaron');

        // grupo_opcion_id: mismo valor entre los 2 ítems de la COPIA (el
        // agrupamiento sigue intacto) — no hace falta que sea distinto del
        // original, agruparPorGrupoOpcion() siempre opera sobre los ítems
        // de UNA alternativa a la vez, nunca mezcla dos alternativas.
        $this->assertNotNull($itemsCopia[0]->grupo_opcion_id);
        $this->assertSame($itemsCopia[0]->grupo_opcion_id, $itemsCopia[1]->grupo_opcion_id);

        // opcion_elegida: exactamente uno de los dos, coherente con el original.
        $elegidos = $itemsCopia->where('opcion_elegida', true);
        $this->assertCount(1, $elegidos);

        // opcion_hotel_tarifa_id: remapeado a una tarifa NUEVA (clonada),
        // nunca la misma fila que el original — comparten alternativa
        // sería un bug real (2 alternativas referenciando la misma fila).
        $tarifaIdsCopia = $itemsCopia->pluck('opcion_hotel_tarifa_id')->all();
        $this->assertNotContains($tarifaA->id, $tarifaIdsCopia);
        $this->assertNotContains($tarifaB->id, $tarifaIdsCopia);
        $this->assertCount(2, array_unique($tarifaIdsCopia), 'cada ítem apunta a su propia tarifa clonada, no a la misma');

        // El hotel ad-hoc clonado existe de verdad y no está pisado por el original.
        $tarifaElegidaCopia = OpcionHotelTarifa::find($elegidos->first()->opcion_hotel_tarifa_id);
        $this->assertNotNull($tarifaElegidaCopia);
        $this->assertNotSame($hotelA->id, $tarifaElegidaCopia->opcion_hotel_id);
        $hotelClonado = OpcionHotel::find($tarifaElegidaCopia->opcion_hotel_id);
        $this->assertSame('Hotel Ad-hoc A', $hotelClonado->nombre_hotel);
        $this->assertNull($hotelClonado->opcion_mayorista_id, 'sigue siendo ad-hoc, no quedó enganchado a ningún mayorista');

        // tip_afe_igv/destino_tributario preservados por ítem.
        $itemAcopia = $itemsCopia->firstWhere('opcion_elegida', true);
        $this->assertSame('20', $itemAcopia->tip_afe_igv);
        $this->assertSame('amazonia', $itemAcopia->destino_tributario);

        // El original queda intacto (nadie le tocó su tarifa/hotel).
        $this->assertSame($tarifaA->id, $itemA->fresh()->opcion_hotel_tarifa_id);
        $this->assertSame($tarifaB->id, $itemB->fresh()->opcion_hotel_tarifa_id);
    }

    public function test_duplicar_preserva_guia_tarifa_id(): void
    {
        $alternativa = $this->crearAlternativa();
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Tarapoto Test Dup', 'tipo' => 'lugar']);
        $guia = Guia::create(['nombre' => 'Guía Test Duplicar', 'documento' => '77556644', 'telefono' => '900111222', 'activo' => true]);
        $guiaTarifa = GuiaTarifa::create([
            'guia_id' => $guia->id, 'destino_id' => $destino->id, 'modalidad' => 'dia_local',
            'costo_diario' => 100, 'tipo_margen' => 'porcentaje', 'margen_valor' => 20,
            'moneda' => 'PEN', 'vigente_desde' => now()->toDateString(),
        ]);

        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'guia', 'guia_tarifa_id' => $guiaTarifa->id,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 100, 'precio_venta_snapshot' => 120, 'precio_convertido' => 120,
        ]);

        $response = app(AlternativaController::class)->duplicar((string) $alternativa->id);
        $this->assertSame(200, $response->getStatusCode());
        $copiaId = $response->getData(true)['alternativa']['id'];

        $itemCopia = AlternativaItem::where('alternativa_id', $copiaId)->first();
        $this->assertNotNull($itemCopia);
        // guia_tarifa_id: copiado DIRECTO — GuiaTarifa es catálogo
        // compartido, no pertenece a ninguna alternativa (mismo criterio
        // que proveedor_tarifa_id/contenido_tour_id), nunca hace falta
        // remapear ni clonar.
        $this->assertSame($guiaTarifa->id, $itemCopia->guia_tarifa_id);
    }

    public function test_duplicar_preserva_grupo_hotel_de_mayorista_con_remap_correcto(): void
    {
        $alternativa = $this->crearAlternativa();

        $proveedor = Proveedor::create(['razon_social' => 'Mayorista Test Duplicar SAC']);
        $opcion = OpcionMayorista::create([
            'alternativa_id' => $alternativa->id, 'proveedor_id' => $proveedor->id, 'moneda' => 'USD', 'estado' => 'elegida',
        ]);
        $hotel = OpcionHotel::create([
            'opcion_mayorista_id' => $opcion->id, 'nombre_hotel' => 'Hotel Mayorista Dup', 'moneda' => 'USD',
            'fotos' => [['path' => 'opciones-hotel/foto-test.jpg', 'tipo_foto' => 'fachada']],
        ]);
        $tarifa = OpcionHotelTarifa::create([
            'opcion_hotel_id' => $hotel->id, 'tipo_habitacion' => 'doble',
            'precio_costo' => 200, 'precio_venta' => 300,
            'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ]);

        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'mayorista',
            'opcion_mayorista_id' => $opcion->id, 'opcion_hotel_tarifa_id' => $tarifa->id,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 2, 'moneda_costo' => 'USD',
            'costo_snapshot' => 200, 'precio_venta_snapshot' => 300, 'precio_convertido' => 300,
        ]);

        $response = app(AlternativaController::class)->duplicar((string) $alternativa->id);
        $this->assertSame(200, $response->getStatusCode());
        $copiaId = $response->getData(true)['alternativa']['id'];

        $itemCopia = AlternativaItem::where('alternativa_id', $copiaId)->first();
        $this->assertNotNull($itemCopia->opcion_mayorista_id);
        $this->assertNotSame($opcion->id, $itemCopia->opcion_mayorista_id, 'apunta a la OpcionMayorista clonada, no a la original');
        $this->assertNotNull($itemCopia->opcion_hotel_tarifa_id);
        $this->assertNotSame($tarifa->id, $itemCopia->opcion_hotel_tarifa_id, 'apunta a la tarifa clonada, no a la original');

        $tarifaClonada = OpcionHotelTarifa::find($itemCopia->opcion_hotel_tarifa_id);
        $this->assertSame('10', $tarifaClonada->tip_afe_igv);
        $this->assertSame('nacional', $tarifaClonada->destino_tributario);

        $hotelClonado = OpcionHotel::find($tarifaClonada->opcion_hotel_id);
        $this->assertSame($itemCopia->opcion_mayorista_id, $hotelClonado->opcion_mayorista_id);
        $this->assertSame([['path' => 'opciones-hotel/foto-test.jpg', 'tipo_foto' => 'fachada']], $hotelClonado->fotos, 'fotos del hotel también se clonan');
    }
}
