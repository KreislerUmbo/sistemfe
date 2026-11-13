<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\CotizacionController;
use App\Http\Controllers\AgenciaViajes\VentaDirectaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\Servicio;
use App\Services\HoraPeru;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// T-031 — servicio de tour (tarifa sin tipo_habitacion) sin precio de niño:
// el niño paga como adulto. Hotel (tipo_habitacion no nulo): sigue en 0.
// Infante: sin cambios (vacío = 0). Postgres real, transacción por test.
class NinoSinPrecioPagaComoAdultoTest extends TestCase
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

    private function crearTarifa(string $modalidad, float $ventaAdulto, ?float $ventaNino, ?string $tipoHabitacion = null, ?float $ventaInfante = null): ProveedorTarifa
    {
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);
        $servicio = Servicio::create(['nombre' => 'Servicio T-031']);
        $destinoServicio = DestinoServicio::create(['destino_atractivo_id' => $destino->id, 'servicio_id' => $servicio->id]);
        $proveedor = Proveedor::create(['razon_social' => 'Proveedor T-031 SAC', 'estado' => true]);
        $proveedorServicio = ProveedorServicio::create(['proveedor_id' => $proveedor->id, 'destino_servicio_id' => $destinoServicio->id]);

        return ProveedorTarifa::create([
            'proveedor_servicio_id' => $proveedorServicio->id,
            'tipo_tarifa' => 'publica',
            'modalidad' => $modalidad,
            'moneda' => 'PEN',
            'tipo_habitacion' => $tipoHabitacion,
            'precio_costo' => 60,
            'margen_tipo' => 'fijo',
            'margen_valor' => 40,
            'precio_venta_adulto' => $ventaAdulto,
            'precio_venta_nino' => $ventaNino,
            'precio_venta_infante' => $ventaInfante,
            'vigente_desde' => HoraPeru::hoyTexto(),
            'tip_afe_igv' => '10',
            'destino_tributario' => 'nacional',
        ]);
    }

    /** Cotización con 2 adultos + 1 niño (+ 1 infante si se pide) y una alternativa vacía. */
    private function crearAlternativa(bool $conNino = true, bool $conInfante = false): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10000000, 99999999), 'full_name' => 'Cliente T-031',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-'.random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'created_at' => now(), 'updated_at' => now(),
        ]);

        CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 30]);
        CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 28]);
        if ($conNino) {
            CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'nino', 'edad' => 8]);
        }
        if ($conInfante) {
            CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'infante', 'edad' => 2]);
        }

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId,
            'nombre' => 'Alternativa 1',
            'moneda_cotizacion' => 'PEN',
            'tipo_cambio_aplicado' => 1,
            'tipo_cambio_origen' => 'dia',
        ]);
    }

    private function agregarPorPersona(Alternativa $alternativa, ProveedorTarifa $tarifa): AlternativaItem
    {
        $respuesta = app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'proveedor',
            'proveedor_tarifa_id' => $tarifa->id,
            'modo_precio' => 'por_persona',
        ]), (string) $alternativa->id);

        $this->assertSame(200, $respuesta->getStatusCode(), json_encode($respuesta->getData(true)));

        return AlternativaItem::findOrFail($respuesta->getData(true)['alternativa_item']['id']);
    }

    // CA1 — niño vacío en servicio de tour: 2 × 100 + 1 × 100 = 300.
    public function test_servicio_de_tour_sin_precio_de_nino_cobra_al_nino_como_adulto(): void
    {
        $item = $this->agregarPorPersona($this->crearAlternativa(), $this->crearTarifa('compartido', 100, null));

        $this->assertEqualsWithDelta(300.0, (float) $item->precio_venta_snapshot, 0.01);
    }

    // CA2 — con precio de niño propio no cambia: 2 × 100 + 70 = 270.
    public function test_con_precio_de_nino_propio_no_cambia(): void
    {
        $item = $this->agregarPorPersona($this->crearAlternativa(), $this->crearTarifa('compartido', 100, 70));

        $this->assertEqualsWithDelta(270.0, (float) $item->precio_venta_snapshot, 0.01);
    }

    // CA2b — un niño con precio 0 escrito a propósito es gratis (0 no es vacío).
    public function test_nino_con_precio_cero_explicito_sigue_gratis(): void
    {
        $item = $this->agregarPorPersona($this->crearAlternativa(), $this->crearTarifa('compartido', 100, 0));

        $this->assertEqualsWithDelta(200.0, (float) $item->precio_venta_snapshot, 0.01);
    }

    // CA3 — el infante sin precio sigue en 0 (el niño sí paga como adulto).
    public function test_infante_sin_precio_sigue_en_cero(): void
    {
        $item = $this->agregarPorPersona($this->crearAlternativa(conInfante: true), $this->crearTarifa('compartido', 100, null));

        $this->assertEqualsWithDelta(300.0, (float) $item->precio_venta_snapshot, 0.01);
    }

    // CA3b — tarifa de hotel: el niño sin precio sigue en 0, igual que hoy.
    public function test_tarifa_de_hotel_sin_precio_de_nino_sigue_en_cero(): void
    {
        $item = $this->agregarPorPersona($this->crearAlternativa(), $this->crearTarifa('privado', 100, null, 'doble'));

        $this->assertEqualsWithDelta(200.0, (float) $item->precio_venta_snapshot, 0.01);
    }

    // CA4 — tour clásico desde plantilla con un servicio compartido sin precio de niño.
    public function test_tour_clasico_desde_plantilla_cobra_al_nino_como_adulto(): void
    {
        $alternativa = $this->crearAlternativa();
        $tarifa = $this->crearTarifa('compartido', 100, null);
        $tour = PaquetePlantilla::create([
            'categoria' => 'local',
            'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE,
            'nombre' => 'City Tour T-031',
            'destino_atractivo_id' => DestinoAtractivo::first()->id,
            'duracion_horas' => 8,
        ]);
        PaquetePlantillaItem::create(['paquete_plantilla_id' => $tour->id, 'proveedor_tarifa_id' => $tarifa->id, 'orden' => 1]);

        $respuesta = app(AlternativaItemController::class)->desdePlantilla(
            new Request(['paquete_plantilla_id' => $tour->id, 'dia_referencial' => 1]),
            (string) $alternativa->id
        );

        $this->assertSame(200, $respuesta->getStatusCode());
        $items = $respuesta->getData()->items_agregados;
        $this->assertCount(1, $items);
        $this->assertEqualsWithDelta(300.0, (float) $items[0]->precio_venta_snapshot, 0.01);
    }

    // CA5 — sumar un niño a una alternativa en borrador recalcula con la regla nueva.
    public function test_cambiar_pasajeros_recalcula_con_la_regla_nueva(): void
    {
        $alternativa = $this->crearAlternativa(conNino: false);
        $item = $this->agregarPorPersona($alternativa, $this->crearTarifa('compartido', 100, null));
        $this->assertEqualsWithDelta(200.0, (float) $item->precio_venta_snapshot, 0.01);

        $pasajeros = CotizacionPasajero::where('cotizacion_id', $alternativa->cotizacion_id)->orderBy('id')->get()
            ->map(fn ($p) => ['id' => $p->id, 'edad' => $p->edad])->all();
        $pasajeros[] = ['edad' => 8];

        $respuesta = app(CotizacionController::class)->actualizarPasajeros(
            new Request(['pasajeros' => $pasajeros]),
            (string) $alternativa->cotizacion_id
        );

        $this->assertSame(200, $respuesta->getStatusCode(), json_encode($respuesta->getData(true)));
        $this->assertEqualsWithDelta(300.0, (float) $item->fresh()->precio_venta_snapshot, 0.01);
    }

    // Venta directa — la vista previa (desglose y total) coincide con lo guardado.
    public function test_venta_directa_vista_previa_coincide_con_el_total_guardado(): void
    {
        $tarifa = $this->crearTarifa('compartido', 100, null);
        $pax = [['edad' => 30], ['edad' => 40], ['edad' => 8], ['edad' => 1]];

        $previa = app(VentaDirectaController::class)->vistaPrevia(new Request(['pax' => $pax, 'proveedor_tarifa_id' => $tarifa->id]))->getData(true);
        $this->assertSame(200, $previa['code'], $previa['message'] ?? '');
        $this->assertEquals(['cantidad' => 1, 'precio' => 100], $previa['por_persona']['desglose']['nino']);
        $this->assertEquals(['cantidad' => 1, 'precio' => 0], $previa['por_persona']['desglose']['infante']);
        $this->assertEqualsWithDelta(300.0, $previa['por_persona']['total'], 0.001);

        $creada = app(VentaDirectaController::class)->store(new Request([
            'cliente_id' => DB::table('clients')->insertGetId([
                'type_document' => 'DNI', 'n_document' => (string) random_int(10000000, 99999999), 'full_name' => 'Cliente VD T-031',
                'created_at' => now(), 'updated_at' => now(),
            ]),
            'destino' => 'Moyobamba',
            'fecha_servicio' => '2026-10-20',
            'origen_tipo' => 'proveedor',
            'pax' => $pax,
            'proveedor_tarifa_id' => $tarifa->id,
            'modo_precio' => 'por_persona',
            'cantidad' => 1,
        ]))->getData(true);
        $this->assertSame(200, $creada['code'], $creada['message'] ?? '');

        $guardado = (float) AlternativaItem::where('alternativa_id', $creada['alternativa']['id'])->get()->sum('total_convertido');
        $this->assertEqualsWithDelta($guardado, $previa['por_persona']['total'], 0.001);
    }
}
