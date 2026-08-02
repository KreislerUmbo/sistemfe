<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\PaquetePlantillaController;
use App\Http\Controllers\AgenciaViajes\PaquetePlantillaItemController;
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
use App\Models\AgenciaViajes\TourItinerarioItem;
use App\Services\AgenciaViajes\ComboExplosionService;
use App\Services\AgenciaViajes\ComboValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Sesión 11b4 del vertical Agencia de Viajes — paquete_combo (tipo nuevo de
// paquetes_plantilla, agrupa 2+ tours_simple). Corre contra
// sistemafe_test_migrations (Postgres real), mismo patrón que
// ReservarCorrelativoTest: config override + transacción por test revertida
// en tearDown(). Primera suite de tests de todo el vertical Agencia de
// Viajes — no existía ningún test previo (Sesiones 0-11c fueron todas
// verificadas manualmente contra tenants reales/sandbox, nunca con
// PHPUnit) — confirmado antes de escribir esto, no asumido.
//
// Fixture real usada en todos los casos: "Alto Mayo Full Day" (costo 60,
// venta 120) + "Laguna Azul Full Day" (costo 55, venta 100) → combo
// "Paquete Tarapoto 3D/2N" sin descuento = 220, el mismo escenario que el
// usuario pidió probar en la conversación de esta sesión.
class PaqueteComboTest extends TestCase
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
    // Fixtures — mismo patrón de "servicio de dominio puro" del resto del
    // vertical: arman la cadena real de FK (destino → servicio →
    // destino_servicio → proveedor → proveedor_servicio → proveedor_tarifa)
    // en vez de mockear nada, porque toda la lógica bajo prueba lee esas
    // relaciones reales.
    // ═══════════════════════════════════════════════════════════════

    private function crearProveedorTarifa(float $costo, float $ventaAdulto): ProveedorTarifa
    {
        $destino = DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);
        $servicio = Servicio::create(['nombre' => 'Traslado ida y vuelta']);
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
            'modalidad' => 'compartido',
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
        $destino = DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);
        $guia = Guia::create(['nombre' => $nombreGuia, 'documento' => '12345678', 'telefono' => '999999999']);

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

    private function crearTourSimple(string $nombre, ProveedorTarifa $tarifa, ?float $precioVentaFinal = null): PaquetePlantilla
    {
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);

        $tour = PaquetePlantilla::create([
            'categoria' => 'local',
            'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE,
            'nombre' => $nombre,
            'destino_atractivo_id' => $destino->id,
            'duracion_horas' => 8,
            'precio_venta_final' => $precioVentaFinal,
        ]);

        PaquetePlantillaItem::create([
            'paquete_plantilla_id' => $tour->id,
            'proveedor_tarifa_id' => $tarifa->id,
            'orden' => 1,
        ]);

        return $tour;
    }

    private function crearCombo(string $nombre, array $tours, ?string $descuentoTipo = null, ?float $descuentoValor = null, ?float $margenMinimoPct = null): PaquetePlantilla
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
            'margen_minimo_pct' => $margenMinimoPct,
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

    // ═══════════════════════════════════════════════════════════════
    // Cálculo de precio (punto 3 del diseño)
    // ═══════════════════════════════════════════════════════════════

    public function test_combo_suma_costo_y_venta_de_sus_tours_incluidos_sin_descuento(): void
    {
        $altoMayo = $this->crearTourSimple('Alto Mayo Full Day', $this->crearProveedorTarifa(60, 120));
        $lagunaAzul = $this->crearTourSimple('Laguna Azul Full Day', $this->crearProveedorTarifa(55, 100));

        $combo = $this->crearCombo('Paquete Tarapoto 3D/2N', [$altoMayo, $lagunaAzul]);

        $totales = app(ComboExplosionService::class)->totalesCombo($combo);

        $this->assertSame(115.0, $totales['costo_total_combo']);
        $this->assertSame(220.0, $totales['venta_bruta_combo']);
        $this->assertSame(220.0, $totales['venta_neta_combo']);
        $this->assertSame(0.0, $totales['descuento_aplicado']);
        $this->assertEmpty($totales['componentes_inactivos']);
    }

    public function test_combo_aplica_descuento_porcentaje(): void
    {
        $altoMayo = $this->crearTourSimple('Alto Mayo Full Day', $this->crearProveedorTarifa(60, 120));
        $lagunaAzul = $this->crearTourSimple('Laguna Azul Full Day', $this->crearProveedorTarifa(55, 100));
        $combo = $this->crearCombo('Paquete Tarapoto 3D/2N', [$altoMayo, $lagunaAzul], 'porcentaje', 10);

        $totales = app(ComboExplosionService::class)->totalesCombo($combo);

        $this->assertSame(220.0, $totales['venta_bruta_combo']);
        $this->assertSame(198.0, $totales['venta_neta_combo']);
        $this->assertSame(22.0, $totales['descuento_aplicado']);
    }

    public function test_combo_aplica_descuento_monto_fijo(): void
    {
        $altoMayo = $this->crearTourSimple('Alto Mayo Full Day', $this->crearProveedorTarifa(60, 120));
        $lagunaAzul = $this->crearTourSimple('Laguna Azul Full Day', $this->crearProveedorTarifa(55, 100));
        $combo = $this->crearCombo('Paquete Tarapoto 3D/2N', [$altoMayo, $lagunaAzul], 'monto', 20);

        $totales = app(ComboExplosionService::class)->totalesCombo($combo);

        $this->assertSame(200.0, $totales['venta_neta_combo']);
        $this->assertSame(20.0, $totales['descuento_aplicado']);
    }

    public function test_margen_minimo_rechaza_descuento_que_deja_margen_insuficiente(): void
    {
        // costo 115, venta bruta 220 → margen sin descuento ≈ 91.3%.
        // Con 30% de descuento (venta_neta=154), margen resultante ≈ 33.9%,
        // menor al mínimo configurado (50%) — debe rechazarse.
        $errorMargen = app(ComboValidationService::class)->validarMargenMinimo(115, 154, 50);

        $this->assertNotNull($errorMargen);
        $this->assertStringContainsString('33.9', $errorMargen);
    }

    public function test_margen_minimo_acepta_descuento_dentro_del_piso(): void
    {
        $errorMargen = app(ComboValidationService::class)->validarMargenMinimo(115, 198, 50);

        $this->assertNull($errorMargen);
    }

    // ═══════════════════════════════════════════════════════════════
    // Exclusividad mutua y profundidad (puntos 1-2 del diseño)
    // ═══════════════════════════════════════════════════════════════

    public function test_item_controller_rechaza_dos_referencias_a_la_vez(): void
    {
        $tarifa = $this->crearProveedorTarifa(60, 120);
        $altoMayo = $this->crearTourSimple('Alto Mayo Full Day', $tarifa);
        $lagunaAzul = $this->crearTourSimple('Laguna Azul Full Day', $this->crearProveedorTarifa(55, 100));
        $combo = $this->crearCombo('Paquete Tarapoto 3D/2N', []);

        $request = new Request(['proveedor_tarifa_id' => $tarifa->id, 'paquete_plantilla_hijo_id' => $lagunaAzul->id]);
        $response = app(PaquetePlantillaItemController::class)->store($request, (string) $combo->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('exactamente uno', $response->getData()->message);
    }

    public function test_item_controller_rechaza_incluir_tour_dentro_de_tour_simple(): void
    {
        $altoMayo = $this->crearTourSimple('Alto Mayo Full Day', $this->crearProveedorTarifa(60, 120));
        $lagunaAzul = $this->crearTourSimple('Laguna Azul Full Day', $this->crearProveedorTarifa(55, 100));

        $request = new Request(['paquete_plantilla_hijo_id' => $lagunaAzul->id]);
        $response = app(PaquetePlantillaItemController::class)->store($request, (string) $altoMayo->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('tour simple no puede incluir', $response->getData()->message);
    }

    public function test_item_controller_rechaza_incluir_combo_dentro_de_combo(): void
    {
        $altoMayo = $this->crearTourSimple('Alto Mayo Full Day', $this->crearProveedorTarifa(60, 120));
        $comboA = $this->crearCombo('Combo A', [$altoMayo]);
        $comboB = $this->crearCombo('Combo B', []);

        $request = new Request(['paquete_plantilla_hijo_id' => $comboA->id]);
        $response = app(PaquetePlantillaItemController::class)->store($request, (string) $comboB->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('no otro combo', $response->getData()->message);
    }

    public function test_item_controller_agrega_tour_hijo_a_combo_correctamente(): void
    {
        $altoMayo = $this->crearTourSimple('Alto Mayo Full Day', $this->crearProveedorTarifa(60, 120));
        $combo = $this->crearCombo('Paquete Tarapoto 3D/2N', []);

        $request = new Request(['paquete_plantilla_hijo_id' => $altoMayo->id, 'orden' => 1]);
        $response = app(PaquetePlantillaItemController::class)->store($request, (string) $combo->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('paquete_plantilla_items', [
            'paquete_plantilla_id' => $combo->id,
            'paquete_plantilla_hijo_id' => $altoMayo->id,
        ]);
    }

    public function test_item_controller_rechaza_item_que_deja_margen_insuficiente_y_no_persiste_nada(): void
    {
        $altoMayo = $this->crearTourSimple('Alto Mayo Full Day', $this->crearProveedorTarifa(60, 120));
        $lagunaAzul = $this->crearTourSimple('Laguna Azul Full Day', $this->crearProveedorTarifa(55, 100));
        // 80% de descuento sobre 120 (venta_neta=24) deja margen muy por
        // debajo del costo del primer tour (60) — imposible cumplir 50% mínimo.
        $combo = $this->crearCombo('Paquete Tarapoto 3D/2N', [$altoMayo], 'porcentaje', 80, 50);

        $countAntes = PaquetePlantillaItem::count();

        $request = new Request(['paquete_plantilla_hijo_id' => $lagunaAzul->id, 'orden' => 2]);
        $response = app(PaquetePlantillaItemController::class)->store($request, (string) $combo->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame($countAntes, PaquetePlantillaItem::count());
    }

    // ═══════════════════════════════════════════════════════════════
    // Explosión de ítems (punto 4 del diseño) — sin deduplicar guías
    // ═══════════════════════════════════════════════════════════════

    public function test_explosion_no_deduplica_lineas_de_guia_entre_tours_del_mismo_combo(): void
    {
        $guiaTarifa = $this->crearGuiaTarifa(80, 'Juan Pérez');

        $altoMayo = PaquetePlantilla::create([
            'categoria' => 'local', 'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE,
            'nombre' => 'Alto Mayo Full Day', 'destino_atractivo_id' => DestinoAtractivo::first()->id,
            'duracion_horas' => 8,
        ]);
        PaquetePlantillaItem::create(['paquete_plantilla_id' => $altoMayo->id, 'guia_tarifa_id' => $guiaTarifa->id, 'orden' => 1]);

        $lagunaAzul = PaquetePlantilla::create([
            'categoria' => 'local', 'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE,
            'nombre' => 'Laguna Azul Full Day', 'destino_atractivo_id' => DestinoAtractivo::first()->id,
            'duracion_horas' => 8,
        ]);
        // Mismo guía, tour distinto — a propósito.
        PaquetePlantillaItem::create(['paquete_plantilla_id' => $lagunaAzul->id, 'guia_tarifa_id' => $guiaTarifa->id, 'orden' => 1]);

        $combo = $this->crearCombo('Paquete Tarapoto 3D/2N', [$altoMayo, $lagunaAzul]);

        $items = app(ComboExplosionService::class)->explotarItems($combo);

        $this->assertCount(2, $items);
        $this->assertSame($altoMayo->id, $items[0]['tour_origen_id']);
        $this->assertSame($lagunaAzul->id, $items[1]['tour_origen_id']);
        $this->assertEqualsWithDelta(80 * 1.10, $items[0]['venta'], 0.01);
        $this->assertEqualsWithDelta(80 * 1.10, $items[1]['venta'], 0.01);
    }

    // ═══════════════════════════════════════════════════════════════
    // Itinerario derivado (punto 5 del diseño)
    // ═══════════════════════════════════════════════════════════════

    public function test_itinerario_derivado_desplaza_dia_relativo_por_tour(): void
    {
        $altoMayo = $this->crearTourSimple('Alto Mayo Full Day', $this->crearProveedorTarifa(60, 120));
        TourItinerarioItem::create(['tour_id' => $altoMayo->id, 'dia_relativo' => 1, 'orden' => 1, 'descripcion' => 'Baños termales de San Mateo']);
        TourItinerarioItem::create(['tour_id' => $altoMayo->id, 'dia_relativo' => 1, 'orden' => 2, 'descripcion' => 'Orquideario']);

        $lagunaAzul = $this->crearTourSimple('Laguna Azul Full Day', $this->crearProveedorTarifa(55, 100));
        TourItinerarioItem::create(['tour_id' => $lagunaAzul->id, 'dia_relativo' => 1, 'orden' => 1, 'descripcion' => 'Paseo a caballo']);
        TourItinerarioItem::create(['tour_id' => $lagunaAzul->id, 'dia_relativo' => 1, 'orden' => 2, 'descripcion' => 'Canopy']);

        $combo = $this->crearCombo('Paquete Tarapoto 3D/2N', [$altoMayo, $lagunaAzul]);

        $itinerario = app(ComboExplosionService::class)->itinerarioDerivado($combo);

        $this->assertCount(4, $itinerario);
        $this->assertSame(1, $itinerario[0]['dia_relativo']);
        $this->assertSame(1, $itinerario[1]['dia_relativo']);
        $this->assertSame(2, $itinerario[2]['dia_relativo']); // desplazado: día 1 del tour B → día 2 del combo
        $this->assertSame(2, $itinerario[3]['dia_relativo']);
        $this->assertSame('Paseo a caballo', $itinerario[2]['descripcion']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Desactivación de un tour_simple usado en un combo activo (punto 10)
    // ═══════════════════════════════════════════════════════════════

    public function test_desactivar_tour_simple_usado_en_combo_activo_es_bloqueado(): void
    {
        $altoMayo = $this->crearTourSimple('Alto Mayo Full Day', $this->crearProveedorTarifa(60, 120));
        $lagunaAzul = $this->crearTourSimple('Laguna Azul Full Day', $this->crearProveedorTarifa(55, 100));
        $this->crearCombo('Paquete Tarapoto 3D/2N', [$altoMayo, $lagunaAzul]);

        $payloadBase = [
            'categoria' => $altoMayo->categoria,
            'nombre' => $altoMayo->nombre,
            'destino_atractivo_id' => $altoMayo->destino_atractivo_id,
            'duracion_horas' => $altoMayo->duracion_horas,
            'activo' => false,
        ];

        $response = app(PaquetePlantillaController::class)->update(new Request($payloadBase), (string) $altoMayo->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Paquete Tarapoto 3D/2N', $response->getData()->message);
        $this->assertTrue($altoMayo->fresh()->activo);
    }

    public function test_desactivar_tour_simple_forzado_excluye_su_costo_del_combo(): void
    {
        $altoMayo = $this->crearTourSimple('Alto Mayo Full Day', $this->crearProveedorTarifa(60, 120));
        $lagunaAzul = $this->crearTourSimple('Laguna Azul Full Day', $this->crearProveedorTarifa(55, 100));
        $combo = $this->crearCombo('Paquete Tarapoto 3D/2N', [$altoMayo, $lagunaAzul]);

        $payloadBase = [
            'categoria' => $altoMayo->categoria,
            'nombre' => $altoMayo->nombre,
            'destino_atractivo_id' => $altoMayo->destino_atractivo_id,
            'duracion_horas' => $altoMayo->duracion_horas,
            'activo' => false,
            'forzar_desactivacion' => true,
        ];

        $response = app(PaquetePlantillaController::class)->update(new Request($payloadBase), (string) $altoMayo->id);
        $this->assertSame(200, $response->getStatusCode());

        $totales = app(ComboExplosionService::class)->totalesCombo($combo->fresh());

        // Solo Laguna Azul queda en el cálculo (costo 55, venta 100).
        $this->assertSame(55.0, $totales['costo_total_combo']);
        $this->assertSame(100.0, $totales['venta_neta_combo']);
        $this->assertCount(1, $totales['componentes_inactivos']);
        $this->assertSame('Alto Mayo Full Day', $totales['componentes_inactivos'][0]['nombre']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Guardas adicionales del controller
    // ═══════════════════════════════════════════════════════════════

    public function test_no_se_puede_cambiar_tipo_de_paquete_con_items_cargados(): void
    {
        $altoMayo = $this->crearTourSimple('Alto Mayo Full Day', $this->crearProveedorTarifa(60, 120));

        $response = app(PaquetePlantillaController::class)->update(new Request([
            'categoria' => $altoMayo->categoria,
            'nombre' => $altoMayo->nombre,
            'destino_atractivo_id' => $altoMayo->destino_atractivo_id,
            'duracion_horas' => $altoMayo->duracion_horas,
            'tipo' => PaquetePlantilla::TIPO_PAQUETE_COMBO,
        ]), (string) $altoMayo->id);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_precio_venta_final_es_rechazado_para_paquete_combo(): void
    {
        $response = app(PaquetePlantillaController::class)->store(new Request([
            'categoria' => 'nacional',
            'tipo' => PaquetePlantilla::TIPO_PAQUETE_COMBO,
            'nombre' => 'Paquete Tarapoto 3D/2N',
            'destino_atractivo_id' => DestinoAtractivo::create(['nombre' => 'Tarapoto', 'tipo' => 'zona'])->id,
            'duracion_horas' => 48,
            'precio_venta_final' => 220,
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('precio_venta_final no aplica', $response->getData()->message);
    }

    // ═══════════════════════════════════════════════════════════════
    // hora_salida/hora_retorno — regresión del bug real reportado por el
    // usuario ("The hora salida field must match the format H:i" al editar
    // un tour). Postgres guarda `time` como "HH:MM:SS"; un fix anterior
    // (commit dfcdf92, en una rama sin mergear a esta) solo normalizaba en
    // el punto de carga de form.vue y volvió a romperse. Fix real: el
    // accessor del modelo siempre devuelve "HH:MM" al leer, y el validador
    // tolera "HH:MM:SS" de entrada (lo recorta antes de validar) en vez de
    // rechazarlo — cubre cualquier punto de origen del dato, no solo uno.
    // ═══════════════════════════════════════════════════════════════

    public function test_hora_salida_y_retorno_se_leen_siempre_normalizadas_a_hi(): void
    {
        $destino = DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);

        // Simula lo que Postgres realmente devuelve para una columna `time`
        // (con segundos), sin pasar por el validador del controller.
        $tour = PaquetePlantilla::create([
            'categoria' => 'local',
            'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE,
            'nombre' => 'Alto Mayo Full Day',
            'destino_atractivo_id' => $destino->id,
            'duracion_horas' => 8,
            'hora_salida' => '06:00:00',
            'hora_retorno' => '18:30:00',
        ]);

        $this->assertSame('06:00', $tour->hora_salida);
        $this->assertSame('18:30', $tour->hora_retorno);
        $this->assertSame('06:00', $tour->fresh()->hora_salida);
        $this->assertSame('06:00', $tour->toArray()['hora_salida']);
    }

    public function test_editar_tour_con_hora_salida_recargada_con_segundos_no_falla_422(): void
    {
        $altoMayo = $this->crearTourSimple('Alto Mayo Full Day', $this->crearProveedorTarifa(60, 120));

        // Reproduce exactamente el escenario reportado: el formulario carga
        // el tour (hora_salida ya vendría con segundos si el accessor no
        // existiera) y lo reenvía tal cual sin que el usuario lo toque.
        $response = app(PaquetePlantillaController::class)->update(new Request([
            'categoria' => $altoMayo->categoria,
            'nombre' => $altoMayo->nombre,
            'destino_atractivo_id' => $altoMayo->destino_atractivo_id,
            'duracion_horas' => $altoMayo->duracion_horas,
            'hora_salida' => '06:00:00',
            'hora_retorno' => '18:30:00',
        ]), (string) $altoMayo->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('06:00', $altoMayo->fresh()->hora_salida);
        $this->assertSame('18:30', $altoMayo->fresh()->hora_retorno);
    }

    // ═══════════════════════════════════════════════════════════════
    // tour_origen_id — wiring de la migración/modelo (sin flujo completo de
    // cotización/reserva, que requeriría una cadena de fixtures mucho más
    // grande — cliente, cotización, alternativa — fuera de alcance de esta
    // suite centrada en paquete_combo).
    // ═══════════════════════════════════════════════════════════════

    public function test_alternativa_item_persiste_tour_origen_id(): void
    {
        $altoMayo = $this->crearTourSimple('Alto Mayo Full Day', $this->crearProveedorTarifa(60, 120));

        // Fixture mínima de alternativa: se prueba solo la columna nueva,
        // no el flujo de cotización completo.
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI',
            'n_document' => '12345678',
            'full_name' => 'Cliente Test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST',
            'codigo' => 'TEST-2026-0001',
            'cliente_id' => $clienteId,
            'destino' => 'Tarapoto',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $altId = DB::table('alternativas')->insertGetId([
            'cotizacion_id' => $cotizacionId,
            'nombre' => 'Alternativa 1',
            'moneda_cotizacion' => 'PEN',
            'tipo_cambio_aplicado' => 1,
            'tipo_cambio_origen' => 'dia',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $item = AlternativaItem::create([
            'alternativa_id' => $altId,
            'origen_tipo' => AlternativaItem::ORIGEN_PROVEEDOR,
            'proveedor_tarifa_id' => $altoMayo->items()->first()->proveedor_tarifa_id,
            'tour_origen_id' => $altoMayo->id,
            'modo_precio' => 'tarifa_fija',
            'cantidad' => 1,
            'moneda_costo' => 'PEN',
            'costo_snapshot' => 60,
            'precio_venta_snapshot' => 120,
            'precio_convertido' => 120,
        ]);

        $this->assertSame($altoMayo->id, $item->fresh()->tour_origen_id);
    }
}
