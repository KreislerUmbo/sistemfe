<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\VentaDirectaController;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Cotizacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// T-045 — la vista previa de Venta directa: mismo total que la reserva creada,
// no guarda nada y exige reservas.crear. Postgres real, transacción por test.
class VentaDirectaVistaPreviaTest extends TestCase
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

    private function crearCliente(): int
    {
        return DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10000000, 99999999), 'full_name' => 'Cliente Vista Previa',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function crearTarifa(string $moneda = 'PEN', array $extra = []): int
    {
        $destinoAtractivoId = DB::table('destinos_atractivos')->insertGetId([
            'nombre' => 'Moyobamba', 'tipo' => 'lugar', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $servicioId = DB::table('servicios')->insertGetId([
            'nombre' => 'Tour Vista Previa', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $destinoServicioId = DB::table('destino_servicio')->insertGetId([
            'destino_atractivo_id' => $destinoAtractivoId, 'servicio_id' => $servicioId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorId = DB::table('proveedores')->insertGetId([
            'razon_social' => 'Operador Vista Previa SAC', 'estado' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorServicioId = DB::table('proveedor_servicios')->insertGetId([
            'proveedor_id' => $proveedorId, 'destino_servicio_id' => $destinoServicioId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('proveedor_tarifas')->insertGetId($extra + [
            'proveedor_servicio_id' => $proveedorServicioId,
            'tipo_tarifa' => 'publica', 'modalidad' => 'compartido', 'moneda' => $moneda,
            'precio_costo' => 20, 'margen_tipo' => 'fijo', 'margen_valor' => 10,
            'precio_venta_adulto' => 30.55, 'precio_venta_nino' => 15.25,
            'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private const PAX = [['edad' => 30], ['edad' => 40], ['edad' => 8], ['edad' => 1]];

    private function vistaPrevia(array $datos): array
    {
        return app(VentaDirectaController::class)->vistaPrevia(new Request($datos))->getData(true);
    }

    private function crear(int $tarifaId, string $modo, int $cantidad): array
    {
        return app(VentaDirectaController::class)->store(new Request([
            'cliente_id' => $this->crearCliente(),
            'destino' => 'Moyobamba',
            'fecha_servicio' => '2026-10-20',
            'origen_tipo' => 'proveedor',
            'pax' => self::PAX,
            'proveedor_tarifa_id' => $tarifaId,
            'modo_precio' => $modo,
            'cantidad' => $cantidad,
        ]))->getData(true);
    }

    private function totalCreado(array $body): float
    {
        return (float) AlternativaItem::where('alternativa_id', $body['alternativa']['id'])->get()->sum('total_convertido');
    }

    // CA2 — la vista previa coincide con la reserva creada, en PEN y en USD,
    // con pasajeros niño e infante (infante sin precio = 0).
    public function test_el_total_de_la_vista_previa_coincide_con_el_de_la_venta_creada(): void
    {
        foreach (['PEN', 'USD'] as $moneda) {
            $tarifaId = $this->crearTarifa($moneda);
            $previa = $this->vistaPrevia(['pax' => self::PAX, 'proveedor_tarifa_id' => $tarifaId, 'cantidad' => 3]);

            $this->assertSame(200, $previa['code'], $previa['message'] ?? '');
            $this->assertSame($moneda, $previa['moneda']);

            $porPersona = $this->crear($tarifaId, 'por_persona', 1);
            $this->assertSame(200, $porPersona['code'], $porPersona['message'] ?? '');
            $this->assertEqualsWithDelta($this->totalCreado($porPersona), $previa['por_persona']['total'], 0.001);

            $fija = $this->crear($tarifaId, 'tarifa_fija', 3);
            $this->assertSame(200, $fija['code'], $fija['message'] ?? '');
            $this->assertEqualsWithDelta($this->totalCreado($fija), $previa['tarifa_fija']['total'], 0.001);
        }
    }

    // CA4b — desglose: 2 adultos, 1 niño, 1 infante; el infante sin precio vale 0.
    public function test_el_desglose_muestra_el_precio_vacio_como_cero(): void
    {
        $previa = $this->vistaPrevia(['pax' => self::PAX, 'proveedor_tarifa_id' => $this->crearTarifa()]);

        $d = $previa['por_persona']['desglose'];
        $this->assertEquals(['cantidad' => 2, 'precio' => 30.55], $d['adulto']);
        $this->assertEquals(['cantidad' => 1, 'precio' => 15.25], $d['nino']);
        $this->assertEquals(['cantidad' => 1, 'precio' => 0], $d['infante']);
        $this->assertEqualsWithDelta(2 * 30.55 + 15.25, $previa['por_persona']['total'], 0.001);
        $this->assertEqualsWithDelta(30.55, $previa['tarifa_fija']['total'], 0.001); // cantidad 1 por defecto
    }

    // CA2b — no crea nada.
    public function test_la_vista_previa_no_crea_cotizaciones_ni_consume_correlativo(): void
    {
        $tarifaId = $this->crearTarifa();
        $cotizaciones = Cotizacion::count();
        $correlativo = fn () => DB::table('codigo_secuencias')->where('tipo', 'venta_directa')->value('ultimo_correlativo');
        $antes = $correlativo();

        $this->vistaPrevia(['pax' => self::PAX, 'proveedor_tarifa_id' => $tarifaId]);

        $this->assertSame($cotizaciones, Cotizacion::count());
        $this->assertSame($antes, $correlativo());
    }

    public function test_tarifa_retirada_o_fuera_de_vigencia_responde_422(): void
    {
        $retirada = $this->vistaPrevia(['pax' => self::PAX, 'proveedor_tarifa_id' => $this->crearTarifa('PEN', ['activo' => false])]);
        $this->assertSame(422, $retirada['code']);
        $this->assertStringContainsString('retirada', $retirada['message']);

        $futura = $this->vistaPrevia(['pax' => self::PAX, 'proveedor_tarifa_id' => $this->crearTarifa('PEN', ['vigente_desde' => '2099-01-01'])]);
        $this->assertSame(422, $futura['code']);
        $this->assertStringContainsString('todavía no está vigente', $futura['message']);
    }

    // CA2b — sin reservas.crear → 403 (middleware de la ruta, igual que store).
    public function test_la_ruta_exige_reservas_crear(): void
    {
        $ruta = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/venta-directa/vista-previa' || str_ends_with($r->uri(), 'venta-directa/vista-previa'));

        $this->assertNotNull($ruta, 'la ruta venta-directa/vista-previa no existe');
        $this->assertContains('permission:reservas.crear', $ruta->gatherMiddleware());
    }
}
