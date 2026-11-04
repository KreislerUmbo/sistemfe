<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReporteOperativoController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\ReservaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// T-055 (H54): una estadía de N noches es un solo ítem con la fecha de entrada
// (alternativa_item.cantidad = noches); el reporte la reparte en sus noches y agrega
// la fila "Salida del hotel". Mismo patrón de infraestructura que ReporteOperativoFiltrosTest.
class ReporteOperativoHotelNochesTest extends TestCase
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

    /** Hotel de 3 noches con entrada el 10/11/2026. */
    private function crearEstadia(?string $horaCheckout = '11:00', int $noches = 3): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '66778899', 'full_name' => 'Cliente Hotel Noches',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-11' . uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-11-10', 'fecha_viaje_hasta' => '2026-11-13',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa hotel', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
        CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 30]);

        $destinoId = DB::table('destinos_atractivos')->insertGetId([
            'nombre' => 'Tarapoto', 'tipo' => 'lugar', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $servicioId = DB::table('servicios')->insertGetId([
            'nombre' => 'Alojamiento', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $destinoServicioId = DB::table('destino_servicio')->insertGetId([
            'destino_atractivo_id' => $destinoId, 'servicio_id' => $servicioId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorId = DB::table('proveedores')->insertGetId([
            'razon_social' => 'Hotel Noches SAC', 'estado' => true, 'es_referencial' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('proveedor_alojamiento_detalle')->insert([
            'proveedor_id' => $proveedorId, 'hora_checkout' => $horaCheckout,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorServicioId = DB::table('proveedor_servicios')->insertGetId([
            'proveedor_id' => $proveedorId, 'destino_servicio_id' => $destinoServicioId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tarifaId = DB::table('proveedor_tarifas')->insertGetId([
            'proveedor_servicio_id' => $proveedorServicioId, 'tipo_habitacion' => 'doble',
            'tipo_tarifa' => 'publica', 'modalidad' => 'compartido', 'moneda' => 'PEN',
            'precio_costo' => 20, 'margen_tipo' => 'fijo', 'margen_valor' => 10, 'precio_venta_adulto' => 30,
            'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor', 'proveedor_tarifa_id' => $tarifaId,
            'modo_precio' => 'tarifa_fija', 'cantidad' => $noches, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 20, 'precio_venta_snapshot' => 30, 'precio_convertido' => 30, 'dia_referencial' => 1,
        ]);

        app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());
        $reservaItem = ReservaItem::where('alternativa_item_id', $item->id)->firstOrFail();
        $reservaItem->update(['fecha' => '2026-11-10']);

        return ['ri' => $reservaItem, 'proveedorId' => $proveedorId];
    }

    private function reporte(array $query): array
    {
        return app(ReporteOperativoController::class)->index(new Request($query))->getData(true);
    }

    /** fecha => [servicio, hotel, hora] de las filas del ítem. */
    private function porFecha(array $body, int $itemId): array
    {
        $r = [];
        foreach ($body['filas'] as $f) {
            if ($f['reserva_item_id'] === $itemId) {
                $r[$f['fecha']] = [$f['servicio'], $f['hotel'], $f['hora']];
            }
        }
        ksort($r);

        return $r;
    }

    public function test_ca1_hotel_de_3_noches_aparece_el_10_11_y_12_y_sale_el_13(): void
    {
        $f = $this->crearEstadia();

        $body = $this->reporte(['fecha_desde' => '2026-11-10', 'fecha_hasta' => '2026-11-14']);
        $dias = $this->porFecha($body, $f['ri']->id);

        $this->assertSame(['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13'], array_keys($dias));
        foreach (['2026-11-10', '2026-11-11', '2026-11-12'] as $d) {
            $this->assertSame('Hotel Noches SAC', $dias[$d][1], "Hotel vacío el $d");
        }
        $this->assertSame('Salida del hotel', $dias['2026-11-13'][0]);
        // Las filas derivadas no cuentan como registros ni como check-in.
        $this->assertSame(1, $body['total_items']);
    }

    public function test_ca2_estadia_que_entro_antes_del_rango_aparece_dentro_de_el(): void
    {
        $f = $this->crearEstadia();

        $body = $this->reporte(['fecha_desde' => '2026-11-11', 'fecha_hasta' => '2026-11-12']);
        $dias = $this->porFecha($body, $f['ri']->id);

        $this->assertSame(['2026-11-11', '2026-11-12'], array_keys($dias));
        $this->assertSame(0, $body['total_items']);
    }

    public function test_filtro_por_hotel_incluye_las_noches_dentro_del_rango(): void
    {
        $f = $this->crearEstadia();

        $body = $this->reporte([
            'fecha_desde' => '2026-11-12', 'fecha_hasta' => '2026-11-13',
            'hotel_proveedor_id' => $f['proveedorId'],
        ]);

        $this->assertSame(['2026-11-12', '2026-11-13'], array_keys($this->porFecha($body, $f['ri']->id)));
    }

    public function test_ca3_la_fila_de_salida_muestra_la_hora_de_checkout(): void
    {
        $f = $this->crearEstadia('11:30:00');

        $dias = $this->porFecha(
            $this->reporte(['fecha_desde' => '2026-11-10', 'fecha_hasta' => '2026-11-13']),
            $f['ri']->id
        );

        $this->assertSame('11:30', $dias['2026-11-13'][2]);
    }

    public function test_salida_sin_checkout_cargado_queda_sin_hora(): void
    {
        $f = $this->crearEstadia(null);

        $dias = $this->porFecha(
            $this->reporte(['fecha_desde' => '2026-11-13', 'fecha_hasta' => '2026-11-13']),
            $f['ri']->id
        );

        $this->assertSame(['Salida del hotel', 'Hotel Noches SAC', null], $dias['2026-11-13']);
    }

    public function test_estadia_que_termino_antes_del_rango_no_aparece(): void
    {
        $f = $this->crearEstadia();

        $body = $this->reporte(['fecha_desde' => '2026-11-14', 'fecha_hasta' => '2026-11-15']);

        $this->assertSame([], $this->porFecha($body, $f['ri']->id));
    }

    public function test_vista_agrupada_del_pdf_y_excel_trae_las_noches_y_la_salida(): void
    {
        $this->crearEstadia('11:00:00');
        $controller = app(ReporteOperativoController::class);
        $body = $this->reporte(['fecha_desde' => '2026-11-10', 'fecha_hasta' => '2026-11-13']);

        $metodo = new \ReflectionMethod($controller, 'armarVistaAgrupada');
        $dias = $metodo->invoke($controller, $body['filas'], Carbon::parse('2026-11-10'), Carbon::parse('2026-11-13'));

        $this->assertSame(['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13'], array_column($dias, 'fecha'));
        $salida = $dias[3]['grupos'][0]['pasajeros'][0]['filas'][0];
        $this->assertSame('Salida del hotel', $salida['servicio']);
        $this->assertSame('11:00', $salida['hora']);
        $this->assertSame('Estadía · noche 2 de 3', $dias[1]['grupos'][0]['pasajeros'][0]['filas'][0]['servicio']);
    }

    public function test_pdf_se_genera_con_las_noches(): void
    {
        $this->crearEstadia();

        $response = app(ReporteOperativoController::class)->pdf(new Request([
            'fecha_desde' => '2026-11-10', 'fecha_hasta' => '2026-11-13',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
