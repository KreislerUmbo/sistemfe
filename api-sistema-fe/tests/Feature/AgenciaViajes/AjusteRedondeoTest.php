<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\PaquetePlantillaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
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

// Fix "ajuste de redondeo en precio de tours/combos" (brief 2026-08-18,
// ejecutado DESPUÉS del fix de ítems sueltos del combo). El vendedor arma
// un tour/combo con ítems reales que suman un número no redondo
// (ej. S/93.66) pero el negocio quiere cobrar un número redondo
// (ej. S/100) — paquetes_plantilla.ajuste_redondeo (positivo o negativo,
// aplica a AMBOS tipos) es la palanca nueva. Mismo patrón de
// infraestructura que PaqueteComboTest/ComboItemsSueltosTest: Postgres
// real (sistemafe_test_migrations), transacción por test revertida.
class AjusteRedondeoTest extends TestCase
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
    // Fixtures — mismo patrón que PaqueteComboTest/ComboItemsSueltosTest.
    // ═══════════════════════════════════════════════════════════════

    private function crearProveedorTarifa(float $costo, float $ventaAdulto, string $modalidad = 'privado'): ProveedorTarifa
    {
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);
        $servicio = Servicio::create(['nombre' => 'Traslado ' . uniqid()]);
        $destinoServicio = DestinoServicio::create(['destino_atractivo_id' => $destino->id, 'servicio_id' => $servicio->id]);
        $proveedor = Proveedor::create(['razon_social' => 'Transportes Test SAC', 'estado' => true]);
        $proveedorServicio = ProveedorServicio::create(['proveedor_id' => $proveedor->id, 'destino_servicio_id' => $destinoServicio->id]);

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

    private function crearTourSimple(string $nombre, ProveedorTarifa $tarifa, ?float $ajusteRedondeo = null): PaquetePlantilla
    {
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);

        $tour = PaquetePlantilla::create([
            'categoria' => 'local',
            'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE,
            'nombre' => $nombre,
            'destino_atractivo_id' => $destino->id,
            'duracion_horas' => 8,
            'ajuste_redondeo' => $ajusteRedondeo,
        ]);

        PaquetePlantillaItem::create(['paquete_plantilla_id' => $tour->id, 'proveedor_tarifa_id' => $tarifa->id, 'orden' => 1]);

        return $tour;
    }

    private function crearCombo(string $nombre, array $tours, ?float $ajusteRedondeo = null): PaquetePlantilla
    {
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);

        $combo = PaquetePlantilla::create([
            'categoria' => 'nacional',
            'tipo' => PaquetePlantilla::TIPO_PAQUETE_COMBO,
            'nombre' => $nombre,
            'destino_atractivo_id' => $destino->id,
            'duracion_horas' => 48,
            'ajuste_redondeo' => $ajusteRedondeo,
        ]);

        foreach ($tours as $orden => $tour) {
            PaquetePlantillaItem::create(['paquete_plantilla_id' => $combo->id, 'paquete_plantilla_hijo_id' => $tour->id, 'orden' => $orden + 1]);
        }

        return $combo;
    }

    private function crearAlternativa(): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '55443322', 'full_name' => 'Cliente Test Ajuste Redondeo',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0500', 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'created_at' => now(), 'updated_at' => now(),
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
    // $fillable — mismo bug ya visto 2 veces en este proyecto (Company en
    // Caja Fase 4, Sale en Series de comprobantes): un campo nuevo que no
    // se agrega a $fillable se descarta en silencio.
    // ═══════════════════════════════════════════════════════════════

    public function test_ajuste_redondeo_se_persiste_via_mass_assignment(): void
    {
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);

        $tour = PaquetePlantilla::create([
            'categoria' => 'local', 'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE, 'nombre' => 'Tour Test',
            'destino_atractivo_id' => $destino->id, 'duracion_horas' => 8, 'ajuste_redondeo' => 6.34,
        ]);

        $this->assertEqualsWithDelta(6.34, (float) $tour->fresh()->ajuste_redondeo, 0.001);

        $tour->update(['ajuste_redondeo' => -3.5]);
        $this->assertEqualsWithDelta(-3.5, (float) $tour->fresh()->ajuste_redondeo, 0.001);
    }

    // ═══════════════════════════════════════════════════════════════
    // Combo: PriceEngineService::calcularCombo() vía totalesCombo()
    // ═══════════════════════════════════════════════════════════════

    public function test_combo_null_ajuste_redondeo_no_cambia_el_calculo_existente(): void
    {
        // Regresión explícita del brief: null = sin ajuste, comportamiento
        // idéntico al de antes de este fix para cualquier combo existente.
        $altoMayo = $this->crearTourSimple('Alto Mayo Full Day', $this->crearProveedorTarifa(60, 120));
        $lagunaAzul = $this->crearTourSimple('Laguna Azul Full Day', $this->crearProveedorTarifa(55, 100));
        $combo = $this->crearCombo('Paquete Tarapoto 3D/2N', [$altoMayo, $lagunaAzul]);

        $totales = app(ComboExplosionService::class)->totalesCombo($combo);

        $this->assertSame(220.0, $totales['venta_neta_combo']);
        $this->assertNull($totales['ajuste_redondeo']);
        $this->assertSame(220.0, $totales['venta_final_combo']);
    }

    public function test_combo_ajuste_redondeo_positivo_sube_venta_final_sin_tocar_venta_neta(): void
    {
        $altoMayo = $this->crearTourSimple('Alto Mayo Full Day', $this->crearProveedorTarifa(60, 93.66));
        $combo = $this->crearCombo('Combo Redondeo', [$altoMayo], 6.34);

        $totales = app(ComboExplosionService::class)->totalesCombo($combo);

        $this->assertSame(93.66, $totales['venta_neta_combo']);
        $this->assertSame(6.34, $totales['ajuste_redondeo']);
        $this->assertSame(100.0, $totales['venta_final_combo']);
        // margen_resultante_pct se mide SOLO sobre venta_neta_combo, sin el
        // ajuste — decisión de negocio confirmada (no es rentabilidad real).
        $this->assertEqualsWithDelta((93.66 - 60) / 60 * 100, $totales['margen_resultante_pct'], 0.01);
    }

    public function test_combo_ajuste_redondeo_negativo_baja_venta_final(): void
    {
        $altoMayo = $this->crearTourSimple('Alto Mayo Full Day', $this->crearProveedorTarifa(60, 120));
        $combo = $this->crearCombo('Combo Redondeo Abajo', [$altoMayo], -20.0);

        $totales = app(ComboExplosionService::class)->totalesCombo($combo);

        $this->assertSame(120.0, $totales['venta_neta_combo']);
        $this->assertSame(-20.0, $totales['ajuste_redondeo']);
        $this->assertSame(100.0, $totales['venta_final_combo']);
    }

    public function test_combo_ajuste_redondeo_aplica_despues_del_descuento(): void
    {
        $altoMayo = $this->crearTourSimple('Alto Mayo Full Day', $this->crearProveedorTarifa(60, 120));
        $destino = DestinoAtractivo::first();
        $combo = PaquetePlantilla::create([
            'categoria' => 'nacional', 'tipo' => PaquetePlantilla::TIPO_PAQUETE_COMBO, 'nombre' => 'Combo Descuento + Ajuste',
            'destino_atractivo_id' => $destino->id, 'duracion_horas' => 48,
            'descuento_tipo' => 'porcentaje', 'descuento_valor' => 10, 'ajuste_redondeo' => 5,
        ]);
        PaquetePlantillaItem::create(['paquete_plantilla_id' => $combo->id, 'paquete_plantilla_hijo_id' => $altoMayo->id, 'orden' => 1]);

        $totales = app(ComboExplosionService::class)->totalesCombo($combo);

        // venta_bruta 120 -10% = venta_neta 108, +5 de ajuste = 113 final.
        $this->assertSame(108.0, $totales['venta_neta_combo']);
        $this->assertSame(113.0, $totales['venta_final_combo']);
    }

    public function test_actualizar_combo_con_piso_de_margen_no_rompe_con_ajuste_redondeo_presente(): void
    {
        // Guard de PaquetePlantillaController::update() al simular el margen
        // mínimo del combo — confirma que $paqueteSimulado->fill($validado)
        // sigue funcionando con el campo nuevo en juego (no revienta el
        // flujo existente de validación de margen mínimo).
        $altoMayo = $this->crearTourSimple('Alto Mayo Full Day', $this->crearProveedorTarifa(60, 120));
        $combo = $this->crearCombo('Combo Margen', [$altoMayo], 5.0);
        $combo->update(['margen_minimo_pct' => 50]);

        $response = app(PaquetePlantillaController::class)->update(new Request([
            'categoria' => $combo->categoria, 'nombre' => $combo->nombre,
            'destino_atractivo_id' => $combo->destino_atractivo_id, 'duracion_horas' => $combo->duracion_horas,
            'ajuste_redondeo' => 8.0,
        ]), (string) $combo->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertEqualsWithDelta(8.0, (float) $combo->fresh()->ajuste_redondeo, 0.001);
    }

    // ═══════════════════════════════════════════════════════════════
    // desdePlantilla() — el punto crítico del brief: la cotización real
    // debe sumar exactamente el total final, con la línea de ajuste visible.
    // ═══════════════════════════════════════════════════════════════

    public function test_desde_plantilla_tour_simple_crea_item_de_ajuste_y_la_alternativa_suma_el_total_redondo(): void
    {
        $tour = $this->crearTourSimple('Tour con ajuste', $this->crearProveedorTarifa(60, 93.66), 6.34);
        $alternativa = $this->crearAlternativa();

        $response = app(AlternativaItemController::class)->desdePlantilla(
            new Request(['paquete_plantilla_id' => $tour->id, 'dia_referencial' => 1]),
            (string) $alternativa->id
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true);

        // 1 ítem del proveedor + 1 ítem de ajuste = 2.
        $this->assertCount(2, $data['items_agregados']);

        $itemAjuste = AlternativaItem::where('alternativa_id', $alternativa->id)
            ->where('origen_tipo', AlternativaItem::ORIGEN_MANUAL)
            ->first();
        $this->assertNotNull($itemAjuste, 'debe crearse un AlternativaItem manual con el ajuste');
        $this->assertSame('Ajuste de redondeo', $itemAjuste->descripcion_manual);
        $this->assertEqualsWithDelta(0.0, (float) $itemAjuste->costo_snapshot, 0.01);
        $this->assertEqualsWithDelta(6.34, (float) $itemAjuste->precio_venta_snapshot, 0.01);

        $this->assertEqualsWithDelta(100.0, (float) $alternativa->fresh()->total, 0.01);
    }

    public function test_desde_plantilla_combo_crea_un_solo_item_de_ajuste_no_uno_por_tour(): void
    {
        $altoMayo = $this->crearTourSimple('Alto Mayo Full Day', $this->crearProveedorTarifa(60, 60));
        $lagunaAzul = $this->crearTourSimple('Laguna Azul Full Day', $this->crearProveedorTarifa(55, 33.66));
        // Un ajuste_redondeo puesto en los tours-hijo NO debe generar su
        // propio ítem al cargarse dentro de un combo — solo el ajuste del
        // NIVEL SUPERIOR (el combo mismo) genera línea. Confirma que no se
        // propaga transitivamente.
        $altoMayo->update(['ajuste_redondeo' => 999]);
        $combo = $this->crearCombo('Combo con ajuste', [$altoMayo, $lagunaAzul], 6.34);

        $alternativa = $this->crearAlternativa();
        $response = app(AlternativaItemController::class)->desdePlantilla(
            new Request(['paquete_plantilla_id' => $combo->id, 'dia_referencial' => 1]),
            (string) $alternativa->id
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true);

        // 2 ítems de proveedor (uno por tour) + 1 SOLO ítem de ajuste = 3.
        $this->assertCount(3, $data['items_agregados']);

        $itemsAjuste = AlternativaItem::where('alternativa_id', $alternativa->id)
            ->where('origen_tipo', AlternativaItem::ORIGEN_MANUAL)
            ->get();
        $this->assertCount(1, $itemsAjuste, 'el ajuste_redondeo=999 del tour-hijo alto mayo NO debe generar su propio ítem');
        $this->assertEqualsWithDelta(6.34, (float) $itemsAjuste->first()->precio_venta_snapshot, 0.01);

        // 60 + 33.66 + 6.34 = 100.0 — el 999 del tour-hijo nunca entra.
        $this->assertEqualsWithDelta(100.0, (float) $alternativa->fresh()->total, 0.01);
    }

    public function test_desde_plantilla_ajuste_redondeo_negativo_tambien_funciona(): void
    {
        $tour = $this->crearTourSimple('Tour ajuste negativo', $this->crearProveedorTarifa(60, 120), -20.0);
        $alternativa = $this->crearAlternativa();

        app(AlternativaItemController::class)->desdePlantilla(
            new Request(['paquete_plantilla_id' => $tour->id, 'dia_referencial' => 1]),
            (string) $alternativa->id
        );

        $itemAjuste = AlternativaItem::where('alternativa_id', $alternativa->id)->where('origen_tipo', AlternativaItem::ORIGEN_MANUAL)->first();
        $this->assertEqualsWithDelta(-20.0, (float) $itemAjuste->precio_venta_snapshot, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $alternativa->fresh()->total, 0.01);
    }

    public function test_desde_plantilla_sin_ajuste_redondeo_no_crea_item_extra(): void
    {
        // Regresión explícita: null = sin cambios, ningún tour/combo
        // existente que no use el campo debe ver un ítem nuevo aparecer.
        $tour = $this->crearTourSimple('Tour sin ajuste', $this->crearProveedorTarifa(60, 120));
        $alternativa = $this->crearAlternativa();

        $response = app(AlternativaItemController::class)->desdePlantilla(
            new Request(['paquete_plantilla_id' => $tour->id, 'dia_referencial' => 1]),
            (string) $alternativa->id
        );

        $data = $response->getData(true);
        $this->assertCount(1, $data['items_agregados']);
        $this->assertSame(0, AlternativaItem::where('alternativa_id', $alternativa->id)->where('origen_tipo', AlternativaItem::ORIGEN_MANUAL)->count());
    }
}
