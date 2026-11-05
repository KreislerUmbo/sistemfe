<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReporteOperativoController;
use App\Http\Controllers\AgenciaViajes\ReservaFacturacionController;
use App\Http\Controllers\AgenciaViajes\VentaDirectaController;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// T-085 — Venta directa con varias líneas, fecha por línea, hotel por noches,
// una sola moneda por venta y vista previa con afectación. Postgres real,
// transacción por test revertida.
class VentaDirectaLineasTest extends TestCase
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

    private const PAX = [['edad' => 30], ['edad' => 40]];

    private const INICIO = '2026-11-04';

    private function crearCliente(): int
    {
        return DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10000000, 99999999), 'full_name' => 'Cliente Líneas',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // Una tarifa con su proveedor/servicio. $extra pisa columnas de la tarifa
    // (p. ej. tipo_habitacion para un hotel, moneda, destino_tributario).
    private function crearTarifa(array $extra = [], string $servicio = 'Traslado', ?int $destinoAtractivoId = null): int
    {
        $destinoAtractivoId ??= DB::table('destinos_atractivos')->insertGetId([
            'nombre' => 'Moyobamba '.uniqid(), 'tipo' => 'lugar', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $servicioId = DB::table('servicios')->insertGetId(['nombre' => $servicio, 'created_at' => now(), 'updated_at' => now()]);
        $destinoServicioId = DB::table('destino_servicio')->insertGetId([
            'destino_atractivo_id' => $destinoAtractivoId, 'servicio_id' => $servicioId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorId = DB::table('proveedores')->insertGetId([
            'razon_social' => 'Proveedor '.$servicio, 'estado' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorServicioId = DB::table('proveedor_servicios')->insertGetId([
            'proveedor_id' => $proveedorId, 'destino_servicio_id' => $destinoServicioId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('proveedor_tarifas')->insertGetId($extra + [
            'proveedor_servicio_id' => $proveedorServicioId,
            'tipo_tarifa' => 'publica', 'modalidad' => 'compartido', 'moneda' => 'PEN',
            'precio_costo' => 20, 'margen_tipo' => 'fijo', 'margen_valor' => 10,
            'precio_venta_adulto' => 50, 'precio_venta_nino' => 25,
            'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function tarifaHotel(array $extra = []): int
    {
        return $this->crearTarifa($extra + ['tipo_habitacion' => 'doble', 'precio_venta_adulto' => 120], 'Habitación doble');
    }

    private function lineaTarifa(int $tarifaId, int $dia = 1, array $extra = []): array
    {
        return $extra + ['origen_tipo' => 'proveedor', 'dia' => $dia, 'proveedor_tarifa_id' => $tarifaId, 'modo_precio' => 'tarifa_fija', 'cantidad' => 1];
    }

    private function lineaLibre(int $dia = 1, array $extra = []): array
    {
        return $extra + [
            'origen_tipo' => 'manual', 'dia' => $dia, 'descripcion_manual' => 'Cena show',
            'precio_venta_snapshot' => 80, 'costo_snapshot' => 0, 'cantidad' => 1,
        ];
    }

    private function crear(array $lineas, array $extra = []): array
    {
        return app(VentaDirectaController::class)->store(new Request($extra + [
            'cliente_id' => $this->crearCliente(),
            'destino' => 'Moyobamba',
            'fecha_servicio' => self::INICIO,
            'pax' => self::PAX,
            'lineas' => $lineas,
        ]))->getData(true);
    }

    private function vistaPrevia(array $lineas, array $extra = []): array
    {
        return app(VentaDirectaController::class)->vistaPrevia(new Request($extra + [
            'fecha_servicio' => self::INICIO,
            'pax' => self::PAX,
            'lineas' => $lineas,
        ]))->getData(true);
    }

    private function totalCreado(array $body): float
    {
        return (float) AlternativaItem::where('alternativa_id', $body['alternativa']['id'])->get()->sum('total_convertido');
    }

    // CA1 — traslado (día 1) + concepto libre (día 2): una reserva, dos
    // ítems con fechas inicio e inicio + 1; se puede preparar UNA factura.
    public function test_ca1_dos_lineas_en_dias_distintos_son_una_reserva_con_fechas_por_linea(): void
    {
        $body = $this->crear([$this->lineaTarifa($this->crearTarifa()), $this->lineaLibre(2)]);

        $this->assertSame(200, $body['code'], $body['message'] ?? '');
        $reserva = Reserva::findOrFail($body['reserva']['id']);
        $fechas = $reserva->items()->orderBy('id')->get()->map(fn ($i) => $i->fecha->toDateString())->all();
        $this->assertSame(['2026-11-04', '2026-11-05'], $fechas);
        $this->assertSame('2026-11-04', $reserva->fecha_viaje_desde->toDateString());
        $this->assertSame('2026-11-05', $reserva->fecha_viaje_hasta->toDateString());
        $this->assertSame(1, Cotizacion::where('origen', Cotizacion::ORIGEN_VENTA_DIRECTA)->count());
        $this->assertEqualsWithDelta(130.0, $this->totalCreado($body), 0.001);
    }

    // CA2 — hotel de 2 noches desde el día 1: cantidad = noches, total
    // precio × noches, fecha_viaje_hasta = inicio + 2; el reporte operativo
    // muestra la estadía y la salida.
    public function test_ca2_hotel_de_dos_noches(): void
    {
        $body = $this->crear([$this->lineaTarifa($this->tarifaHotel(), 1, ['modo_precio' => 'tarifa_fija', 'cantidad' => 2])]);

        $this->assertSame(200, $body['code'], $body['message'] ?? '');
        $item = AlternativaItem::where('alternativa_id', $body['alternativa']['id'])->firstOrFail();
        $this->assertSame(2, (int) $item->cantidad);
        $this->assertSame('tarifa_fija', $item->modo_precio);
        $this->assertEqualsWithDelta(240.0, $this->totalCreado($body), 0.001);

        $reserva = Reserva::findOrFail($body['reserva']['id']);
        $this->assertSame('2026-11-06', $reserva->fecha_viaje_hasta->toDateString());

        $reporte = app(ReporteOperativoController::class)->index(new Request([
            'fecha_desde' => '2026-11-04', 'fecha_hasta' => '2026-11-07',
        ]))->getData(true);
        $itemReservaId = ReservaItem::where('reserva_id', $reserva->id)->value('id');
        $filas = collect($reporte['filas'])->where('reserva_item_id', $itemReservaId);
        $this->assertSame(['2026-11-04', '2026-11-05', '2026-11-06'], $filas->pluck('fecha')->unique()->sort()->values()->all());
        $this->assertSame('Salida del hotel', $filas->firstWhere('fecha', '2026-11-06')['servicio']);
    }

    // CA3 — un hotel no se cobra por persona.
    public function test_ca3_hotel_por_persona_responde_422(): void
    {
        $body = $this->crear([$this->lineaTarifa($this->tarifaHotel(), 1, ['modo_precio' => 'por_persona'])]);

        $this->assertSame(422, $body['code']);
        $this->assertStringContainsString('por noche', $body['message']);
        $this->assertSame(0, Cotizacion::where('origen', Cotizacion::ORIGEN_VENTA_DIRECTA)->count());
    }

    // CA4 — una sola moneda por venta, sin convertir nada.
    public function test_ca4_tarifa_en_dolares_en_una_venta_en_soles_responde_422_y_no_crea_nada(): void
    {
        $dolares = $this->crearTarifa(['moneda' => 'USD'], 'Tour en dólares');

        $body = $this->crear([$this->lineaLibre(1), $this->lineaTarifa($dolares, 2)]);

        $this->assertSame(422, $body['code']);
        $this->assertSame('La venta es en soles y «Tour en dólares» está en dólares. No se mezclan monedas.', $body['message']);
        $this->assertSame(0, Cotizacion::where('origen', Cotizacion::ORIGEN_VENTA_DIRECTA)->count());
    }

    public function test_ca4_venta_en_dolares_con_tarifa_usd_y_concepto_libre(): void
    {
        $dolares = $this->crearTarifa(['moneda' => 'USD'], 'Tour en dólares');

        $body = $this->crear([$this->lineaTarifa($dolares), $this->lineaLibre(1)], ['moneda' => 'USD']);

        $this->assertSame(200, $body['code'], $body['message'] ?? '');
        $this->assertSame('USD', $body['alternativa']['moneda_cotizacion']);
        $this->assertEquals(1, $body['alternativa']['tipo_cambio_aplicado']);
        $libre = AlternativaItem::where('alternativa_id', $body['alternativa']['id'])->where('origen_tipo', 'manual')->firstOrFail();
        $this->assertSame('USD', $libre->moneda_costo);
        $this->assertEqualsWithDelta(130.0, $this->totalCreado($body), 0.001);
    }

    // CA5 — el payload viejo (una línea en la raíz) sigue funcionando.
    public function test_ca5_payload_viejo_de_una_linea_funciona_igual(): void
    {
        $body = app(VentaDirectaController::class)->store(new Request([
            'cliente_id' => $this->crearCliente(),
            'destino' => 'Moyobamba',
            'fecha_servicio' => self::INICIO,
            'origen_tipo' => 'proveedor',
            'pax' => self::PAX,
            'proveedor_tarifa_id' => $this->crearTarifa(['moneda' => 'USD']),
            'modo_precio' => 'por_persona',
            'cantidad' => 1,
        ]))->getData(true);

        $this->assertSame(200, $body['code'], $body['message'] ?? '');
        $this->assertSame('USD', $body['alternativa']['moneda_cotizacion']); // la de su tarifa, como siempre
        $this->assertEqualsWithDelta(100.0, $this->totalCreado($body), 0.001);
        $this->assertSame('2026-11-04', Reserva::findOrFail($body['reserva']['id'])->items()->first()->fecha->toDateString());
    }

    // CA6 — la vista previa suma lo mismo que lo creado (varias líneas, hotel, USD).
    public function test_ca6_el_total_de_la_vista_previa_coincide_con_lo_creado(): void
    {
        foreach (['PEN', 'USD'] as $moneda) {
            $lineas = [
                $this->lineaTarifa($this->crearTarifa(['moneda' => $moneda]), 1, ['modo_precio' => 'por_persona']),
                $this->lineaTarifa($this->tarifaHotel(['moneda' => $moneda]), 1, ['cantidad' => 3]),
                $this->lineaLibre(2, ['cantidad' => 2]),
            ];

            $previa = $this->vistaPrevia($lineas, ['moneda' => $moneda]);
            $creada = $this->crear($lineas, ['moneda' => $moneda]);

            $this->assertSame(200, $previa['code'], $previa['message'] ?? '');
            $this->assertSame(200, $creada['code'], $creada['message'] ?? '');
            $this->assertEqualsWithDelta($this->totalCreado($creada), $previa['total'], 0.001);
            $this->assertSame('2026-11-07', $previa['fecha_viaje_hasta']);
            $this->assertTrue($previa['lineas'][1]['es_hotel']);
            $this->assertSame(3, $previa['lineas'][1]['noches']);
            $this->assertSame('2026-11-07', $previa['lineas'][1]['fecha_salida']);
            $this->assertSame('2026-11-05', $previa['lineas'][2]['fecha']);
        }
    }

    public function test_ca6_vista_previa_con_una_linea_en_otra_moneda_devuelve_el_motivo(): void
    {
        $previa = $this->vistaPrevia([$this->lineaTarifa($this->crearTarifa(['moneda' => 'USD'], 'Tour USD'))], ['moneda' => 'PEN']);

        $this->assertSame(422, $previa['code']);
        $this->assertArrayNotHasKey('total', $previa);
        $this->assertStringContainsString('No se mezclan monedas', $previa['message']);
    }

    public function test_vista_previa_sin_modo_elegido_ofrece_las_dos_opciones_y_no_da_total(): void
    {
        $previa = $this->vistaPrevia([$this->lineaTarifa($this->crearTarifa(), 1, ['modo_precio' => null])]);

        $this->assertSame(200, $previa['code'], $previa['message'] ?? '');
        $this->assertNull($previa['total']);
        $this->assertNull($previa['lineas'][0]['total']);
        $this->assertEqualsWithDelta(100.0, $previa['lineas'][0]['por_persona']['total'], 0.001);
        $this->assertEqualsWithDelta(50.0, $previa['lineas'][0]['tarifa_fija']['total'], 0.001);
    }

    public function test_vista_previa_no_guarda_nada(): void
    {
        $cotizaciones = Cotizacion::count();
        $this->vistaPrevia([$this->lineaTarifa($this->crearTarifa()), $this->lineaLibre(2)]);

        $this->assertSame($cotizaciones, Cotizacion::count());
    }

    // CA7 — Amazonía + gravado: no se puede facturar junto, con el MISMO
    // motivo que daría facturar esa reserva. Solo Amazonía: se podrá, tras confirmar.
    public function test_ca7_amazonia_mas_gravado_no_es_facturable_con_el_mismo_motivo_que_la_facturacion(): void
    {
        $amazonia = $this->crearTarifa(['tip_afe_igv' => '20', 'destino_tributario' => 'amazonia'], 'Tour Amazonía');
        $lineas = [$this->lineaTarifa($amazonia), $this->lineaTarifa($this->crearTarifa(), 2)];

        $previa = $this->vistaPrevia($lineas);

        $this->assertFalse($previa['facturable']['ok']);
        $this->assertSame('amazonia', $previa['lineas'][0]['destino_tributario']);
        $this->assertSame('20', $previa['lineas'][0]['tip_afe_igv']);

        // La reserva creada con esas mismas líneas, vista por la facturación.
        $this->setUpTenantFacturacion();
        $creada = $this->crear($lineas);
        $this->assertSame(200, $creada['code'], $creada['message'] ?? '');
        $factura = app(ReservaFacturacionController::class)
            ->prepararFactura(new Request([
                'pasajero_ids' => Reserva::findOrFail($creada['reserva']['id'])->pasajeros()->pluck('id')->all(),
                'reserva_item_ids_manual' => ReservaItem::where('reserva_id', $creada['reserva']['id'])->pluck('id')->all(),
            ]), (string) $creada['reserva']['id'])->getData(true);

        $this->assertTrue($factura['bloqueado_tributario'] ?? false, json_encode($factura));
        $this->assertSame($factura['motivo'], $previa['facturable']['motivo']);
    }

    public function test_ca7_solo_amazonia_es_facturable_pero_pide_confirmar(): void
    {
        $amazonia = $this->crearTarifa(['tip_afe_igv' => '20', 'destino_tributario' => 'amazonia'], 'Tour Amazonía');

        $previa = $this->vistaPrevia([$this->lineaTarifa($amazonia)]);

        $this->assertTrue($previa['facturable']['ok']);
        $this->assertNull($previa['facturable']['motivo']);
        $this->assertTrue($previa['facturable']['requiere_confirmar_amazonia']);
    }

    public function test_solo_gravado_es_facturable_y_no_pide_confirmar(): void
    {
        $previa = $this->vistaPrevia([$this->lineaTarifa($this->crearTarifa()), $this->lineaLibre(2)]);

        $this->assertTrue($previa['facturable']['ok']);
        $this->assertFalse($previa['facturable']['requiere_confirmar_amazonia']);
    }

    public function test_destino_atractivo_pone_su_nombre_como_destino_de_la_cotizacion(): void
    {
        $destinoId = DB::table('destinos_atractivos')->insertGetId([
            'nombre' => 'Lamas', 'tipo' => 'lugar', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $body = app(VentaDirectaController::class)->store(new Request([
            'cliente_id' => $this->crearCliente(),
            'destino_atractivo_id' => $destinoId,
            'fecha_servicio' => self::INICIO,
            'pax' => self::PAX,
            'lineas' => [$this->lineaLibre()],
        ]))->getData(true);

        $this->assertSame(200, $body['code'], $body['message'] ?? '');
        $this->assertSame('Lamas', Cotizacion::findOrFail($body['alternativa']['cotizacion_id'])->destino);
    }

    public function test_limites_de_lineas_y_de_dia(): void
    {
        $this->assertSame(422, $this->crear([])['code']);
        $this->assertSame(422, $this->crear(array_fill(0, 21, $this->lineaLibre()))['code']);
        $this->assertSame(422, $this->crear([$this->lineaLibre(15)])['code']);
        $this->assertSame(422, $this->crear([$this->lineaLibre(1, ['cantidad' => 0])])['code']);
    }

    // facturación habilitada para el tenant de la prueba (prepararFactura la exige).
    private function setUpTenantFacturacion(): void
    {
        $tenant = new \App\Models\Tenant;
        $tenant->id = 'test-tenant-'.uniqid();
        $tenant->facturacion_habilitada = true;
        app(\Stancl\Tenancy\Tenancy::class)->tenant = $tenant;
    }
}
