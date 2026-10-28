<?php

namespace Tests\Feature\AgenciaViajes;

use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\DestinoServicioController;
use App\Http\Controllers\AgenciaViajes\PaquetePlantillaController;
use App\Http\Controllers\AgenciaViajes\PaquetePlantillaItemController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\GuiaTarifa;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\OpcionMayoristaTour;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\PaquetePlantillaPrecio;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\Servicio;
use App\Models\AgenciaViajes\TemporadaOcurrencia;
use App\Models\AgenciaViajes\TourItinerarioItem;
use App\Services\AgenciaViajes\DiagnosticoToursService;
use App\Services\AgenciaViajes\TemporadaResolverService;
use App\Services\AgenciaViajes\TourCostoEstimadoService;
use App\Services\HoraPeru;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Fila 13, fase 1a — el tour en el catálogo (plan-tours-venta-simple-costo-real.md
// §3.1, §3.2, §3.9b, §3.12, N15; brief PEGAR-EN-CLAUDE-CODE-13-fase1a-tour-catalogo.md
// §5). Mismo patrón de infraestructura que AjusteRedondeoTest/PaqueteComboTest:
// Postgres real (sistemafe_test_migrations), transacción por test revertida.
class Fila13Fase1aTourCatalogoTest extends TestCase
{
    use ConAccesoATodasLasReservas;

    private DestinoAtractivo $destino;

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

        $this->destino = DestinoAtractivo::create(['nombre' => 'Laguna Azul '.uniqid(), 'tipo' => 'zona']);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════
    // Fixtures
    // ═══════════════════════════════════════════════════════════════

    private function crearDestinoServicio(string $nombre): DestinoServicio
    {
        $servicio = Servicio::create(['nombre' => $nombre.' '.uniqid()]);

        return DestinoServicio::create(['destino_atractivo_id' => $this->destino->id, 'servicio_id' => $servicio->id]);
    }

    private function crearTarifa(string $modalidad, float $costo, float $venta, array $extra = []): ProveedorTarifa
    {
        $destinoServicio = $this->crearDestinoServicio('Servicio');
        $proveedor = Proveedor::create(['razon_social' => 'Proveedor Test '.uniqid(), 'estado' => true]);
        $proveedorServicio = ProveedorServicio::create(['proveedor_id' => $proveedor->id, 'destino_servicio_id' => $destinoServicio->id]);

        return ProveedorTarifa::create($extra + [
            'proveedor_servicio_id' => $proveedorServicio->id,
            'tipo_tarifa' => 'publica',
            'modalidad' => $modalidad,
            'moneda' => 'PEN',
            'precio_costo' => $costo,
            'margen_tipo' => 'porcentaje',
            'margen_valor' => 20,
            'precio_venta_adulto' => $venta,
            'vigente_desde' => HoraPeru::hoyTexto(),
            'tip_afe_igv' => '20',
            'destino_tributario' => 'amazonia',
        ]);
    }

    // Tarifa hermana (mismo servicio del proveedor) para otra temporada.
    private function crearTarifaHermana(ProveedorTarifa $base, float $costo, int $temporadaId): ProveedorTarifa
    {
        $base = $base->fresh();

        return ProveedorTarifa::create(array_merge(
            $base->only($base->getFillable()),
            ['precio_costo' => $costo, 'temporada_id' => $temporadaId]
        ));
    }

    private function crearGuiaTarifa(float $costoDiario): GuiaTarifa
    {
        $guia = Guia::create(['nombre' => 'Guía Test', 'documento' => (string) random_int(10000000, 99999999), 'telefono' => '999999999', 'activo' => true]);

        return GuiaTarifa::create([
            'guia_id' => $guia->id,
            'destino_id' => $this->destino->id,
            'modalidad' => 'dia_local',
            'costo_diario' => $costoDiario,
            'tipo_margen' => 'porcentaje',
            'margen_valor' => 25,
            'moneda' => 'PEN',
            'vigente_desde' => HoraPeru::hoyTexto(),
        ]);
    }

    private function crearTour(array $atributos = []): PaquetePlantilla
    {
        return PaquetePlantilla::create($atributos + [
            'categoria' => 'local',
            'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE,
            'nombre' => 'Laguna Azul Full Day',
            'destino_atractivo_id' => $this->destino->id,
            'duracion_horas' => 10,
        ]);
    }

    private function agregarItem(PaquetePlantilla $tour, array $atributos): PaquetePlantillaItem
    {
        return PaquetePlantillaItem::create($atributos + ['paquete_plantilla_id' => $tour->id]);
    }

    private function diasDeItinerario(PaquetePlantilla $tour, int $dias): void
    {
        for ($dia = 1; $dia <= $dias; $dia++) {
            TourItinerarioItem::create([
                'tour_id' => $tour->id, 'dia_relativo' => $dia, 'orden' => 1,
                'destino_atractivo_id' => $this->destino->id, 'descripcion' => "Día {$dia}",
            ]);
        }
    }

    private function payloadTour(array $extra = []): array
    {
        return $extra + [
            'categoria' => 'local',
            'nombre' => 'Laguna Azul Full Day',
            'destino_atractivo_id' => $this->destino->id,
            'duracion_horas' => 10,
        ];
    }

    private function crearOpcionMayorista(): OpcionMayorista
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10000000, 99999999), 'full_name' => 'Cliente Test Fila 13',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-F13-'.random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Cusco', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa', 'estado' => 'borrador',
            'moneda_cotizacion' => 'USD', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
        $proveedor = Proveedor::create(['razon_social' => 'Mayorista Test '.uniqid(), 'estado' => true]);

        return OpcionMayorista::create([
            'alternativa_id' => $alternativa->id, 'proveedor_id' => $proveedor->id, 'moneda' => 'USD', 'estado' => 'candidata',
        ]);
    }

    private function ocurrencia(int $id, int $temporadaId, string $desde, string $hasta): TemporadaOcurrencia
    {
        $o = new TemporadaOcurrencia(['temporada_id' => $temporadaId, 'anio' => 2026, 'fecha_desde' => $desde, 'fecha_hasta' => $hasta]);
        $o->id = $id;

        return $o;
    }

    // ═══════════════════════════════════════════════════════════════
    // Validaciones por uso (N1, N15)
    // ═══════════════════════════════════════════════════════════════

    public function test_tour_solo_itinerario_no_exige_precio_costo_ni_igv(): void
    {
        $respuesta = app(PaquetePlantillaController::class)->store(new Request($this->payloadTour(['uso' => 'solo_itinerario'])));

        $this->assertSame(200, $respuesta->getStatusCode(), json_encode($respuesta->getData()));
        $this->assertSame('solo_itinerario', $respuesta->getData(true)['paquete_plantilla']['uso']);
    }

    public function test_tour_de_venta_nuevo_exige_precio_adulto(): void
    {
        $sinPrecio = app(PaquetePlantillaController::class)->store(new Request($this->payloadTour()));
        $this->assertSame(422, $sinPrecio->getStatusCode());
        $this->assertStringContainsString('precio adulto', $sinPrecio->getData(true)['message']);

        $conPrecio = app(PaquetePlantillaController::class)->store(new Request($this->payloadTour([
            'precio_venta_final' => 150, 'precio_venta_nino' => 130, 'precio_venta_adulto_ext' => 170,
            'costo_estimado_persona' => 120, 'moneda' => 'PEN', 'tip_afe_igv' => '20', 'destino_tributario' => 'amazonia',
            'modalidad' => 'compartido', 'pax_minimo_salida' => 6,
        ])));
        $this->assertSame(200, $conPrecio->getStatusCode(), json_encode($conPrecio->getData()));
        $tour = $conPrecio->getData(true)['paquete_plantilla'];
        $this->assertSame('venta', $tour['uso']);
        $this->assertSame('130.00', $tour['precio_venta_nino']);
        $this->assertSame(6, $tour['pax_minimo_salida']);
    }

    public function test_igv_del_tour_exige_destino_tributario_y_codigo_valido(): void
    {
        $sinDestino = app(PaquetePlantillaController::class)->store(new Request($this->payloadTour(['precio_venta_final' => 100, 'tip_afe_igv' => '20'])));
        $this->assertSame(422, $sinDestino->getStatusCode());

        $codigoInvalido = app(PaquetePlantillaController::class)->store(new Request($this->payloadTour([
            'precio_venta_final' => 100, 'tip_afe_igv' => '99', 'destino_tributario' => 'amazonia',
        ])));
        $this->assertSame(422, $codigoInvalido->getStatusCode());
    }

    public function test_editar_un_tour_viejo_sin_precio_sigue_permitido(): void
    {
        // Tour de venta heredado sin precio: desactivarlo, editarlo desde
        // TourIncluidoForm (sin mandar precio) o desde detalle.vue (que
        // reenvía el objeto completo, precio null incluido) no se bloquea.
        $tour = $this->crearTour();

        $parcial = app(PaquetePlantillaController::class)->update(new Request($this->payloadTour(['activo' => false])), (string) $tour->id);
        $this->assertSame(200, $parcial->getStatusCode(), json_encode($parcial->getData()));

        $completo = app(PaquetePlantillaController::class)->update(new Request($this->payloadTour(['precio_venta_final' => null])), (string) $tour->id);
        $this->assertSame(200, $completo->getStatusCode(), json_encode($completo->getData()));
    }

    public function test_borrar_el_precio_de_un_tour_de_venta_se_rechaza(): void
    {
        $tour = $this->crearTour(['precio_venta_final' => 120]);

        $respuesta = app(PaquetePlantillaController::class)->update(new Request($this->payloadTour(['precio_venta_final' => null])), (string) $tour->id);

        $this->assertSame(422, $respuesta->getStatusCode());
    }

    public function test_pasar_a_venta_un_tour_solo_itinerario_exige_precio(): void
    {
        $tour = $this->crearTour(['uso' => 'solo_itinerario']);

        $respuesta = app(PaquetePlantillaController::class)->update(new Request($this->payloadTour(['uso' => 'venta'])), (string) $tour->id);

        $this->assertSame(422, $respuesta->getStatusCode());
    }

    public function test_un_combo_no_puede_ser_solo_itinerario(): void
    {
        $respuesta = app(PaquetePlantillaController::class)->store(new Request($this->payloadTour([
            'tipo' => 'paquete_combo', 'uso' => 'solo_itinerario',
        ])));

        $this->assertSame(422, $respuesta->getStatusCode());
    }

    public function test_filtro_por_uso_en_el_listado(): void
    {
        $this->crearTour(['nombre' => 'Solo itinerario F13', 'uso' => 'solo_itinerario']);
        $this->crearTour(['nombre' => 'Venta F13', 'precio_venta_final' => 100]);

        $respuesta = app(PaquetePlantillaController::class)->index(new Request(['uso' => 'solo_itinerario', 'search' => 'F13']));
        $nombres = array_column($respuesta->getData(true)['paquetes_plantilla'], 'nombre');

        $this->assertSame(['Solo itinerario F13'], $nombres);
    }

    // ═══════════════════════════════════════════════════════════════
    // Backfill de `uso` (N15)
    // ═══════════════════════════════════════════════════════════════

    public function test_backfill_de_uso_marca_solo_itinerario_al_vinculado_sin_precio(): void
    {
        $vinculadoSinPrecio = $this->crearTour(['nombre' => 'Arribo a Cusco']);
        $vinculadoConPrecio = $this->crearTour(['nombre' => 'City Tour Cusco', 'precio_venta_final' => 80]);
        $suelto = $this->crearTour(['nombre' => 'Laguna Azul']);

        $opcion = $this->crearOpcionMayorista();
        OpcionMayoristaTour::create(['opcion_mayorista_id' => $opcion->id, 'paquete_plantilla_id' => $vinculadoSinPrecio->id, 'orden' => 1]);
        OpcionMayoristaTour::create(['opcion_mayorista_id' => $opcion->id, 'paquete_plantilla_id' => $vinculadoConPrecio->id, 'orden' => 2]);

        // Postgres permite DDL dentro de la transacción del test: se baja y
        // se vuelve a subir la migración real, y todo se revierte al final.
        $migracion = require database_path('migrations/tenant/verticals/agencia-viajes/2026_10_14_150000_add_precio_costo_uso_to_paquetes_plantilla_table.php');
        $migracion->down();
        $migracion->up();

        $uso = fn (PaquetePlantilla $t) => DB::table('paquetes_plantilla')->where('id', $t->id)->value('uso');
        $this->assertSame('solo_itinerario', $uso($vinculadoSinPrecio));
        $this->assertSame('venta', $uso($vinculadoConPrecio));
        $this->assertSame('venta', $uso($suelto));
    }

    // ═══════════════════════════════════════════════════════════════
    // Calculadora (§3.9b, N4)
    // ═══════════════════════════════════════════════════════════════

    public function test_calculadora_por_persona_por_vehiculo_y_guia_por_dias(): void
    {
        $tour = $this->crearTour(['precio_venta_final' => 150, 'pax_minimo_salida' => 6]);
        $this->diasDeItinerario($tour, 3);

        $almuerzo = $this->agregarItem($tour, ['proveedor_tarifa_id' => $this->crearTarifa('compartido', 25, 32)->id, 'orden' => 1]);
        $minivan = $this->agregarItem($tour, ['proveedor_tarifa_id' => $this->crearTarifa('privado', 300, 360)->id, 'orden' => 2]);
        $guia = $this->agregarItem($tour, ['guia_tarifa_id' => $this->crearGuiaTarifa(120)->id, 'orden' => 3]);

        $calculo = app(TourCostoEstimadoService::class)->calcular($tour);
        $porItem = collect($calculo['items'])->keyBy('item_id');

        $this->assertSame(3, $calculo['dias']);
        $this->assertEqualsWithDelta(25.0, $porItem[$almuerzo->id]['costo_sugerido'], 0.001);
        $this->assertEqualsWithDelta(50.0, $porItem[$minivan->id]['costo_sugerido'], 0.001); // 300 ÷ 6
        $this->assertEqualsWithDelta(60.0, $porItem[$guia->id]['costo_sugerido'], 0.001); // 120 × 3 días ÷ 6
        $this->assertEqualsWithDelta(135.0, $calculo['total'], 0.001);
    }

    public function test_calculadora_con_temporada_usa_la_tarifa_de_esa_temporada(): void
    {
        $tour = $this->crearTour(['precio_venta_final' => 150, 'pax_minimo_salida' => 6]);
        $base = $this->crearTarifa('compartido', 25, 32);
        $deTemporada = $this->crearTarifaHermana($base, 35, 9001);
        $item = $this->agregarItem($tour, ['proveedor_tarifa_id' => $base->id]);

        $sinTemporada = app(TourCostoEstimadoService::class)->calcular($tour);
        $conTemporada = app(TourCostoEstimadoService::class)->calcular($tour, 9001);
        $otraTemporada = app(TourCostoEstimadoService::class)->calcular($tour, 9002);

        $this->assertEqualsWithDelta(25.0, $sinTemporada['items'][0]['costo_sugerido'], 0.001);
        $this->assertEqualsWithDelta(35.0, $conTemporada['items'][0]['costo_sugerido'], 0.001);
        $this->assertTrue($conTemporada['items'][0]['de_temporada']);
        $this->assertSame($deTemporada->id, $conTemporada['items'][0]['tarifa_usada_id']);
        // Sin tarifa propia de esa temporada, la de referencia.
        $this->assertEqualsWithDelta(25.0, $otraTemporada['items'][0]['costo_sugerido'], 0.001);
        $this->assertSame($item->id, $otraTemporada['items'][0]['item_id']);
    }

    public function test_calculadora_no_inventa_numeros(): void
    {
        $tour = $this->crearTour(['precio_venta_final' => 150]); // sin mínimo para salir

        $sinTarifa = $this->agregarItem($tour, ['destino_servicio_id' => $this->crearDestinoServicio('Entrada')->id, 'orden' => 1]);
        $privadoSinMinimo = $this->agregarItem($tour, ['proveedor_tarifa_id' => $this->crearTarifa('privado', 300, 360)->id, 'orden' => 2]);
        $hotel = $this->agregarItem($tour, ['proveedor_tarifa_id' => $this->crearTarifa('privado', 200, 250, ['tipo_habitacion' => 'doble'])->id, 'orden' => 3]);
        $enDolares = $this->agregarItem($tour, ['proveedor_tarifa_id' => $this->crearTarifa('compartido', 10, 15, ['moneda' => 'USD'])->id, 'orden' => 4]);

        $calculo = app(TourCostoEstimadoService::class)->calcular($tour);
        $porItem = collect($calculo['items'])->keyBy('item_id');

        foreach ([$sinTarifa, $privadoSinMinimo, $hotel, $enDolares] as $item) {
            $this->assertNull($porItem[$item->id]['costo_sugerido']);
            $this->assertNotNull($porItem[$item->id]['motivo']);
        }
        $this->assertNull($calculo['total']);
    }

    public function test_calculadora_no_guarda_nada(): void
    {
        $tour = $this->crearTour(['precio_venta_final' => 150, 'pax_minimo_salida' => 6]);
        $item = $this->agregarItem($tour, ['proveedor_tarifa_id' => $this->crearTarifa('compartido', 25, 32)->id]);

        $respuesta = app(PaquetePlantillaController::class)->calculadoraCostos(new Request(), (string) $tour->id);

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertNull($item->fresh()->costo_estimado_persona);
    }

    // ═══════════════════════════════════════════════════════════════
    // Costo del tour (§3.1)
    // ═══════════════════════════════════════════════════════════════

    public function test_costo_del_tour_es_la_suma_del_incluye_si_todos_tienen_costo(): void
    {
        $tour = $this->crearTour(['precio_venta_final' => 150, 'costo_estimado_persona' => 999]);
        $this->agregarItem($tour, ['destino_servicio_id' => $this->crearDestinoServicio('Almuerzo')->id, 'costo_estimado_persona' => 25]);
        $this->agregarItem($tour, ['destino_servicio_id' => $this->crearDestinoServicio('Entrada')->id, 'costo_estimado_persona' => 10, 'costo_estimado_persona_ext' => 30]);

        $costo = app(TourCostoEstimadoService::class)->costoDelTour($tour->fresh());

        $this->assertSame('incluye', $costo['origen']);
        $this->assertEqualsWithDelta(35.0, $costo['costo'], 0.001);
        $this->assertEqualsWithDelta(55.0, $costo['costo_ext'], 0.001); // 25 (ext vacío = nacional) + 30
    }

    public function test_costo_del_tour_con_un_item_sin_costo_usa_el_escrito_a_mano(): void
    {
        $tour = $this->crearTour(['precio_venta_final' => 150, 'costo_estimado_persona' => 120]);
        $this->agregarItem($tour, ['destino_servicio_id' => $this->crearDestinoServicio('Almuerzo')->id, 'costo_estimado_persona' => 25]);
        $this->agregarItem($tour, ['destino_servicio_id' => $this->crearDestinoServicio('Bote')->id]);

        $costo = app(TourCostoEstimadoService::class)->costoDelTour($tour->fresh());

        $this->assertSame('manual', $costo['origen']);
        $this->assertEqualsWithDelta(120.0, $costo['costo'], 0.001);
        $this->assertSame(1, $costo['items_sin_costo']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Resolución de temporada (§3.12)
    // ═══════════════════════════════════════════════════════════════

    public function test_resolucion_de_temporada(): void
    {
        $resolver = app(TemporadaResolverService::class);
        $alta = $this->ocurrencia(1, 10, '2026-07-01', '2026-08-31');
        $fiestasPatrias = $this->ocurrencia(2, 20, '2026-07-25', '2026-07-31');
        $ocurrencias = [$alta, $fiestasPatrias];

        $this->assertSame(10, $resolver->elegir($ocurrencias, '2026-07-10')->temporada_id); // dentro de una
        $this->assertNull($resolver->elegir($ocurrencias, '2026-09-15')); // fuera de todas
        $this->assertSame(20, $resolver->elegir($ocurrencias, '2026-07-28')->temporada_id); // superpuestas: gana la más corta
        $this->assertSame(10, $resolver->elegir($ocurrencias, '2026-07-01')->temporada_id); // borde de inicio
        $this->assertSame(10, $resolver->elegir($ocurrencias, '2026-08-31')->temporada_id); // borde de fin
        $this->assertSame(20, $resolver->elegir($ocurrencias, '2026-07-31')->temporada_id); // fin de la corta
        $this->assertSame(10, $resolver->elegir($ocurrencias, '2026-08-01')->temporada_id); // día siguiente a la corta
    }

    public function test_resolucion_de_temporada_empate_de_duracion_desempata_por_id(): void
    {
        $resolver = app(TemporadaResolverService::class);
        $a = $this->ocurrencia(7, 70, '2026-12-20', '2026-12-31');
        $b = $this->ocurrencia(3, 30, '2026-12-22', '2027-01-02');

        $this->assertSame(30, $resolver->elegir([$a, $b], '2026-12-25')->temporada_id);
    }

    // ═══════════════════════════════════════════════════════════════
    // Incluye: servicio sin tarifa, actualizar, precios por temporada
    // ═══════════════════════════════════════════════════════════════

    public function test_incluye_admite_servicio_sin_tarifa_solo_en_tour_simple(): void
    {
        $tour = $this->crearTour(['precio_venta_final' => 150]);
        $entrada = $this->crearDestinoServicio('Entrada');

        $ok = app(PaquetePlantillaItemController::class)->store(new Request([
            'destino_servicio_id' => $entrada->id, 'costo_estimado_persona' => 10, 'tip_afe_igv' => '20', 'destino_tributario' => 'amazonia',
        ]), (string) $tour->id);
        $this->assertSame(200, $ok->getStatusCode(), json_encode($ok->getData()));

        $duplicado = app(PaquetePlantillaItemController::class)->store(new Request(['destino_servicio_id' => $entrada->id]), (string) $tour->id);
        $this->assertSame(422, $duplicado->getStatusCode());

        $combo = $this->crearTour(['tipo' => PaquetePlantilla::TIPO_PAQUETE_COMBO, 'nombre' => 'Combo F13']);
        $enCombo = app(PaquetePlantillaItemController::class)->store(new Request(['destino_servicio_id' => $entrada->id]), (string) $combo->id);
        $this->assertSame(422, $enCombo->getStatusCode());
    }

    public function test_item_con_tarifa_toma_servicio_y_proveedor_de_la_tarifa(): void
    {
        $tour = $this->crearTour(['precio_venta_final' => 150]);
        $tarifa = $this->crearTarifa('compartido', 25, 32);

        $respuesta = app(PaquetePlantillaItemController::class)->store(new Request(['proveedor_tarifa_id' => $tarifa->id]), (string) $tour->id);
        $item = PaquetePlantillaItem::find($respuesta->getData(true)['paquete_plantilla_item']['id']);

        $this->assertSame($tarifa->proveedorServicio->destino_servicio_id, $item->destino_servicio_id);
        $this->assertSame($tarifa->proveedorServicio->proveedor_id, $item->proveedor_id);
    }

    public function test_actualizar_costo_e_igv_de_un_item(): void
    {
        $tour = $this->crearTour(['precio_venta_final' => 150]);
        $item = $this->agregarItem($tour, ['proveedor_tarifa_id' => $this->crearTarifa('compartido', 25, 32)->id]);

        $respuesta = app(PaquetePlantillaItemController::class)->update(new Request([
            'costo_estimado_persona' => 28, 'costo_estimado_persona_ext' => 30, 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ]), (string) $item->id);

        $this->assertSame(200, $respuesta->getStatusCode());
        $item->refresh();
        $this->assertSame('28.00', $item->costo_estimado_persona);
        $this->assertSame('10', $item->tip_afe_igv);
        // La tarifa de referencia no cambia por esta vía.
        $this->assertNotNull($item->proveedor_tarifa_id);
    }

    public function test_precios_por_temporada_agregar_editar_y_quitar(): void
    {
        $temporada = \App\Models\AgenciaViajes\Temporada::where('giro', 'agencia_viajes')->first();
        if (! $temporada) {
            $this->markTestSkipped('No hay temporadas de agencia en la base central.');
        }

        $tour = $this->crearTour(['precio_venta_final' => 150]);
        $controller = app(PaquetePlantillaController::class);

        $creado = $controller->guardarPrecio(new Request(['temporada_id' => $temporada->id, 'precio_venta_adulto' => 180]), (string) $tour->id);
        $this->assertSame(200, $creado->getStatusCode(), json_encode($creado->getData()));
        $precioId = $creado->getData(true)['precio']['id'];

        $repetido = $controller->guardarPrecio(new Request(['temporada_id' => $temporada->id, 'precio_venta_adulto' => 190]), (string) $tour->id);
        $this->assertSame(422, $repetido->getStatusCode());

        $editado = $controller->guardarPrecio(new Request(['temporada_id' => $temporada->id, 'precio_venta_adulto' => 200, 'precio_venta_nino' => 170]), (string) $tour->id, (string) $precioId);
        $this->assertSame(200, $editado->getStatusCode());
        $this->assertSame('200.00', PaquetePlantillaPrecio::find($precioId)->precio_venta_adulto);

        $inexistente = $controller->guardarPrecio(new Request(['temporada_id' => 999999, 'precio_venta_adulto' => 100]), (string) $tour->id);
        $this->assertSame(422, $inexistente->getStatusCode());

        $controller->eliminarPrecio((string) $tour->id, (string) $precioId);
        $this->assertNull(PaquetePlantillaPrecio::find($precioId));
    }

    public function test_duplicar_copia_campos_nuevos_e_incluye_y_precios(): void
    {
        $tour = $this->crearTour(['precio_venta_final' => 150, 'precio_venta_nino' => 130, 'pax_minimo_salida' => 6, 'uso' => 'venta']);
        $this->agregarItem($tour, ['destino_servicio_id' => $this->crearDestinoServicio('Entrada')->id, 'costo_estimado_persona' => 10, 'tip_afe_igv' => '20', 'destino_tributario' => 'amazonia']);
        PaquetePlantillaPrecio::create(['paquete_plantilla_id' => $tour->id, 'temporada_id' => 9001, 'precio_venta_adulto' => 180]);

        $respuesta = app(PaquetePlantillaController::class)->duplicar((string) $tour->id);
        $copia = PaquetePlantilla::with(['items', 'precios'])->find($respuesta->getData(true)['paquete_plantilla']['id']);

        $this->assertSame('130.00', $copia->precio_venta_nino);
        $this->assertSame(6, $copia->pax_minimo_salida);
        $this->assertSame('10.00', $copia->items->first()->costo_estimado_persona);
        $this->assertSame('20', $copia->items->first()->tip_afe_igv);
        $this->assertCount(1, $copia->precios);
        $this->assertCount(1, $tour->precios()->get(), 'El original conserva su precio.');
    }

    // ═══════════════════════════════════════════════════════════════
    // Choque con DestinoServicioController (hallazgo de la sesión)
    // ═══════════════════════════════════════════════════════════════

    public function test_fusionar_servicio_lleva_el_incluye_y_quitarlo_se_bloquea(): void
    {
        $tour = $this->crearTour(['precio_venta_final' => 150]);
        $origen = $this->crearDestinoServicio('Entrada');
        $otroDestino = DestinoAtractivo::create(['nombre' => 'Otro destino '.uniqid(), 'tipo' => 'zona']);
        $destino = DestinoServicio::create(['destino_atractivo_id' => $otroDestino->id, 'servicio_id' => $origen->servicio_id]);
        $item = $this->agregarItem($tour, ['destino_servicio_id' => $origen->id]);

        $quitar = app(DestinoServicioController::class)->destroy((string) $origen->id);
        $this->assertSame(422, $quitar->getStatusCode());

        $fusion = app(DestinoServicioController::class)->fusionar(new Request(['destino_servicio_destino_id' => $destino->id]), (string) $origen->id);
        $this->assertSame(200, $fusion->getStatusCode(), json_encode($fusion->getData()));
        $this->assertSame($destino->id, $item->fresh()->destino_servicio_id);
    }

    // ═══════════════════════════════════════════════════════════════
    // Diagnóstico: solo lectura
    // ═══════════════════════════════════════════════════════════════

    public function test_diagnostico_no_escribe_nada(): void
    {
        $tour = $this->crearTour(['precio_venta_final' => 150, 'pax_minimo_salida' => 6]);
        $this->agregarItem($tour, ['proveedor_tarifa_id' => $this->crearTarifa('compartido', 25, 32)->id]);
        $this->agregarItem($tour, ['proveedor_tarifa_id' => $this->crearTarifa('privado', 300, 360, ['tip_afe_igv' => '10', 'destino_tributario' => 'nacional'])->id]);
        $vinculado = $this->crearTour(['nombre' => 'Arribo a Cusco', 'uso' => 'solo_itinerario']);
        OpcionMayoristaTour::create(['opcion_mayorista_id' => $this->crearOpcionMayorista()->id, 'paquete_plantilla_id' => $vinculado->id, 'orden' => 1]);

        $huella = fn () => collect(['paquetes_plantilla', 'paquete_plantilla_items', 'paquete_plantilla_precios', 'proveedor_tarifas', 'opcion_mayorista_tours'])
            ->mapWithKeys(fn ($t) => [$t => DB::selectOne("select count(*) n, md5(coalesce(string_agg(x::text, '|' order by x.id), '')) h from {$t} x")])
            ->toJson();

        $antes = $huella();
        $filas = collect(app(DiagnosticoToursService::class)->diagnosticar())->keyBy('id');
        $this->assertSame($antes, $huella());

        $this->assertTrue($filas[$tour->id]['igv_mezclado']);
        $this->assertTrue($filas[$tour->id]['tiene_privados']);
        $this->assertEqualsWithDelta(75.0, $filas[$tour->id]['costo_propuesto'], 0.001); // 25 + 300 ÷ 6
        $this->assertSame('solo_itinerario', $filas[$vinculado->id]['uso_propuesto']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Regresión: desdePlantilla() no cambia en esta fase
    // ═══════════════════════════════════════════════════════════════

    public function test_desde_plantilla_da_el_mismo_resultado_que_antes(): void
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '44556677', 'full_name' => 'Cliente Regresión F13',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-F13-REG', 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([['adulto', 30], ['adulto', 28], ['nino', 8], ['infante', 2]] as [$tipo, $edad]) {
            CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => $tipo, 'edad' => $edad]);
        }
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);

        // Tour con TODO lo nuevo cargado: precio por persona, costos, IGV,
        // mínimo, precio por temporada y un servicio sin tarifa.
        $tour = $this->crearTour([
            'precio_venta_final' => 150, 'precio_venta_nino' => 130, 'costo_estimado_persona' => 120,
            'tip_afe_igv' => '20', 'destino_tributario' => 'amazonia', 'pax_minimo_salida' => 6,
        ]);
        $this->diasDeItinerario($tour, 2);
        $this->agregarItem($tour, ['proveedor_tarifa_id' => $this->crearTarifa('compartido', 20, 50, ['precio_venta_nino' => 30, 'precio_venta_infante' => 0])->id, 'orden' => 1, 'costo_estimado_persona' => 99]);
        $this->agregarItem($tour, ['proveedor_tarifa_id' => $this->crearTarifa('privado', 100, 180)->id, 'orden' => 2, 'costo_estimado_persona' => 99]);
        $this->agregarItem($tour, ['guia_tarifa_id' => $this->crearGuiaTarifa(100)->id, 'orden' => 3]);
        $this->agregarItem($tour, ['destino_servicio_id' => $this->crearDestinoServicio('Entrada')->id, 'orden' => 4, 'costo_estimado_persona' => 10]);
        PaquetePlantillaPrecio::create(['paquete_plantilla_id' => $tour->id, 'temporada_id' => 9001, 'precio_venta_adulto' => 999]);

        $respuesta = app(AlternativaItemController::class)->desdePlantilla(
            new Request(['paquete_plantilla_id' => $tour->id, 'dia_referencial' => 1]),
            (string) $alternativa->id
        );

        $this->assertSame(200, $respuesta->getStatusCode());
        $items = collect($respuesta->getData(true)['items_agregados']);

        // Mismo resultado del modo clásico: 3 líneas (el servicio sin tarifa
        // se salta), precios desde las tarifas, nunca desde el tour.
        $this->assertCount(3, $items);
        $compartido = $items[0];
        $this->assertSame('por_persona', $compartido['modo_precio']);
        $this->assertEqualsWithDelta(130.0, (float) $compartido['precio_venta_snapshot'], 0.01); // 2×50 + 30 + 0
        $this->assertEqualsWithDelta(80.0, (float) $compartido['costo_snapshot'], 0.01); // 4×20
        $privado = $items[1];
        $this->assertSame('tarifa_fija', $privado['modo_precio']);
        $this->assertEqualsWithDelta(180.0, (float) $privado['precio_venta_snapshot'], 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $privado['costo_snapshot'], 0.01);
        $guia = $items[2];
        // Guía contado como 1 día aunque el tour dure 2 (E5, sin corregir en
        // esta fase): costo 100, venta 100 + 25 %.
        $this->assertEqualsWithDelta(100.0, (float) $guia['costo_snapshot'], 0.01);
        $this->assertEqualsWithDelta(125.0, (float) $guia['precio_venta_snapshot'], 0.01);
    }
}
