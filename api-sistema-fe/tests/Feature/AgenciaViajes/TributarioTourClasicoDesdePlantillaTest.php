<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\ReservaFacturacionController;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\GuiaTarifa;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\Reserva;
use App\Services\AgenciaViajes\ReservaItemCreacionService;
use App\Services\HoraPeru;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AgenciaViajes\AltaItem\Concerns\PreparaAltaItem;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// T-029 — tour clásico cargado desde plantilla (AlternativaItemController::desdePlantilla()):
// cada servicio nace con su tratamiento tributario (H18) y el costo del compartido no cuenta
// infantes (H19). Postgres real, transacción por test.
class TributarioTourClasicoDesdePlantillaTest extends TestCase
{
    use ConAccesoATodasLasReservas;
    use PreparaAltaItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararBase();
        $this->verTodasLasReservas();
    }

    protected function tearDown(): void
    {
        $this->deshacerBase();
        parent::tearDown();
    }

    private function guiaTarifa(): GuiaTarifa
    {
        $guia = Guia::create(['nombre' => 'Guía T029', 'documento' => '12345678', 'telefono' => '999999999', 'activo' => true]);

        return GuiaTarifa::create([
            'guia_id' => $guia->id,
            'destino_id' => (DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']))->id,
            'modalidad' => 'dia_local', 'costo_diario' => 100, 'tipo_margen' => 'porcentaje', 'margen_valor' => 20,
            'moneda' => 'PEN', 'vigente_desde' => HoraPeru::hoyTexto(),
        ]);
    }

    /** Tour con una tarifa (modalidad dada) y un guía; ajuste de redondeo opcional. */
    private function tour(array $tarifas, bool $conGuia = true, ?float $ajuste = null): PaquetePlantilla
    {
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);
        $tour = PaquetePlantilla::create([
            'categoria' => 'local', 'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE, 'nombre' => 'Tour T029',
            'destino_atractivo_id' => $destino->id, 'duracion_horas' => 8, 'ajuste_redondeo' => $ajuste,
        ]);
        $orden = 1;
        foreach ($tarifas as $tarifa) {
            PaquetePlantillaItem::create(['paquete_plantilla_id' => $tour->id, 'proveedor_tarifa_id' => $tarifa->id, 'orden' => $orden++]);
        }
        if ($conGuia) {
            PaquetePlantillaItem::create(['paquete_plantilla_id' => $tour->id, 'guia_tarifa_id' => $this->guiaTarifa()->id, 'orden' => $orden]);
        }

        return $tour;
    }

    private function cargar($alternativa, PaquetePlantilla $tour)
    {
        $respuesta = app(AlternativaItemController::class)->desdePlantilla(
            new Request(['paquete_plantilla_id' => $tour->id, 'dia_referencial' => 1]),
            (string) $alternativa->id
        );
        $this->assertSame(200, $respuesta->getStatusCode());

        return $alternativa->items()->orderBy('id')->get();
    }

    private function tarifaConIgv(string $tip, string $destino, string $modalidad = 'privado')
    {
        $tarifa = $this->tarifaProveedor($modalidad);
        $tarifa->update(['tip_afe_igv' => $tip, 'destino_tributario' => $destino]);

        return $tarifa->fresh();
    }

    // CA1 — falla antes del fix: el guía nacía con tip_afe_igv nulo.
    public function test_guia_del_tour_nace_con_el_default_de_la_agencia(): void
    {
        $this->defaultTributarioDeLaAgencia('20', 'amazonia');
        [$alternativa] = $this->alternativa();

        $items = $this->cargar($alternativa, $this->tour([$this->tarifaConIgv('20', 'amazonia')]));

        $guia = $items->firstWhere('origen_tipo', AlternativaItem::ORIGEN_GUIA);
        $this->assertSame('20', $guia->tip_afe_igv);
        $this->assertSame('amazonia', $guia->destino_tributario);
    }

    // CA2
    public function test_servicios_con_tarifa_nacen_con_el_tratamiento_de_su_tarifa(): void
    {
        $this->defaultTributarioDeLaAgencia('20', 'amazonia');
        [$alternativa] = $this->alternativa();

        $items = $this->cargar($alternativa, $this->tour([
            $this->tarifaConIgv('10', 'nacional', 'privado'),
            $this->tarifaConIgv('30', 'nacional', 'compartido'),
        ], conGuia: false));

        $this->assertSame(['10', 'nacional'], [$items[0]->tip_afe_igv, $items[0]->destino_tributario]);
        $this->assertSame(['30', 'nacional'], [$items[1]->tip_afe_igv, $items[1]->destino_tributario]);
    }

    // CA3
    public function test_ajuste_de_redondeo_toma_el_tratamiento_de_los_servicios_si_coinciden(): void
    {
        $this->defaultTributarioDeLaAgencia('10', 'nacional');
        [$alternativa] = $this->alternativa();

        $items = $this->cargar($alternativa, $this->tour([$this->tarifaConIgv('20', 'amazonia')], conGuia: false, ajuste: 6.34));

        $ajuste = $items->firstWhere('descripcion_manual', 'Ajuste de redondeo');
        $this->assertSame(['20', 'amazonia'], [$ajuste->tip_afe_igv, $ajuste->destino_tributario]);
    }

    public function test_ajuste_de_redondeo_usa_el_default_si_los_servicios_estan_mezclados(): void
    {
        $this->defaultTributarioDeLaAgencia('20', 'amazonia');
        [$alternativa] = $this->alternativa();

        $items = $this->cargar($alternativa, $this->tour([
            $this->tarifaConIgv('10', 'nacional'),
            $this->tarifaConIgv('20', 'amazonia'),
        ], conGuia: false, ajuste: -3.5));

        $ajuste = $items->firstWhere('descripcion_manual', 'Ajuste de redondeo');
        $this->assertSame(['20', 'amazonia'], [$ajuste->tip_afe_igv, $ajuste->destino_tributario]);
    }

    // CA4 — la reserva de un tour así en agencia Amazonía: todos sus ítems resuelven igual,
    // sin mezcla gravado/exonerado ni caída al '10'/'nacional'.
    public function test_reserva_del_tour_factura_sin_mezcla_tributaria(): void
    {
        $this->defaultTributarioDeLaAgencia('20', 'amazonia');
        [$alternativa] = $this->alternativa();
        $items = $this->cargar($alternativa, $this->tour([$this->tarifaConIgv('20', 'amazonia')], conGuia: true, ajuste: 6.34));
        $this->assertCount(3, $items); // servicio + guía + ajuste

        $reserva = Reserva::create(['alternativa_id' => $alternativa->id, 'estado' => 'activa']);
        $reservaItems = $items->map(fn ($i) => app(ReservaItemCreacionService::class)->crear($reserva, $i, null, []));

        foreach ($reservaItems as $ri) {
            $this->assertSame('20', ReservaFacturacionController::resolverTipAfeIgvItem($ri->fresh()));
            $this->assertSame('amazonia', ReservaFacturacionController::resolverDestinoTributario($ri->fresh()));
        }
    }

    // CA5 (H19) — 2 adultos, 1 niño, 1 infante en la alternativa: se quita el niño del conteo
    // pidiendo el caso exacto de la tarea con una cotización de 2 adultos + 1 infante.
    public function test_costo_del_compartido_no_cuenta_infantes(): void
    {
        [$alternativa, $pax] = $this->alternativa();
        // Dejar solo 2 adultos + 1 infante.
        DB::table('cotizacion_pasajeros')->where('id', $pax[2])->delete();

        $tarifa = $this->tarifaProveedor('compartido');
        $tarifa->update(['precio_costo' => 20, 'precio_venta_adulto' => 50]);

        $items = $this->cargar($alternativa, $this->tour([$tarifa->fresh()], conGuia: false));

        $this->assertSame('por_persona', $items[0]->modo_precio);
        $this->assertEqualsWithDelta(40.0, (float) $items[0]->costo_snapshot, 0.01); // 20 × 2
    }

    // CA6
    public function test_comando_de_diagnostico_lista_sin_escribir(): void
    {
        [$alternativa] = $this->alternativa();
        $guia = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => AlternativaItem::ORIGEN_GUIA, 'guia_tarifa_id' => $this->guiaTarifa()->id,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN', 'costo_snapshot' => 1, 'precio_venta_snapshot' => 1, 'precio_convertido' => 1,
        ]);
        $antes = DB::table('alternativa_items')->get()->toJson();

        $resultado = (new \ReflectionMethod(\App\Console\Commands\DiagnosticarItemsSinIgv::class, 'buscar'));
        $resultado->setAccessible(true);
        $filas = $resultado->invoke(new \App\Console\Commands\DiagnosticarItemsSinIgv());

        $this->assertTrue($filas['alternativa']->pluck('id')->contains($guia->id));
        $this->assertSame($antes, DB::table('alternativa_items')->get()->toJson());
    }
}
