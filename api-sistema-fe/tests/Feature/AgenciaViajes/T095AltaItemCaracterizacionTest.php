<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\GuiaTarifa;
use App\Models\AgenciaViajes\OpcionHotel;
use App\Models\AgenciaViajes\OpcionHotelTarifa;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\OpcionMayoristaOpcional;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorAlojamientoDetalle;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\Servicio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// T-095 — test de caracterización del alta de ítems (POST alternativas/{id}/items), escrito contra
// el código de ANTES del refactor y que no se vuelve a editar. Por cada origen_tipo fija la
// respuesta JSON completa, la fila creada (todas sus columnas), la fila de cotizacion_pasaje_aereo
// y el total de la alternativa, comparados contra un archivo "dorado" en fixtures/T095/.
// Los ids y las marcas de tiempo se normalizan (cambian en cada corrida).
// Para regrabar los dorados (solo si cambia a propósito lo que devuelve el alta): T095_GRABAR=1.
class T095AltaItemCaracterizacionTest extends TestCase
{
    use ConAccesoATodasLasReservas;

    private const UUID_GRUPO = '5f0e5a52-7c3b-4c55-9b1f-0d2f3c4a5b6c';

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

    // ── Fixtures ───────────────────────────────────────────────────────

    /** 2 adultos (30, 28), 1 niño de 8 y 1 infante de 2. Devuelve [alternativa, [ids de pasajeros]]. */
    private function alternativa(string $moneda = 'PEN'): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10000000, 99999999), 'full_name' => 'Cliente T095',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-T095-'.uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $pax = [];
        foreach ([['adulto', 30], ['adulto', 28], ['nino', 8], ['infante', 2]] as [$tipo, $edad]) {
            $pax[] = CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => $tipo, 'edad' => $edad])->id;
        }
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa T095', 'estado' => 'borrador',
            'moneda_cotizacion' => $moneda, 'tipo_cambio_aplicado' => 3.75, 'tipo_cambio_origen' => 'dia',
        ]);

        return [$alternativa, $pax];
    }

    private function tarifaProveedor(string $modalidad, bool $conAlojamiento = false): ProveedorTarifa
    {
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);
        $servicio = Servicio::create(['nombre' => 'Servicio T095']);
        $destinoServicio = DestinoServicio::create(['destino_atractivo_id' => $destino->id, 'servicio_id' => $servicio->id]);
        $proveedor = Proveedor::create(['razon_social' => 'Proveedor T095 SAC', 'estado' => true]);
        if ($conAlojamiento) {
            ProveedorAlojamientoDetalle::create([
                'proveedor_id' => $proveedor->id, 'edad_max_infante_gratis' => 4, 'edad_max_nino_cama_adicional' => 12,
            ]);
        }
        $proveedorServicio = ProveedorServicio::create(['proveedor_id' => $proveedor->id, 'destino_servicio_id' => $destinoServicio->id]);

        return ProveedorTarifa::create([
            'proveedor_servicio_id' => $proveedorServicio->id,
            'tipo_tarifa' => 'publica',
            'modalidad' => $modalidad,
            'moneda' => 'PEN',
            'precio_costo' => 100,
            'margen_tipo' => 'porcentaje',
            'margen_valor' => 20,
            'precio_venta_adulto' => 150,
            'precio_venta_nino' => 90,
            'precio_venta_infante' => 30,
            'precio_costo_cama_adicional' => $conAlojamiento ? 15 : null,
            'precio_venta_cama_adicional' => $conAlojamiento ? 30 : null,
            'vigente_desde' => '2026-01-01',
            'tip_afe_igv' => '20',
            'destino_tributario' => 'amazonia',
        ]);
    }

    private function mayoristaElegida(Alternativa $alternativa, string $estado = 'elegida'): OpcionMayorista
    {
        $proveedor = Proveedor::create(['razon_social' => 'Mayorista T095 SAC']);

        return OpcionMayorista::create([
            'alternativa_id' => $alternativa->id, 'proveedor_id' => $proveedor->id, 'moneda' => 'USD', 'estado' => $estado,
        ]);
    }

    private function tarifaHotel(?OpcionMayorista $opcion, string $moneda = 'USD'): OpcionHotelTarifa
    {
        $hotel = OpcionHotel::create(['opcion_mayorista_id' => $opcion?->id, 'nombre_hotel' => 'Hotel T095', 'moneda' => $moneda]);

        return OpcionHotelTarifa::create([
            'opcion_hotel_id' => $hotel->id, 'tipo_habitacion' => 'doble', 'precio_costo' => 80, 'precio_venta' => 120,
            'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ]);
    }

    private function tarifaGuia(): GuiaTarifa
    {
        $destino = DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);
        $guia = Guia::create(['nombre' => 'Guía T095', 'documento' => '12345678', 'telefono' => '999999999', 'activo' => true]);

        return GuiaTarifa::create([
            'guia_id' => $guia->id, 'destino_id' => $destino->id, 'modalidad' => 'dia_local', 'costo_diario' => 100,
            'tipo_margen' => 'porcentaje', 'margen_valor' => 20, 'moneda' => 'PEN', 'vigente_desde' => '2026-01-01',
        ]);
    }

    // ── Captura y comparación ──────────────────────────────────────────

    /** Ids (clave `id`, `*_id`, `pax_incluidos`) y marcas de tiempo se reemplazan por un marcador. */
    private function normalizar(mixed $valor, ?string $clave = null): mixed
    {
        if (is_array($valor)) {
            $salida = [];
            foreach ($valor as $k => $v) {
                $salida[$k] = $this->normalizar($v, is_string($k) ? $k : $clave);
            }

            return $salida;
        }
        if ($valor === null) {
            return null;
        }
        if ($clave === 'pax_incluidos' && is_string($valor)) {
            // La fila cruda trae el JSON como texto: se conserva la cantidad de ids.
            return '<'.count(json_decode($valor, true)).' ids>';
        }
        if ($clave === 'id' || $clave === 'pax_incluidos' || str_ends_with((string) $clave, '_id')) {
            return is_int($valor) ? '<id>' : $valor;
        }
        if ($clave === 'fecha_cotizado' || str_ends_with((string) $clave, '_at')) {
            return '<ts>';
        }

        return $valor;
    }

    private function aArray(mixed $datos): array
    {
        return json_decode(json_encode($datos), true);
    }

    private function compararConDorado(string $caso, array $captura): void
    {
        $ruta = __DIR__."/fixtures/T095/{$caso}.json";
        $captura = $this->normalizar($this->aArray($captura));

        if (getenv('T095_GRABAR') === '1') {
            file_put_contents($ruta, json_encode($captura, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        }

        $this->assertFileExists($ruta, "Falta el dorado {$caso}: corre con T095_GRABAR=1 sobre el código original.");
        $this->assertSame(
            json_decode(file_get_contents($ruta), true),
            $captura,
            "La respuesta del alta «{$caso}» cambió respecto al dorado."
        );
    }

    /** Llama a store() y captura respuesta + fila creada + pasaje aéreo + total. */
    private function altaFeliz(string $caso, Alternativa $alternativa, array $datos): void
    {
        $res = app(AlternativaItemController::class)->store(new Request($datos), (string) $alternativa->id);
        $cuerpo = $res->getData(true);
        $this->assertSame(200, $res->getStatusCode(), json_encode($cuerpo));

        $itemId = $cuerpo['alternativa_item']['id'];
        $this->compararConDorado($caso, [
            'http' => $res->getStatusCode(),
            'respuesta' => $cuerpo,
            'fila' => DB::table('alternativa_items')->where('id', $itemId)->first(),
            'pasaje_aereo' => DB::table('cotizacion_pasaje_aereo')->where('alternativa_item_id', $itemId)->first(),
            'total' => DB::table('alternativas')->where('id', $alternativa->id)->value('total'),
            'items_de_la_alternativa' => DB::table('alternativa_items')->where('alternativa_id', $alternativa->id)->count(),
        ]);
    }

    private function altaRechazada(string $caso, Alternativa $alternativa, array $datos, ?string $mensajeExacto = null): void
    {
        $res = app(AlternativaItemController::class)->store(new Request($datos), (string) $alternativa->id);
        $cuerpo = $res->getData(true);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame(422, $cuerpo['code']);
        if ($mensajeExacto !== null) {
            $this->assertSame($mensajeExacto, $cuerpo['message']);
        }
        $this->compararConDorado($caso, [
            'http' => $res->getStatusCode(),
            'respuesta' => $cuerpo,
            'items_de_la_alternativa' => DB::table('alternativa_items')->where('alternativa_id', $alternativa->id)->count(),
            'total' => DB::table('alternativas')->where('id', $alternativa->id)->value('total'),
        ]);
    }

    // ── Altas felices, una por fuente de precio ────────────────────────

    public function test_proveedor_tarifa_por_persona(): void
    {
        [$alternativa, $pax] = $this->alternativa();
        $tarifa = $this->tarifaProveedor('compartido');

        $this->altaFeliz('proveedor_por_persona', $alternativa, [
            'origen_tipo' => 'proveedor', 'proveedor_tarifa_id' => $tarifa->id, 'modo_precio' => 'por_persona',
            'pax_incluidos' => [$pax[0], $pax[2], $pax[3]], 'cantidad' => 2, 'dia_referencial' => 2,
            'grupo_opcion_id' => self::UUID_GRUPO,
        ]);
    }

    public function test_proveedor_tarifa_fija_con_cama_adicional(): void
    {
        [$alternativa, $pax] = $this->alternativa();
        $tarifa = $this->tarifaProveedor('privado', conAlojamiento: true);

        $this->altaFeliz('proveedor_tarifa_fija_cama_adicional', $alternativa, [
            'origen_tipo' => 'proveedor', 'proveedor_tarifa_id' => $tarifa->id, 'modo_precio' => 'tarifa_fija',
            'pax_incluidos' => [$pax[2]], 'camas_adicionales_nino' => 2,
        ]);
    }

    public function test_proveedor_hotel_adhoc_local(): void
    {
        [$alternativa] = $this->alternativa();
        $tarifa = $this->tarifaHotel(null, 'PEN');

        $this->altaFeliz('proveedor_hotel_adhoc', $alternativa, [
            'origen_tipo' => 'proveedor', 'opcion_hotel_tarifa_id' => $tarifa->id, 'modo_precio' => 'por_persona', 'cantidad' => 3,
        ]);
    }

    public function test_proveedor_precio_de_referencia_sin_tributario_cae_al_default(): void
    {
        [$alternativa] = $this->alternativa();

        $this->altaFeliz('proveedor_precio_referencia', $alternativa, [
            'origen_tipo' => 'proveedor', 'modo_precio' => 'tarifa_fija', 'moneda_costo' => 'USD',
            'precio_venta_snapshot' => 50, 'costo_snapshot' => 30,
        ]);
    }

    public function test_mayorista_con_habitacion(): void
    {
        [$alternativa, $pax] = $this->alternativa();
        $opcion = $this->mayoristaElegida($alternativa);
        $tarifa = $this->tarifaHotel($opcion);

        $this->altaFeliz('mayorista_habitacion', $alternativa, [
            'origen_tipo' => 'mayorista', 'opcion_mayorista_id' => $opcion->id, 'opcion_hotel_tarifa_id' => $tarifa->id,
            'cantidad' => 2, 'pax_incluidos' => [$pax[0], $pax[1]], 'grupo_opcion_id' => self::UUID_GRUPO,
        ]);
    }

    public function test_mayorista_con_opcional(): void
    {
        [$alternativa] = $this->alternativa('USD');
        $opcion = $this->mayoristaElegida($alternativa);
        $opcional = OpcionMayoristaOpcional::create([
            'opcion_mayorista_id' => $opcion->id, 'nombre' => 'Excursión T095', 'precio_por_persona' => 170, 'moneda' => 'USD',
        ]);

        $this->altaFeliz('mayorista_opcional', $alternativa, [
            'origen_tipo' => 'mayorista', 'opcion_mayorista_id' => $opcion->id, 'opcion_mayorista_opcional_id' => $opcional->id,
            'tip_afe_igv' => '30', 'destino_tributario' => 'extranjero',
        ]);
    }

    public function test_guia(): void
    {
        [$alternativa, $pax] = $this->alternativa();
        $tarifa = $this->tarifaGuia();

        $this->altaFeliz('guia', $alternativa, [
            'origen_tipo' => 'guia', 'guia_tarifa_id' => $tarifa->id, 'cantidad' => 2, 'pax_incluidos' => [$pax[0]],
        ]);
    }

    public function test_pasaje_aereo(): void
    {
        [$alternativa] = $this->alternativa();

        $this->altaFeliz('pasaje_aereo', $alternativa, [
            'origen_tipo' => 'pasaje_aereo', 'aerolinea' => 'latam', 'itinerario' => 'LIMA - TARAPOTO', 'moneda' => 'USD',
            'tarifa_base_adulto' => 100, 'tarifa_base_nino' => 80, 'tarifa_base_infante' => 20,
            'cargos' => [['nombre' => 'TUA', 'monto' => 10, 'tipo' => 'tasa_aeropuerto'], ['nombre' => 'IGV', 'monto' => 5, 'no_aplica_infante' => true]],
            'fee_agencia_monto' => 12, 'dia_referencial' => 1, 'tarifa_valida_hasta' => '2026-12-31 18:30',
        ]);
    }

    public function test_manual_en_usd_sobre_alternativa_en_pen(): void
    {
        [$alternativa, $pax] = $this->alternativa('PEN');

        $this->altaFeliz('manual_usd_en_pen', $alternativa, [
            'origen_tipo' => 'manual', 'descripcion_manual' => 'Entradas', 'proveedor_sugerido_manual' => 'Proveedor sugerido',
            'costo_snapshot' => 10, 'precio_venta_snapshot' => 20, 'moneda_costo' => 'USD', 'cantidad' => 3,
            'pax_incluidos' => [$pax[0], $pax[1]], 'dia_referencial' => 1,
        ]);
    }

    // ── Rechazos ───────────────────────────────────────────────────────

    public function test_mayorista_de_otra_alternativa_se_rechaza(): void
    {
        [$alternativa] = $this->alternativa();
        [$otra] = $this->alternativa();
        $opcion = $this->mayoristaElegida($otra);
        $tarifa = $this->tarifaHotel($opcion);

        $this->altaRechazada('rechazo_mayorista_otra_alternativa', $alternativa, [
            'origen_tipo' => 'mayorista', 'opcion_mayorista_id' => $opcion->id, 'opcion_hotel_tarifa_id' => $tarifa->id,
        ], 'La opción de mayorista no pertenece a esta alternativa.');
    }

    public function test_mayorista_no_elegida_se_rechaza(): void
    {
        [$alternativa] = $this->alternativa();
        $opcion = $this->mayoristaElegida($alternativa, 'propuesta');
        $tarifa = $this->tarifaHotel($opcion);

        $this->altaRechazada('rechazo_mayorista_no_elegida', $alternativa, [
            'origen_tipo' => 'mayorista', 'opcion_mayorista_id' => $opcion->id, 'opcion_hotel_tarifa_id' => $tarifa->id,
        ], 'Marcá la opción de mayorista como elegida antes de agregarla al ítem.');
    }

    public function test_mayorista_con_tarifa_de_habitacion_de_otra_opcion_se_rechaza(): void
    {
        [$alternativa] = $this->alternativa();
        $opcion = $this->mayoristaElegida($alternativa);
        $otraOpcion = $this->mayoristaElegida($alternativa, 'propuesta');
        $tarifaAjena = $this->tarifaHotel($otraOpcion);

        $this->altaRechazada('rechazo_mayorista_tarifa_ajena', $alternativa, [
            'origen_tipo' => 'mayorista', 'opcion_mayorista_id' => $opcion->id, 'opcion_hotel_tarifa_id' => $tarifaAjena->id,
        ], 'Esa tarifa de habitación no pertenece a la opción de mayorista indicada.');
    }

    public function test_manual_sin_descripcion_se_rechaza(): void
    {
        [$alternativa] = $this->alternativa();

        $this->altaRechazada('rechazo_manual_sin_descripcion', $alternativa, [
            'origen_tipo' => 'manual', 'costo_snapshot' => 10, 'precio_venta_snapshot' => 20, 'moneda_costo' => 'PEN', 'cantidad' => 1,
        ]);
    }
}
