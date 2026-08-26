<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReporteOperativoController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\ReservaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Mejoras post-11d — filtros nuevos del reporte operativo (destino/servicio/tour/hotel)
// y el catálogo de opciones que los alimenta. Mismo patrón de infraestructura que
// ReporteOperativoTest: Postgres real (sistemafe_test_migrations), transacción por test
// revertida. Fixture propia (no reutiliza crearReservaConItems() de ReporteOperativoTest):
// necesita 2 destinos/servicios DISTINTOS para que los filtros discriminen de verdad.
class ReporteOperativoFiltrosTest extends TestCase
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

    /**
     * itemA: destino "Playa Norte" / servicio "Transporte", proveedor normal (no hotel).
     * itemB: destino "Museo Central" / servicio "Entrada", proveedor CON tipo_habitacion
     *        (funciona como el "hotel" del fixture).
     * itemC: sin destino/servicio/hotel — solo tour_origen_id (paquete_plantilla).
     * itemD: control, sin ninguna dimensión — no debe matchear ningún filtro.
     */
    private function crearReservaConDimensiones(): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '55667788', 'full_name' => 'Cliente Test Filtros',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-05' . uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-15', 'fecha_viaje_hasta' => '2026-09-15',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId,
            'nombre' => 'Alternativa filtros',
            'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN',
            'tipo_cambio_aplicado' => 1,
            'tipo_cambio_origen' => 'dia',
        ]);

        CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 30]);

        $destinoAId = DB::table('destinos_atractivos')->insertGetId([
            'nombre' => 'Playa Norte', 'tipo' => 'lugar', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $destinoBId = DB::table('destinos_atractivos')->insertGetId([
            'nombre' => 'Museo Central', 'tipo' => 'lugar', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $servicioAId = DB::table('servicios')->insertGetId([
            'nombre' => 'Transporte', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $servicioBId = DB::table('servicios')->insertGetId([
            'nombre' => 'Entrada', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $destinoServicioAId = DB::table('destino_servicio')->insertGetId([
            'destino_atractivo_id' => $destinoAId, 'servicio_id' => $servicioAId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $destinoServicioBId = DB::table('destino_servicio')->insertGetId([
            'destino_atractivo_id' => $destinoBId, 'servicio_id' => $servicioBId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $proveedorNormalId = DB::table('proveedores')->insertGetId([
            'razon_social' => 'Transportes Norte SAC', 'estado' => true, 'es_referencial' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorHotelId = DB::table('proveedores')->insertGetId([
            'razon_social' => 'Hotel Museo SAC', 'estado' => true, 'es_referencial' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorServicioAId = DB::table('proveedor_servicios')->insertGetId([
            'proveedor_id' => $proveedorNormalId, 'destino_servicio_id' => $destinoServicioAId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorServicioBId = DB::table('proveedor_servicios')->insertGetId([
            'proveedor_id' => $proveedorHotelId, 'destino_servicio_id' => $destinoServicioBId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $tarifaBase = [
            'tipo_tarifa' => 'publica', 'modalidad' => 'compartido', 'moneda' => 'PEN',
            'precio_costo' => 20, 'margen_tipo' => 'fijo', 'margen_valor' => 10, 'precio_venta_adulto' => 30,
            'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
            'created_at' => now(), 'updated_at' => now(),
        ];
        $tarifaAId = DB::table('proveedor_tarifas')->insertGetId(
            $tarifaBase + ['proveedor_servicio_id' => $proveedorServicioAId]
        );
        $tarifaBId = DB::table('proveedor_tarifas')->insertGetId(
            $tarifaBase + ['proveedor_servicio_id' => $proveedorServicioBId, 'tipo_habitacion' => 'doble']
        );

        $tour = PaquetePlantilla::create([
            'categoria' => 'local', 'nombre' => 'Tour Filtros', 'codigo' => 'TF-' . uniqid(),
            'destino_atractivo_id' => $destinoAId, 'duracion_horas' => 4,
        ]);

        $itemsBase = [
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 20, 'precio_venta_snapshot' => 30, 'precio_convertido' => 30,
            'dia_referencial' => 1,
        ];

        $itemA = AlternativaItem::create($itemsBase + [
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor', 'proveedor_tarifa_id' => $tarifaAId,
        ]);
        $itemB = AlternativaItem::create($itemsBase + [
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor', 'proveedor_tarifa_id' => $tarifaBId,
        ]);
        $itemC = AlternativaItem::create($itemsBase + [
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual',
            'descripcion_manual' => 'Ítem del tour', 'tour_origen_id' => $tour->id,
        ]);
        $itemD = AlternativaItem::create($itemsBase + [
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual',
            'descripcion_manual' => 'Ítem sin ninguna dimensión',
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        return [
            'reserva' => $reserva,
            'riA' => ReservaItem::where('alternativa_item_id', $itemA->id)->firstOrFail(),
            'riB' => ReservaItem::where('alternativa_item_id', $itemB->id)->firstOrFail(),
            'riC' => ReservaItem::where('alternativa_item_id', $itemC->id)->firstOrFail(),
            'riD' => ReservaItem::where('alternativa_item_id', $itemD->id)->firstOrFail(),
            'destinoAId' => $destinoAId, 'destinoBId' => $destinoBId,
            'servicioAId' => $servicioAId, 'servicioBId' => $servicioBId,
            'tourId' => $tour->id, 'proveedorHotelId' => $proveedorHotelId,
        ];
    }

    private function idsDevueltos(array $body): array
    {
        return array_unique(array_column($body['filas'], 'reserva_item_id'));
    }

    public function test_filtro_destino_solo_devuelve_items_de_ese_destino(): void
    {
        $f = $this->crearReservaConDimensiones();

        $body = app(ReporteOperativoController::class)->index(new Request([
            'fecha_desde' => '2026-09-15', 'fecha_hasta' => '2026-09-15',
            'destino_atractivo_id' => $f['destinoAId'],
        ]))->getData(true);

        $this->assertSame([$f['riA']->id], $this->idsDevueltos($body));
    }

    public function test_filtro_servicio_solo_devuelve_items_de_ese_servicio(): void
    {
        $f = $this->crearReservaConDimensiones();

        $body = app(ReporteOperativoController::class)->index(new Request([
            'fecha_desde' => '2026-09-15', 'fecha_hasta' => '2026-09-15',
            'servicio_id' => $f['servicioBId'],
        ]))->getData(true);

        $this->assertSame([$f['riB']->id], $this->idsDevueltos($body));
    }

    public function test_filtro_tour_solo_devuelve_items_de_ese_tour(): void
    {
        $f = $this->crearReservaConDimensiones();

        $body = app(ReporteOperativoController::class)->index(new Request([
            'fecha_desde' => '2026-09-15', 'fecha_hasta' => '2026-09-15',
            'tour_id' => $f['tourId'],
        ]))->getData(true);

        $this->assertSame([$f['riC']->id], $this->idsDevueltos($body));
    }

    public function test_filtro_hotel_solo_devuelve_items_de_ese_proveedor_con_tipo_habitacion(): void
    {
        $f = $this->crearReservaConDimensiones();

        $body = app(ReporteOperativoController::class)->index(new Request([
            'fecha_desde' => '2026-09-15', 'fecha_hasta' => '2026-09-15',
            'hotel_proveedor_id' => $f['proveedorHotelId'],
        ]))->getData(true);

        $this->assertSame([$f['riB']->id], $this->idsDevueltos($body));
    }

    public function test_filtros_combinados_se_aplican_con_and(): void
    {
        $f = $this->crearReservaConDimensiones();

        // destino A + servicio B nunca coinciden en el mismo ítem del fixture -> vacío.
        $body = app(ReporteOperativoController::class)->index(new Request([
            'fecha_desde' => '2026-09-15', 'fecha_hasta' => '2026-09-15',
            'destino_atractivo_id' => $f['destinoAId'], 'servicio_id' => $f['servicioBId'],
        ]))->getData(true);

        $this->assertSame([], $this->idsDevueltos($body));
    }

    public function test_filtros_disponibles_devuelve_las_4_dimensiones_sin_auto_restringirse(): void
    {
        $f = $this->crearReservaConDimensiones();

        $body = app(ReporteOperativoController::class)->filtrosDisponibles(new Request([
            'fecha_desde' => '2026-09-15', 'fecha_hasta' => '2026-09-15',
        ]))->getData(true);

        $this->assertContains($f['destinoAId'], array_column($body['destinos'], 'id'));
        $this->assertContains($f['destinoBId'], array_column($body['destinos'], 'id'));
        $this->assertContains($f['servicioAId'], array_column($body['servicios'], 'id'));
        $this->assertContains($f['servicioBId'], array_column($body['servicios'], 'id'));
        $this->assertContains($f['tourId'], array_column($body['tours'], 'id'));
        $this->assertContains($f['proveedorHotelId'], array_column($body['hoteles'], 'id'));
    }
}
