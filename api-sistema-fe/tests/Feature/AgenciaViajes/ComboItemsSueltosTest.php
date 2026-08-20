<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\GuiaTarifa;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\Servicio;
use App\Services\AgenciaViajes\ComboExplosionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Fix "ítems sueltos de un combo no se calculan ni se cargan a la
// cotización" (brief 2026-08-18). Un paquete_plantilla_item de un
// paquete_combo puede traer proveedor_tarifa_id/guia_tarifa_id DIRECTO
// sobre el combo (sin envolverlo en un tour-hijo, paquete_plantilla_hijo_id
// null) — el frontend ya lo llama "ítems sueltos"
// (paquetes/detalle.vue::itemsSueltos). Antes de este fix,
// ComboExplosionService::totalesCombo()/toursDelCombo() y
// AlternativaItemController::desdePlantilla() solo miraban tours-hijo,
// perdiendo estos ítems por completo tanto en el precio "desde" del
// catálogo como al cargar el combo en una cotización real. Mismo patrón de
// infraestructura que PaqueteComboTest: Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
class ComboItemsSueltosTest extends TestCase
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

    // ═══════════════════════════════════════════════════════════════
    // Fixtures — mismo patrón que PaqueteComboTest.
    // ═══════════════════════════════════════════════════════════════

    private function crearProveedorTarifa(float $costo, float $ventaAdulto, string $modalidad = 'compartido'): ProveedorTarifa
    {
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);
        $servicio = Servicio::create(['nombre' => 'Traslado ida y vuelta ' . uniqid()]);
        $destinoServicio = DestinoServicio::create([
            'destino_atractivo_id' => $destino->id,
            'servicio_id' => $servicio->id,
        ]);
        $proveedor = Proveedor::create(['razon_social' => 'Transportes Test SAC', 'estado' => true]);
        $proveedorServicio = ProveedorServicio::create([
            'proveedor_id' => $proveedor->id,
            'destino_servicio_id' => $destinoServicio->id,
        ]);

        return ProveedorTarifa::create([
            'proveedor_servicio_id' => $proveedorServicio->id,
            'tipo_tarifa' => 'publica',
            'modalidad' => $modalidad,
            'moneda' => 'PEN',
            'precio_costo' => $costo,
            'margen_tipo' => 'porcentaje',
            'margen_valor' => 20,
            'precio_venta_adulto' => $ventaAdulto,
            'vigente_desde' => now()->toDateString(),
            'tip_afe_igv' => '10',
            'destino_tributario' => 'nacional',
        ]);
    }

    private function crearGuiaTarifa(float $costoDiario, string $nombreGuia = 'Guía Test'): GuiaTarifa
    {
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);
        $guia = Guia::create(['nombre' => $nombreGuia, 'documento' => uniqid(), 'telefono' => '999999999']);

        return GuiaTarifa::create([
            'guia_id' => $guia->id,
            'destino_id' => $destino->id,
            'modalidad' => 'dia_local',
            'costo_diario' => $costoDiario,
            'tipo_margen' => 'porcentaje',
            'margen_valor' => 10,
            'moneda' => 'PEN',
            'vigente_desde' => now()->toDateString(),
        ]);
    }

    private function crearTourSimple(string $nombre, ProveedorTarifa $tarifa): PaquetePlantilla
    {
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);

        $tour = PaquetePlantilla::create([
            'categoria' => 'local',
            'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE,
            'nombre' => $nombre,
            'destino_atractivo_id' => $destino->id,
            'duracion_horas' => 8,
        ]);

        PaquetePlantillaItem::create([
            'paquete_plantilla_id' => $tour->id,
            'proveedor_tarifa_id' => $tarifa->id,
            'orden' => 1,
        ]);

        return $tour;
    }

    private function crearCombo(string $nombre, array $tours, ?string $descuentoTipo = null, ?float $descuentoValor = null): PaquetePlantilla
    {
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);

        $combo = PaquetePlantilla::create([
            'categoria' => 'nacional',
            'tipo' => PaquetePlantilla::TIPO_PAQUETE_COMBO,
            'nombre' => $nombre,
            'destino_atractivo_id' => $destino->id,
            'duracion_horas' => 48,
            'descuento_tipo' => $descuentoTipo,
            'descuento_valor' => $descuentoValor,
        ]);

        foreach ($tours as $orden => $tour) {
            PaquetePlantillaItem::create([
                'paquete_plantilla_id' => $combo->id,
                'paquete_plantilla_hijo_id' => $tour->id,
                'orden' => $orden + 1,
            ]);
        }

        return $combo;
    }

    private function crearAlternativa(): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '99887766', 'full_name' => 'Cliente Test Items Sueltos',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0400', 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId,
            'nombre' => 'Alternativa 1',
            'moneda_cotizacion' => 'PEN',
            'tipo_cambio_aplicado' => 1,
            'tipo_cambio_origen' => 'dia',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // totalesCombo() — cálculo de precio del catálogo (punto 1 del brief)
    // ═══════════════════════════════════════════════════════════════

    public function test_totales_combo_suma_items_sueltos_de_proveedor_y_guia(): void
    {
        $altoMayo = $this->crearTourSimple('Alto Mayo Full Day', $this->crearProveedorTarifa(60, 120));
        $tarifaHotelSuelto = $this->crearProveedorTarifa(30, 50);
        $guiaTarifaSuelta = $this->crearGuiaTarifa(80, 'Guía Suelto Test');

        $combo = $this->crearCombo('Paquete Tarapoto 3D/2N', [$altoMayo]);
        PaquetePlantillaItem::create([
            'paquete_plantilla_id' => $combo->id,
            'proveedor_tarifa_id' => $tarifaHotelSuelto->id,
            'orden' => 2,
        ]);
        PaquetePlantillaItem::create([
            'paquete_plantilla_id' => $combo->id,
            'guia_tarifa_id' => $guiaTarifaSuelta->id,
            'orden' => 3,
        ]);

        $totales = app(ComboExplosionService::class)->totalesCombo($combo);

        // Tour: costo 60 / venta 120. Hotel suelto: costo 30 / venta 50.
        // Guía suelto: costo_diario 80, margen 10% => venta 88.
        $this->assertSame(60.0 + 30.0 + 80.0, $totales['costo_total_combo']);
        $this->assertEqualsWithDelta(120.0 + 50.0 + 88.0, $totales['venta_bruta_combo'], 0.01);
        $this->assertEqualsWithDelta(120.0 + 50.0 + 88.0, $totales['venta_neta_combo'], 0.01);
    }

    public function test_totales_combo_sin_items_sueltos_sigue_calculando_igual_que_antes(): void
    {
        // Regresión explícita del brief: un combo que SOLO tiene tours-hijo
        // (el caso que ya funcionaba) no debe cambiar su total.
        $altoMayo = $this->crearTourSimple('Alto Mayo Full Day', $this->crearProveedorTarifa(60, 120));
        $lagunaAzul = $this->crearTourSimple('Laguna Azul Full Day', $this->crearProveedorTarifa(55, 100));
        $combo = $this->crearCombo('Paquete Tarapoto 3D/2N', [$altoMayo, $lagunaAzul]);

        $totales = app(ComboExplosionService::class)->totalesCombo($combo);

        $this->assertSame(115.0, $totales['costo_total_combo']);
        $this->assertSame(220.0, $totales['venta_bruta_combo']);
        $this->assertSame(220.0, $totales['venta_neta_combo']);

        $this->assertTrue(app(ComboExplosionService::class)->itemsSueltosDelCombo($combo)->isEmpty());
    }

    // ═══════════════════════════════════════════════════════════════
    // explotarItems()/explotarItemsSueltos() — antes código muerto con el
    // mismo bug (punto 3 del brief), ahora usado de verdad.
    // ═══════════════════════════════════════════════════════════════

    public function test_explotar_items_incluye_los_sueltos_del_combo(): void
    {
        $altoMayo = $this->crearTourSimple('Alto Mayo Full Day', $this->crearProveedorTarifa(60, 120));
        $tarifaSuelta = $this->crearProveedorTarifa(30, 50, 'privado');
        $combo = $this->crearCombo('Paquete Tarapoto 3D/2N', [$altoMayo]);
        PaquetePlantillaItem::create([
            'paquete_plantilla_id' => $combo->id,
            'proveedor_tarifa_id' => $tarifaSuelta->id,
            'orden' => 2,
        ]);

        $items = app(ComboExplosionService::class)->explotarItems($combo);

        $this->assertCount(2, $items);
        $this->assertSame($altoMayo->id, $items[0]['tour_origen_id']);
        $this->assertNull($items[1]['tour_origen_id']);
        $this->assertSame($tarifaSuelta->id, $items[1]['proveedor_tarifa_id']);
    }

    // ═══════════════════════════════════════════════════════════════
    // desdePlantilla() — cargar el combo en una cotización real (punto 2
    // del brief, el bug de mayor impacto: se perdía el servicio por
    // completo, sin error, en una cotización de cliente real).
    // ═══════════════════════════════════════════════════════════════

    public function test_desde_plantilla_crea_alternativa_items_para_tour_y_para_items_sueltos(): void
    {
        $altoMayo = $this->crearTourSimple('Alto Mayo Full Day', $this->crearProveedorTarifa(60, 120));
        $tarifaHotelSuelto = $this->crearProveedorTarifa(30, 50, 'privado');
        $guiaTarifaSuelta = $this->crearGuiaTarifa(80, 'Guía Suelto Test');

        $combo = $this->crearCombo('Paquete Tarapoto 3D/2N', [$altoMayo]);
        PaquetePlantillaItem::create([
            'paquete_plantilla_id' => $combo->id,
            'proveedor_tarifa_id' => $tarifaHotelSuelto->id,
            'orden' => 2,
        ]);
        PaquetePlantillaItem::create([
            'paquete_plantilla_id' => $combo->id,
            'guia_tarifa_id' => $guiaTarifaSuelta->id,
            'orden' => 3,
        ]);

        $alternativa = $this->crearAlternativa();

        $response = app(AlternativaItemController::class)->desdePlantilla(
            new Request(['paquete_plantilla_id' => $combo->id, 'dia_referencial' => 1]),
            (string) $alternativa->id
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true);

        // 1 ítem del tour-hijo + 1 hotel suelto + 1 guía suelto = 3.
        $this->assertCount(3, $data['items_agregados']);

        $itemsPersistidos = AlternativaItem::where('alternativa_id', $alternativa->id)->get();
        $this->assertCount(3, $itemsPersistidos);

        $itemHotelSuelto = $itemsPersistidos->firstWhere('proveedor_tarifa_id', $tarifaHotelSuelto->id);
        $this->assertNotNull($itemHotelSuelto, 'el hotel suelto del combo debe generar su propio AlternativaItem');
        $this->assertNull($itemHotelSuelto->tour_origen_id);
        $this->assertEqualsWithDelta(30.0, (float) $itemHotelSuelto->costo_snapshot, 0.01);
        $this->assertEqualsWithDelta(50.0, (float) $itemHotelSuelto->precio_venta_snapshot, 0.01);

        $itemGuiaSuelto = $itemsPersistidos->firstWhere('guia_tarifa_id', $guiaTarifaSuelta->id);
        $this->assertNotNull($itemGuiaSuelto, 'el guía suelto del combo debe generar su propio AlternativaItem');
        $this->assertNull($itemGuiaSuelto->tour_origen_id);

        $itemTourHijo = $itemsPersistidos->firstWhere('tour_origen_id', $altoMayo->id);
        $this->assertNotNull($itemTourHijo, 'el ítem del tour-hijo debe seguir generándose igual que antes');

        // El total de la alternativa (recalculado dentro de la misma
        // transacción de desdePlantilla()) debe reflejar los 3 ítems, no
        // solo el del tour-hijo — confirma que el bug no solo afectaba al
        // precio "desde" del catálogo, sino a la cotización real del cliente.
        $this->assertEqualsWithDelta(50.0 + 88.0, (float) $alternativa->fresh()->total, 0.01);
    }
}
