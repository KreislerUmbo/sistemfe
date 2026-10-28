<?php

namespace Tests\Feature\AgenciaViajes;

use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use App\Http\Controllers\AgenciaViajes\ConfiguracionCodigosController;
use App\Http\Controllers\AgenciaViajes\CotizacionController;
use App\Http\Controllers\AgenciaViajes\VentaDirectaController;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Services\AgenciaViajes\CodigoGeneradorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Venta Directa, bloque 1 (09-oct-2026) — las 4 fallas del análisis:
// concepto libre siempre 422, ítem de reserva sin fecha, choque de código de
// reserva con una cotización del mismo correlativo, y cotizaciones de soporte
// mezcladas en el listado del Cotizador. Mismo patrón de infraestructura que
// VentaDirectaReservaFechaTest: Postgres real (sistemafe_test_migrations),
// transacción por test revertida.
class VentaDirectaBloque1Test extends TestCase
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

        // T-025: el cotizador pregunta a CotizacionPolicy; estos tests prueban otras reglas.
        $this->verTodasLasReservas();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function crearCliente(): int
    {
        return DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '11991200', 'full_name' => 'Cliente Test Bloque 1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function crearProveedorTarifa(): int
    {
        $destinoAtractivoId = DB::table('destinos_atractivos')->insertGetId([
            'nombre' => 'Moyobamba', 'tipo' => 'lugar',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $servicioId = DB::table('servicios')->insertGetId([
            'nombre' => 'Tour Test Bloque 1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $destinoServicioId = DB::table('destino_servicio')->insertGetId([
            'destino_atractivo_id' => $destinoAtractivoId, 'servicio_id' => $servicioId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorId = DB::table('proveedores')->insertGetId([
            'razon_social' => 'Operador Test Bloque 1 SAC', 'estado' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorServicioId = DB::table('proveedor_servicios')->insertGetId([
            'proveedor_id' => $proveedorId, 'destino_servicio_id' => $destinoServicioId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('proveedor_tarifas')->insertGetId([
            'proveedor_servicio_id' => $proveedorServicioId,
            'tipo_tarifa' => 'publica', 'modalidad' => 'compartido', 'moneda' => 'PEN',
            'precio_costo' => 40, 'margen_tipo' => 'fijo', 'margen_valor' => 20,
            'precio_venta_adulto' => 60,
            'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ventaDirectaProveedor(int $clienteId, int $tarifaId, ?string $fecha = '2026-10-20'): array
    {
        return app(VentaDirectaController::class)->store(new Request([
            'cliente_id' => $clienteId,
            'destino' => 'Moyobamba',
            'fecha_servicio' => $fecha,
            'origen_tipo' => 'proveedor',
            'pax' => [['edad' => 30], ['edad' => 35]],
            'proveedor_tarifa_id' => $tarifaId,
            'modo_precio' => 'por_persona',
            'cantidad' => 1,
        ]))->getData(true);
    }

    public function test_concepto_libre_crea_la_reserva_con_costo_y_cantidad_reales(): void
    {
        $body = app(VentaDirectaController::class)->store(new Request([
            'cliente_id' => $this->crearCliente(),
            'destino' => 'Tarapoto',
            'fecha_servicio' => '2026-10-20',
            'origen_tipo' => 'manual',
            'pax' => [['edad' => 30]],
            'descripcion_manual' => 'Traslado aeropuerto-hotel',
            'costo_snapshot' => 25,
            'precio_venta_snapshot' => 40,
            'cantidad' => 2,
            'moneda_costo' => 'PEN',
        ]))->getData(true);

        $this->assertSame(200, $body['code'], $body['message'] ?? '');

        $reservaItem = ReservaItem::with('alternativaItem')->where('reserva_id', $body['reserva']['id'])->sole();
        $this->assertSame('manual', $reservaItem->alternativaItem->origen_tipo);
        $this->assertEquals(25, (float) $reservaItem->alternativaItem->costo_snapshot);
        $this->assertSame(2, (int) $reservaItem->alternativaItem->cantidad);
    }

    public function test_concepto_libre_sin_costo_responde_422_claro(): void
    {
        $body = app(VentaDirectaController::class)->store(new Request([
            'cliente_id' => $this->crearCliente(),
            'destino' => 'Tarapoto',
            'origen_tipo' => 'manual',
            'pax' => [['edad' => 30]],
            'descripcion_manual' => 'Traslado',
            'precio_venta_snapshot' => 40,
            'moneda_costo' => 'PEN',
        ]))->getData(true);

        $this->assertSame(422, $body['code']);
        $this->assertStringContainsString('costo', mb_strtolower($body['message']));
    }

    public function test_el_item_de_la_reserva_hereda_la_fecha_del_servicio(): void
    {
        $body = $this->ventaDirectaProveedor($this->crearCliente(), $this->crearProveedorTarifa());

        $this->assertSame(200, $body['code'], $body['message'] ?? '');
        $reservaItem = ReservaItem::where('reserva_id', $body['reserva']['id'])->sole();
        $this->assertSame('2026-10-20', $reservaItem->fecha?->toDateString());
    }

    public function test_sin_fecha_de_servicio_el_item_queda_sin_fecha(): void
    {
        $body = $this->ventaDirectaProveedor($this->crearCliente(), $this->crearProveedorTarifa(), null);

        $this->assertSame(200, $body['code'], $body['message'] ?? '');
        $this->assertNull(ReservaItem::where('reserva_id', $body['reserva']['id'])->sole()->fecha);
    }

    public function test_la_reserva_de_venta_directa_no_choca_con_la_de_una_cotizacion_del_mismo_correlativo(): void
    {
        $clienteId = $this->crearCliente();
        $generador = app(CodigoGeneradorService::class);

        // Cotización y venta directa con el mismo correlativo y periodo: antes
        // del fix las dos reservas salían con el mismo código derivado.
        DB::table('codigo_secuencias')->whereIn('tipo', ['cotizacion', 'venta_directa'])->update(['ultimo_correlativo' => 41]);

        $cotizacion = Cotizacion::create([
            'cliente_id' => $clienteId,
            'codigo_prefijo' => DB::table('configuracion_codigos')->where('tipo', 'cotizacion')->value('prefijo'),
            'codigo' => $generador->generar('cotizacion'),
            'destino' => 'Moyobamba',
        ]);
        $codigoReservaCotizacion = $generador->generarParaReserva($cotizacion);
        DB::table('reserva')->insert([
            'codigo' => $codigoReservaCotizacion, 'estado' => 'activa',
            'alternativa_id' => DB::table('alternativas')->insertGetId([
                'cotizacion_id' => $cotizacion->id, 'nombre' => 'A', 'estado' => 'aceptada',
                'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'agencia', 'total' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $body = $this->ventaDirectaProveedor($clienteId, $this->crearProveedorTarifa());

        $this->assertSame(200, $body['code'], $body['message'] ?? '');
        $reserva = Reserva::with('alternativa.cotizacion')->findOrFail($body['reserva']['id']);
        $this->assertSame($reserva->alternativa->cotizacion->codigo, $reserva->codigo);
        $this->assertNotSame($codigoReservaCotizacion, $reserva->codigo);
        $this->assertSame(Cotizacion::ORIGEN_VENTA_DIRECTA, $reserva->alternativa->cotizacion->origen);
    }

    public function test_el_listado_del_cotizador_no_muestra_ventas_directas(): void
    {
        $clienteId = $this->crearCliente();
        $body = $this->ventaDirectaProveedor($clienteId, $this->crearProveedorTarifa());
        $this->assertSame(200, $body['code'], $body['message'] ?? '');
        $idVentaDirecta = $body['alternativa']['cotizacion_id'];

        $comercial = Cotizacion::create([
            'cliente_id' => $clienteId, 'codigo_prefijo' => 'C', 'codigo' => 'C-TEST-BLOQUE1', 'destino' => 'Moyobamba',
        ]);

        $listado = app(CotizacionController::class)->index(new Request(['search' => 'Cliente Test Bloque 1', 'per_page' => 100]))->getData(true);
        $ids = array_column($listado['cotizaciones'], 'id');

        $this->assertContains($comercial->id, $ids);
        $this->assertNotContains($idVentaDirecta, $ids);
    }

    public function test_no_se_puede_repetir_un_prefijo_entre_tipos(): void
    {
        $body = app(ConfiguracionCodigosController::class)->update(new Request([
            'prefijo' => mb_strtolower((string) DB::table('configuracion_codigos')->where('tipo', 'venta_directa')->value('prefijo')),
            'separador' => '-',
            'incluye_periodo' => false,
            'longitud_correlativo' => 4,
            'reinicio_correlativo' => 'nunca',
            'activo' => true,
        ]), 'reserva')->getData(true);

        $this->assertSame(422, $body['code']);
        $this->assertStringContainsString('venta_directa', $body['message']);
    }
}
