<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\ProveedorController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorAlojamientoDetalle;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\ProveedorTipo;
use App\Models\AgenciaViajes\ProveedorTipoConfig;
use App\Models\AgenciaViajes\Servicio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Sesión 11o (actualizado en la consolidación de hoteles) — 2 piezas
// relacionadas: (1) modalidad 'compartido' cargada desde plantilla se cobra
// por pasajero REAL de la cotización (no tarifa plana de 1 adulto, que es
// lo que pasaba antes — 'privado' no se toca, ya era tarifa fija real);
// (2) cama adicional para niños en hoteles, según tramo de EDAD real del
// pasajero (cotizacion_pasajeros.edad), no tipo_pax — ahora resuelto desde
// proveedor_alojamiento_detalle/proveedor_tarifas (origen_tipo=proveedor),
// no desde opciones_hotel/paquete_plantilla (eliminado). Mismo patrón que
// PaqueteComboTest: Postgres real (sistemafe_test_migrations), transacción
// por test revertida.
//
// Parte 2 SOLO lee (nunca escribe) el catálogo central proveedor_tipos —
// 'central' apunta a la base real (db_tenant_central), fuera de la
// transacción de este test. Si el slug 'alojamiento-hoteles' no existe ahí,
// esos tests se saltan en vez de arriesgar una escritura contra datos reales.
class PrecioPorPasajeroTest extends TestCase
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

    private function crearProveedorTarifa(string $modalidad, float $costo, float $ventaAdulto, ?float $ventaNino = null, ?float $ventaInfante = null): ProveedorTarifa
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
            'modalidad' => $modalidad,
            'moneda' => 'PEN',
            'precio_costo' => $costo,
            'margen_tipo' => 'porcentaje',
            'margen_valor' => 20,
            'precio_venta_adulto' => $ventaAdulto,
            'precio_venta_nino' => $ventaNino,
            'precio_venta_infante' => $ventaInfante,
            'vigente_desde' => now()->toDateString(),
            'tip_afe_igv' => '10',
            'destino_tributario' => 'nacional',
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

    // Cotización con 2 adultos + 1 niño + 1 infante, más una alternativa
    // vacía lista para cargarle una plantilla.
    private function crearAlternativaConPasajeros(): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '12345678', 'full_name' => 'Cliente Test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0001', 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'created_at' => now(), 'updated_at' => now(),
        ]);

        CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 30]);
        CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 28]);
        CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'nino', 'edad' => 8]);
        CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'infante', 'edad' => 2]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId,
            'nombre' => 'Alternativa 1',
            'moneda_cotizacion' => 'PEN',
            'tipo_cambio_aplicado' => 1,
            'tipo_cambio_origen' => 'dia',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Parte 1 — modalidad 'compartido' se cobra por pasajero real
    // ═══════════════════════════════════════════════════════════════

    public function test_compartido_cargado_desde_plantilla_se_cobra_por_pasajero_real(): void
    {
        $alternativa = $this->crearAlternativaConPasajeros();
        $tarifa = $this->crearProveedorTarifa('compartido', costo: 20, ventaAdulto: 50, ventaNino: 30, ventaInfante: 0);
        $tour = $this->crearTourSimple('City Tour Compartido', $tarifa);

        $response = app(AlternativaItemController::class)->desdePlantilla(
            new Request(['paquete_plantilla_id' => $tour->id, 'dia_referencial' => 1]),
            (string) $alternativa->id
        );

        $this->assertSame(200, $response->getStatusCode());
        $items = $response->getData()->items_agregados;
        $this->assertCount(1, $items);

        // 2 adultos × 50 + 1 niño × 30 + 1 infante × 0 = 130 (NO 50 plano).
        $this->assertEqualsWithDelta(130.0, (float) $items[0]->precio_venta_snapshot, 0.01);
        $this->assertSame('por_persona', $items[0]->modo_precio);
        // costo también multiplicado por el total de pasajeros (4 × 20 = 80).
        $this->assertEqualsWithDelta(80.0, (float) $items[0]->costo_snapshot, 0.01);
    }

    public function test_privado_cargado_desde_plantilla_sigue_con_tarifa_fija_sin_multiplicar(): void
    {
        $alternativa = $this->crearAlternativaConPasajeros();
        $tarifa = $this->crearProveedorTarifa('privado', costo: 100, ventaAdulto: 180);
        $tour = $this->crearTourSimple('Transporte Privado', $tarifa);

        $response = app(AlternativaItemController::class)->desdePlantilla(
            new Request(['paquete_plantilla_id' => $tour->id, 'dia_referencial' => 1]),
            (string) $alternativa->id
        );

        $this->assertSame(200, $response->getStatusCode());
        $items = $response->getData()->items_agregados;
        $this->assertCount(1, $items);

        // Tarifa plana, SIN multiplicar por los 4 pasajeros de la cotización.
        $this->assertEqualsWithDelta(180.0, (float) $items[0]->precio_venta_snapshot, 0.01);
        $this->assertSame('tarifa_fija', $items[0]->modo_precio);
        $this->assertSame(1, $items[0]->cantidad);
    }

    // ═══════════════════════════════════════════════════════════════
    // Parte 2 — cama adicional para niños en hoteles (consolidación de
    // hoteles: ahora es una proveedor_tarifa más, origen_tipo=proveedor,
    // con el tramo de edad viviendo en proveedor_alojamiento_detalle en vez
    // de opciones_hotel/paquete_plantilla).
    // ═══════════════════════════════════════════════════════════════

    private function crearProveedorHotelConTarifa(
        ?float $costoCamaAdicional = 15,
        ?float $ventaCamaAdicional = 30,
        int $edadMaxInfanteGratis = 4,
        int $edadMaxNinoCamaAdicional = 12,
    ): ProveedorTarifa {
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Tarapoto', 'tipo' => 'zona']);
        $servicio = Servicio::create(['nombre' => 'Alojamiento']);
        $destinoServicio = DestinoServicio::create([
            'destino_atractivo_id' => $destino->id,
            'servicio_id' => $servicio->id,
        ]);
        $proveedor = Proveedor::create(['razon_social' => 'Hotel Test SAC', 'estado' => true]);
        ProveedorAlojamientoDetalle::create([
            'proveedor_id' => $proveedor->id,
            'edad_max_infante_gratis' => $edadMaxInfanteGratis,
            'edad_max_nino_cama_adicional' => $edadMaxNinoCamaAdicional,
        ]);
        $proveedorServicio = ProveedorServicio::create([
            'proveedor_id' => $proveedor->id,
            'destino_servicio_id' => $destinoServicio->id,
        ]);

        return ProveedorTarifa::create([
            'proveedor_servicio_id' => $proveedorServicio->id,
            'tipo_tarifa' => 'publica',
            'modalidad' => 'privado',
            'moneda' => 'PEN',
            'tipo_habitacion' => 'matrimonial',
            'precio_costo' => 100,
            'margen_tipo' => 'porcentaje',
            'margen_valor' => 50,
            'precio_venta_adulto' => 150,
            'precio_costo_cama_adicional' => $costoCamaAdicional,
            'precio_venta_cama_adicional' => $ventaCamaAdicional,
            'vigente_desde' => now()->toDateString(),
            'tip_afe_igv' => '10',
            'destino_tributario' => 'nacional',
        ]);
    }

    public function test_cama_adicional_se_suma_al_snapshot_cuando_hay_pax_en_tramo(): void
    {
        $alternativa = $this->crearAlternativaConPasajeros();
        $tarifa = $this->crearProveedorHotelConTarifa();
        // El niño de 8 años (creado en crearAlternativaConPasajeros()) cae en (4, 12].
        $ninoId = CotizacionPasajero::where('cotizacion_id', $alternativa->cotizacion_id)->where('tipo_pax', 'nino')->first()->id;

        $response = app(AlternativaItemController::class)->store(
            new Request([
                'origen_tipo' => 'proveedor',
                'proveedor_tarifa_id' => $tarifa->id,
                'modo_precio' => 'tarifa_fija',
                'pax_incluidos' => [$ninoId],
                'camas_adicionales_nino' => 1,
            ]),
            (string) $alternativa->id
        );

        $this->assertSame(200, $response->getStatusCode());
        $item = $response->getData()->alternativa_item;
        // 150 (habitación) + 1 × 30 (cama adicional) = 180.
        $this->assertEqualsWithDelta(180.0, (float) $item->precio_venta_snapshot, 0.01);
        $this->assertEqualsWithDelta(115.0, (float) $item->costo_snapshot, 0.01);
    }

    public function test_cama_adicional_rechazada_si_ningun_pax_incluido_esta_en_el_tramo(): void
    {
        $alternativa = $this->crearAlternativaConPasajeros();
        $tarifa = $this->crearProveedorHotelConTarifa();
        // El infante de 2 años NO cae en (4, 12] — está bajo edad_max_infante_gratis.
        $infanteId = CotizacionPasajero::where('cotizacion_id', $alternativa->cotizacion_id)->where('tipo_pax', 'infante')->first()->id;

        $response = app(AlternativaItemController::class)->store(
            new Request([
                'origen_tipo' => 'proveedor',
                'proveedor_tarifa_id' => $tarifa->id,
                'modo_precio' => 'tarifa_fija',
                'pax_incluidos' => [$infanteId],
                'camas_adicionales_nino' => 1,
            ]),
            (string) $alternativa->id
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, \App\Models\AgenciaViajes\AlternativaItem::count());
    }

    public function test_cama_adicional_rechazada_sin_pax_incluidos(): void
    {
        $alternativa = $this->crearAlternativaConPasajeros();
        $tarifa = $this->crearProveedorHotelConTarifa();

        $response = app(AlternativaItemController::class)->store(
            new Request([
                'origen_tipo' => 'proveedor',
                'proveedor_tarifa_id' => $tarifa->id,
                'modo_precio' => 'tarifa_fija',
                'camas_adicionales_nino' => 1,
            ]),
            (string) $alternativa->id
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_cama_adicional_rechazada_si_la_habitacion_no_tiene_precio_configurado(): void
    {
        $alternativa = $this->crearAlternativaConPasajeros();
        $tarifa = $this->crearProveedorHotelConTarifa(costoCamaAdicional: null, ventaCamaAdicional: null);
        $ninoId = CotizacionPasajero::where('cotizacion_id', $alternativa->cotizacion_id)->where('tipo_pax', 'nino')->first()->id;

        $response = app(AlternativaItemController::class)->store(
            new Request([
                'origen_tipo' => 'proveedor',
                'proveedor_tarifa_id' => $tarifa->id,
                'modo_precio' => 'tarifa_fija',
                'pax_incluidos' => [$ninoId],
                'camas_adicionales_nino' => 1,
            ]),
            (string) $alternativa->id
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_cama_adicional_rechazada_si_el_proveedor_no_tiene_detalle_de_alojamiento(): void
    {
        // Mismo caso real que motivó la validación: una tarifa con
        // tipo_habitacion pero cuyo proveedor nunca tuvo la fila de
        // proveedor_alojamiento_detalle creada (ej. tipo_id cambiado
        // después, o dato cargado por fuera del flujo normal).
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Tarapoto', 'tipo' => 'zona']);
        $servicio = Servicio::create(['nombre' => 'Alojamiento']);
        $destinoServicio = DestinoServicio::create(['destino_atractivo_id' => $destino->id, 'servicio_id' => $servicio->id]);
        $proveedor = Proveedor::create(['razon_social' => 'Hotel Sin Detalle SAC', 'estado' => true]);
        $proveedorServicio = ProveedorServicio::create(['proveedor_id' => $proveedor->id, 'destino_servicio_id' => $destinoServicio->id]);
        $tarifa = ProveedorTarifa::create([
            'proveedor_servicio_id' => $proveedorServicio->id,
            'tipo_tarifa' => 'publica', 'modalidad' => 'privado', 'moneda' => 'PEN',
            'tipo_habitacion' => 'matrimonial', 'precio_costo' => 100, 'margen_tipo' => 'porcentaje', 'margen_valor' => 50,
            'precio_venta_adulto' => 150, 'precio_costo_cama_adicional' => 15, 'precio_venta_cama_adicional' => 30,
            'vigente_desde' => now()->toDateString(), 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ]);

        $alternativa = $this->crearAlternativaConPasajeros();
        $ninoId = CotizacionPasajero::where('cotizacion_id', $alternativa->cotizacion_id)->where('tipo_pax', 'nino')->first()->id;

        $response = app(AlternativaItemController::class)->store(
            new Request([
                'origen_tipo' => 'proveedor',
                'proveedor_tarifa_id' => $tarifa->id,
                'modo_precio' => 'tarifa_fija',
                'pax_incluidos' => [$ninoId],
                'camas_adicionales_nino' => 1,
            ]),
            (string) $alternativa->id
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    // Confirma explícitamente el punto de la spec: usa `edad` REAL, no
    // tipo_pax — un pasajero de 10 años con tipo_pax='adulto' (por el
    // umbral general de la agencia, que corta en 8 en este fixture) igual
    // activa cama adicional si cae dentro del tramo del PROVEEDOR (4, 12].
    public function test_cama_adicional_usa_edad_real_no_tipo_pax(): void
    {
        $alternativa = $this->crearAlternativaConPasajeros();
        $tarifa = $this->crearProveedorHotelConTarifa();

        $paxId = CotizacionPasajero::create([
            'cotizacion_id' => $alternativa->cotizacion_id,
            'tipo_pax' => 'adulto', // clasificado como adulto pese a la edad
            'edad' => 10,
        ])->id;

        $response = app(AlternativaItemController::class)->store(
            new Request([
                'origen_tipo' => 'proveedor',
                'proveedor_tarifa_id' => $tarifa->id,
                'modo_precio' => 'tarifa_fija',
                'pax_incluidos' => [$paxId],
                'camas_adicionales_nino' => 1,
            ]),
            (string) $alternativa->id
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    // ═══════════════════════════════════════════════════════════════
    // Parte 3 — defaults de edad heredados de configuracion_agencia al
    // crear un proveedor tipo Alojamiento (ProveedorController), sin tocar
    // el catálogo central real (proveedor_tipos): solo LEE el id real de
    // 'alojamiento-hoteles' — nunca escribe en 'central'. Si el catálogo no
    // tiene ese slug en este entorno, se salta en vez de arriesgar una
    // escritura contra datos reales.
    // ═══════════════════════════════════════════════════════════════

    private function tipoIdAlojamientoOSkip(): int
    {
        $tipoId = ProveedorTipo::where('slug', 'alojamiento-hoteles')->value('id');

        if (! $tipoId) {
            $this->markTestSkipped("Catálogo central sin 'alojamiento-hoteles' en este entorno — no se escribe en central desde un test.");
        }

        ProveedorTipoConfig::create(['proveedor_tipo_id' => $tipoId, 'habilitado' => true]);

        return $tipoId;
    }

    public function test_proveedor_sin_edades_explicitas_hereda_default_de_configuracion_agencia(): void
    {
        $tipoId = $this->tipoIdAlojamientoOSkip();

        ConfiguracionAgencia::first()->update([
            'edad_max_infante_gratis_hotel_default' => 3,
            'edad_max_nino_cama_adicional_hotel_default' => 10,
        ]);

        $response = app(ProveedorController::class)->store(Request::create('', 'POST', [
            'razon_social' => 'Hotel Sin Edades Explícitas',
            'tipo_id' => $tipoId,
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $proveedor = $response->getData()->proveedor;
        $this->assertSame(3, $proveedor->alojamiento_detalle->edad_max_infante_gratis);
        $this->assertSame(10, $proveedor->alojamiento_detalle->edad_max_nino_cama_adicional);
    }

    public function test_proveedor_con_edades_explicitas_no_usa_el_default(): void
    {
        $tipoId = $this->tipoIdAlojamientoOSkip();

        $response = app(ProveedorController::class)->store(Request::create('', 'POST', [
            'razon_social' => 'Hotel Con Edades Propias',
            'tipo_id' => $tipoId,
            'edad_max_infante_gratis' => 5,
            'edad_max_nino_cama_adicional' => 15,
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $proveedor = $response->getData()->proveedor;
        $this->assertSame(5, $proveedor->alojamiento_detalle->edad_max_infante_gratis);
        $this->assertSame(15, $proveedor->alojamiento_detalle->edad_max_nino_cama_adicional);
    }
}
