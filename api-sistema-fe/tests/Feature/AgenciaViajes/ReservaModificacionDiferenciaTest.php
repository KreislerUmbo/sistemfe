<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\CotizacionController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaModificacionController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaModificacion;
use App\Models\AgenciaViajes\ReservaVenta;
use App\Models\AgenciaViajes\Servicio;
use App\Models\AgenciaViajes\TourItinerarioItem;
use App\Models\Sale\Sale;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// T-003 — Modificar reserva: calcular la diferencia con lo vendido (R-AGE-03, R-AGE-04,
// R-AGE-06, D-AGE-031, D-AGE-032). Cada test parte de una reserva creada por el camino real
// (aceptar → crear la modificación) y cambia la versión nueva por los mismos endpoints del
// cotizador (actualizarPasajeros) cuando se trata de pasajeros. Postgres real, una transacción
// por test que se revierte. Todo el dinero se compara en centavos.
class ReservaModificacionDiferenciaTest extends TestCase
{
    use ConAccesoATodasLasReservas;

    private User $usuario;

    /** @var array<string, CotizacionPasajero> */
    private array $pax = [];

    /** @var array<string, AlternativaItem> */
    private array $items = [];

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

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->usuario = $this->verTodasLasReservas();
        $this->usuario->givePermissionTo(Permission::firstOrCreate(['name' => 'reservas.modificar', 'guard_name' => 'api']));
        Auth::guard('api')->setUser($this->usuario->fresh());
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    // ── Fixtures ────────────────────────────────────────────────────────────────

    private function crearTarifa(float $ventaAdulto, float $ventaNino): ProveedorTarifa
    {
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);
        $servicio = Servicio::create(['nombre' => 'Traslado '.uniqid()]);
        $destinoServicio = DestinoServicio::create(['destino_atractivo_id' => $destino->id, 'servicio_id' => $servicio->id]);
        $proveedor = Proveedor::create(['razon_social' => 'Transportes Test SAC', 'estado' => true]);
        $proveedorServicio = ProveedorServicio::create(['proveedor_id' => $proveedor->id, 'destino_servicio_id' => $destinoServicio->id]);

        return ProveedorTarifa::create([
            'proveedor_servicio_id' => $proveedorServicio->id,
            'tipo_tarifa' => 'publica',
            'modalidad' => 'privado',
            'moneda' => 'PEN',
            'precio_costo' => 60,
            'margen_tipo' => 'porcentaje',
            'margen_valor' => 20,
            'precio_venta_adulto' => $ventaAdulto,
            'precio_venta_nino' => $ventaNino,
            'vigente_desde' => \App\Services\HoraPeru::hoyTexto(),
            'tip_afe_igv' => '10',
            'destino_tributario' => 'nacional',
        ]);
    }

    private function item(Alternativa $alternativa, array $datos): AlternativaItem
    {
        return AlternativaItem::create($datos + [
            'alternativa_id' => $alternativa->id,
            'origen_tipo' => AlternativaItem::ORIGEN_MANUAL,
            'modo_precio' => 'tarifa_fija',
            'cantidad' => 1,
            'moneda_costo' => 'PEN',
            'costo_snapshot' => 0,
            'tip_afe_igv' => '10',
            'destino_tributario' => 'nacional',
        ]);
    }

    // Reserva activa de 3 pasajeros (Ana y Beto adultos, Carla niña de 8) y servicios:
    //  traslado    por persona (100 adulto / 50 niño) = 250
    //  habitacion  tarifa fija, para todos = 200
    //  solo_carla  taller solo para Carla = 80
    //  hotel       exonerado (Amazonía) = 300
    // Con $conTour, además un tour precio-a-mano repartido en 2 líneas de IGV (100,01 gravado
    // + 50,02 exonerado): no se combina con cambios de pasajeros (no hay tour real que repreciar).
    // $extras (T-073): 'pasaje', 'tour_real', 'tour_manual' — ver más abajo.
    private function reservaConTresPasajeros(bool $conTour = false, array $extras = []): Reserva
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10000000, 99999999),
            'full_name' => 'Cliente Diferencia', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacion = Cotizacion::create([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-DIF-'.uniqid(), 'cliente_id' => $clienteId,
            'vendedor_id' => $this->usuario->id, 'destino' => 'Tarapoto',
            'fecha_viaje_desde' => '2026-11-10', 'fecha_viaje_hasta' => '2026-11-13',
        ]);
        foreach ([['ana', 'adulto', 30], ['beto', 'adulto', 35], ['carla', 'nino', 8]] as [$clave, $tipo, $edad]) {
            $this->pax[$clave] = CotizacionPasajero::create([
                'cotizacion_id' => $cotizacion->id, 'tipo_pax' => $tipo, 'edad' => $edad,
            ]);
        }

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacion->id, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
        $tarifa = $this->crearTarifa(100, 50);
        $this->items['traslado'] = $this->item($alternativa, [
            'origen_tipo' => AlternativaItem::ORIGEN_PROVEEDOR, 'proveedor_tarifa_id' => $tarifa->id,
            'modo_precio' => 'por_persona', 'pax_incluidos' => null, 'costo_snapshot' => 150,
            'precio_venta_snapshot' => 250, 'precio_convertido' => 250,
        ]);
        $this->items['habitacion'] = $this->item($alternativa, [
            'descripcion_manual' => 'Habitación doble', 'precio_venta_snapshot' => 200, 'precio_convertido' => 200,
        ]);
        $this->items['solo_carla'] = $this->item($alternativa, [
            'descripcion_manual' => 'Taller para niños', 'pax_incluidos' => [$this->pax['carla']->id],
            'precio_venta_snapshot' => 80, 'precio_convertido' => 80,
        ]);
        $this->items['hotel'] = $this->item($alternativa, [
            'descripcion_manual' => 'Hotel', 'precio_venta_snapshot' => 300, 'precio_convertido' => 300,
            'tip_afe_igv' => '20', 'destino_tributario' => 'amazonia',
        ]);
        if ($conTour) {
            $cargaTour = (string) \Illuminate\Support\Str::uuid();
            foreach ([['tour_gravado', 100.01, '10', 'nacional'], ['tour_exonerado', 50.02, '20', 'amazonia']] as $grupo => [$clave, $precio, $igv, $destino]) {
                $this->items[$clave] = $this->item($alternativa, [
                    'origen_tipo' => AlternativaItem::ORIGEN_TOUR, 'modo_precio' => 'por_persona',
                    'carga_tour' => $cargaTour, 'precio_manual' => true,
                    'tour_linea' => ['tour_nombre' => 'Tour Catarata', 'grupo' => $grupo, 'n_grupos' => 2],
                    'precio_venta_snapshot' => $precio, 'precio_convertido' => $precio,
                    'tip_afe_igv' => $igv, 'destino_tributario' => $destino,
                ]);
            }
        }

        // T-073 — Pasaje aéreo: por persona, manual (nadie lo reprecia solo), 3 × 200 = 600.
        if (in_array('pasaje', $extras, true)) {
            $this->items['pasaje'] = $this->item($alternativa, [
                'descripcion_manual' => 'Pasaje aéreo', 'modo_precio' => 'por_persona', 'pax_incluidos' => null,
                'precio_venta_snapshot' => 600, 'precio_convertido' => 600,
            ]);
        }
        // T-073 — Tour real (dos grupos de IGV, 150 por adulto): 'tour_real' reprecia solo;
        // 'tour_manual' con el precio fijado a mano (300 en total para el único adulto que hay).
        foreach (['tour_real', 'tour_manual'] as $variante) {
            if (in_array($variante, $extras, true)) {
                $this->cargarTourReal($alternativa, $variante === 'tour_manual');
            }
        }

        $respuesta = app(ReservaController::class)->aceptar(new Request(), (string) $alternativa->id);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());

        return Reserva::where('alternativa_id', $alternativa->id)->firstOrFail();
    }

    // Tour de venta con 100 por adulto y dos servicios incluidos (uno exonerado, otro gravado):
    // se carga con el endpoint real y queda una línea por grupo de IGV.
    private function cargarTourReal(Alternativa $alternativa, bool $precioManual): void
    {
        ConfiguracionAgencia::first()->update([
            'modo_tours' => ConfiguracionAgencia::MODO_TOURS_NUEVO,
            'tip_afe_igv_default' => '20', 'destino_tributario_default' => 'amazonia',
        ]);
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);
        AlternativaDestino::firstOrCreate(['alternativa_id' => $alternativa->id, 'destino_atractivo_id' => $destino->id], ['orden' => 1]);

        $tour = PaquetePlantilla::create([
            'categoria' => 'local', 'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE, 'nombre' => 'Laguna Azul Full Day',
            'destino_atractivo_id' => $destino->id, 'duracion_horas' => 10,
            'precio_venta_final' => 100, 'precio_venta_nino' => null, 'costo_estimado_persona' => null,
        ]);
        TourItinerarioItem::create(['tour_id' => $tour->id, 'dia_relativo' => 1, 'orden' => 1, 'destino_atractivo_id' => $destino->id, 'descripcion' => 'Día 1']);
        foreach ([['Bote', 60, '20', 'amazonia'], ['Transporte', 20, '10', 'nacional']] as $orden => [$nombre, $costo, $igv, $destinoTributario]) {
            $servicio = Servicio::create(['nombre' => $nombre.' '.uniqid()]);
            PaquetePlantillaItem::create([
                'paquete_plantilla_id' => $tour->id,
                'destino_servicio_id' => DestinoServicio::create(['destino_atractivo_id' => $destino->id, 'servicio_id' => $servicio->id])->id,
                'costo_estimado_persona' => $costo, 'tip_afe_igv' => $igv, 'destino_tributario' => $destinoTributario, 'orden' => $orden + 1,
            ]);
        }

        $controller = app(AlternativaItemController::class);
        $respuesta = $controller->desdePlantilla(new Request(['paquete_plantilla_id' => $tour->id, 'dia_referencial' => 1]), (string) $alternativa->id);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());

        $lineas = AlternativaItem::where('alternativa_id', $alternativa->id)->where('origen_tipo', AlternativaItem::ORIGEN_TOUR)->orderBy('id')->get();
        $this->assertCount(2, $lineas);
        $this->items[$precioManual ? 'tour_manual_a' : 'tour_real_a'] = $lineas[0];
        $this->items[$precioManual ? 'tour_manual_b' : 'tour_real_b'] = $lineas[1];
        if ($precioManual) {
            $controller->precioCarga(new Request(['precio' => 120]), (string) $alternativa->id, $lineas[0]->carga_tour);
        }
    }

    private function abrir(Reserva $reserva): ReservaModificacion
    {
        $respuesta = app(ReservaModificacionController::class)->store((string) $reserva->id);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());

        return ReservaModificacion::findOrFail($respuesta->getData(true)['modificacion']['id']);
    }

    // Reserva con la modificación ya abierta.
    private function reservaConModificacion(bool $conTour = false, array $extras = []): array
    {
        $reserva = $this->reservaConTresPasajeros($conTour, $extras);

        return [$reserva, $this->abrir($reserva)];
    }

    /** El ítem de la versión nueva que viene de un servicio vendido. */
    private function nuevo(ReservaModificacion $m, string $clave): AlternativaItem
    {
        return AlternativaItem::where('alternativa_id', $m->alternativa_nueva_id)
            ->where('item_origen_id', $this->items[$clave]->id)->firstOrFail();
    }

    // Cambia los pasajeros de la modificación por el endpoint real del cotizador.
    private function cambiarPasajeros(ReservaModificacion $m, array $quitar = [], array $agregarEdades = []): void
    {
        $lista = [];
        foreach (CotizacionPasajero::where('cotizacion_id', $m->cotizacion_id)->orderBy('id')->get() as $copia) {
            $reservaPasajeroId = $copia->reserva_pasajero_id;
            if (in_array($reservaPasajeroId, $quitar, true)) {
                continue;
            }
            $lista[] = ['id' => $copia->id, 'edad' => $copia->edad];
        }
        foreach ($agregarEdades as $edad) {
            $lista[] = ['edad' => $edad];
        }

        $respuesta = app(CotizacionController::class)->actualizarPasajeros(
            Request::create('/', 'PUT', ['pasajeros' => $lista]),
            (string) $m->cotizacion_id
        );
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
    }

    private function reservaPasajeroId(Reserva $reserva, string $clave): int
    {
        return (int) $reserva->pasajeros->firstWhere('cotizacion_pasajero_id', $this->pax[$clave]->id)->id;
    }

    private function facturar(Reserva $reserva, array $claves = [], array $pasajeroIds = []): Sale
    {
        $reservaItemIds = collect($claves)->map(
            fn (string $c) => ReservaItem::where('reserva_id', $reserva->id)->where('alternativa_item_id', $this->items[$c]->id)->firstOrFail()->id
        )->all();
        $sale = Sale::factory()->create(['user_id' => $this->usuario->id]);
        ReservaVenta::create([
            'reserva_id' => $reserva->id, 'sale_id' => $sale->id,
            'reserva_item_ids' => $reservaItemIds, 'reserva_pasajero_ids' => $pasajeroIds,
        ]);

        return $sale;
    }

    private function diferencia(Reserva $reserva): array
    {
        $respuesta = app(ReservaModificacionController::class)->diferencia((string) $reserva->id);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());

        return $respuesta->getData(true);
    }

    private function linea(array $dif, AlternativaItem|string $item): array
    {
        $id = $item instanceof AlternativaItem ? $item->id : $item;
        $linea = collect($dif['lineas'])->first(fn ($l) => $l['alternativa_item_id'] === $id || $l['clave'] === $id);
        $this->assertNotNull($linea, 'No hay línea para '.json_encode($id));

        return $linea;
    }

    private function tiposDeBloqueo(array $dif): array
    {
        return collect($dif['bloqueos'])->pluck('tipo')->all();
    }

    // ── CA1 ────────────────────────────────────────────────────────────────────

    public function test_ca1_agregar_un_adulto_cambia_el_precio_por_exactamente_un_adulto_y_el_pasajero_sale_agregado(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $this->cambiarPasajeros($m, [], [28]);

        $dif = $this->diferencia($reserva);

        $traslado = $this->linea($dif, $this->nuevo($m, 'traslado'));
        $this->assertSame('cambio_precio', $traslado['estado']);
        $this->assertSame(25000, $traslado['vendido_centavos']);
        $this->assertSame(10000, $traslado['diferencia_centavos']); // un adulto: 100,00

        $agregados = collect($dif['pasajeros'])->where('estado', 'agregado');
        $this->assertCount(1, $agregados);
        $this->assertNull($agregados->first()['reserva_pasajero_id']);
        $this->assertCount(3, collect($dif['pasajeros'])->where('estado', 'igual'));
        $this->assertTrue($dif['hay_cambios']);
    }

    // ── CA2 ────────────────────────────────────────────────────────────────────

    public function test_ca2_quitar_un_pasajero_no_facturado_baja_su_parte_y_falta_la_penalidad(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $beto = $this->reservaPasajeroId($reserva, 'beto');
        $this->cambiarPasajeros($m, [$beto]);

        $dif = $this->diferencia($reserva);

        $this->assertSame(-10000, $this->linea($dif, $this->nuevo($m, 'traslado'))['diferencia_centavos']);

        $quitado = collect($dif['pasajeros'])->firstWhere('reserva_pasajero_id', $beto);
        $this->assertSame('quitado', $quitado['estado']);
        $this->assertTrue($quitado['falta_penalidad']);
        $this->assertContains('falta_penalidad', $this->tiposDeBloqueo($dif));
        $this->assertFalse($dif['se_puede_aplicar']);

        $bloqueo = collect($dif['bloqueos'])->firstWhere('tipo', 'falta_penalidad');
        $this->assertSame($beto, $bloqueo['reserva_pasajero_id']);
    }

    // ── CA3 ────────────────────────────────────────────────────────────────────

    public function test_ca3_quitar_un_pasajero_facturado_requiere_nota_de_credito_con_el_sale_id(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $beto = $this->reservaPasajeroId($reserva, 'beto');
        $sale = $this->facturar($reserva, ['hotel'], [$beto]);
        $this->cambiarPasajeros($m, [$beto]);

        $dif = $this->diferencia($reserva);

        $quitado = collect($dif['pasajeros'])->firstWhere('reserva_pasajero_id', $beto);
        $this->assertSame('requiere_nota_credito', $quitado['estado']);
        $this->assertFalse($quitado['falta_penalidad']);
        $this->assertSame([$sale->id], $quitado['sale_ids']);

        $bloqueo = collect($dif['bloqueos'])->first(fn ($b) => $b['reserva_pasajero_id'] === $beto);
        $this->assertSame('requiere_nota_credito', $bloqueo['tipo']);
        $this->assertSame($sale->id, $bloqueo['sale_id']);
        $this->assertFalse($dif['se_puede_aplicar']);
    }

    // ── CA4 ────────────────────────────────────────────────────────────────────

    public function test_ca4_un_servicio_nuevo_sale_agregado(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $nuevo = $this->item(Alternativa::findOrFail($m->alternativa_nueva_id), [
            'descripcion_manual' => 'Entrada al parque', 'precio_venta_snapshot' => 45.5, 'precio_convertido' => 45.5,
        ]);

        $dif = $this->diferencia($reserva);

        $linea = $this->linea($dif, $nuevo);
        $this->assertSame('agregado', $linea['estado']);
        $this->assertSame(0, $linea['vendido_centavos']);
        $this->assertSame(4550, $linea['diferencia_centavos']);
        $this->assertSame(4550, $dif['totales']['diferencia']);
        $this->assertTrue($dif['se_puede_aplicar']);
    }

    public function test_un_servicio_de_la_reserva_que_se_borra_en_la_version_nueva_sale_quitado(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $this->nuevo($m, 'hotel')->delete();

        $dif = $this->diferencia($reserva);

        $linea = collect($dif['lineas'])->firstWhere('estado', 'quitado');
        $this->assertSame(-30000, $linea['diferencia_centavos']);
        $this->assertNull($linea['alternativa_item_id']);
        $this->assertNotNull($linea['reserva_item_id']);
    }

    // ── CA5 ────────────────────────────────────────────────────────────────────

    public function test_ca5_sube_un_servicio_facturado_sale_ajuste_con_su_tratamiento_tributario(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $this->facturar($reserva, ['hotel']);
        $this->nuevo($m, 'hotel')->update(['precio_venta_snapshot' => 350, 'precio_convertido' => 350]);

        $dif = $this->diferencia($reserva);

        $linea = $this->linea($dif, $this->nuevo($m, 'hotel'));
        $this->assertSame('ajuste', $linea['estado']);
        $this->assertSame(5000, $linea['diferencia_centavos']);
        $this->assertTrue($linea['facturado']);
        $this->assertSame('20', $linea['tip_afe_igv']);
        $this->assertSame('amazonia', $linea['destino_tributario']);
        $this->assertSame(30000, $dif['totales']['ya_facturado']);
        $this->assertSame([], $dif['bloqueos']);
        $this->assertTrue($dif['se_puede_aplicar']);
    }

    // ── CA6 ────────────────────────────────────────────────────────────────────

    public function test_ca6_baja_un_servicio_facturado_requiere_nota_de_credito(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $sale = $this->facturar($reserva, ['hotel']);
        $nuevoHotel = $this->nuevo($m, 'hotel');
        $nuevoHotel->update(['precio_venta_snapshot' => 250, 'precio_convertido' => 250]);

        $dif = $this->diferencia($reserva);

        $linea = $this->linea($dif, $nuevoHotel);
        $this->assertSame('requiere_nota_credito', $linea['estado']);
        $this->assertSame(-5000, $linea['diferencia_centavos']);

        $bloqueo = $dif['bloqueos'][0];
        $this->assertSame('requiere_nota_credito', $bloqueo['tipo']);
        $this->assertSame($sale->id, $bloqueo['sale_id']);
        $this->assertSame($nuevoHotel->id, $bloqueo['alternativa_item_id']);
        $this->assertFalse($dif['se_puede_aplicar']);
    }

    public function test_quitar_un_servicio_facturado_requiere_nota_de_credito(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $sale = $this->facturar($reserva, ['hotel']);
        $this->nuevo($m, 'hotel')->delete();

        $dif = $this->diferencia($reserva);

        $linea = collect($dif['lineas'])->firstWhere('estado', 'requiere_nota_credito');
        $this->assertNull($linea['alternativa_item_id']);
        $this->assertSame([$sale->id], $linea['sale_ids']);
        $this->assertSame($sale->id, $dif['bloqueos'][0]['sale_id']);
    }

    // ── CA7 ────────────────────────────────────────────────────────────────────

    public function test_ca7_habitacion_con_otra_cantidad_de_pasajeros_y_mismo_precio_queda_para_revisar_y_se_confirma(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $this->cambiarPasajeros($m, [], [28]);
        $habitacion = $this->nuevo($m, 'habitacion');

        $dif = $this->diferencia($reserva);
        $linea = $this->linea($dif, $habitacion);
        $this->assertSame('para_revisar', $linea['estado']);
        $this->assertSame(0, $linea['diferencia_centavos']);
        $this->assertSame([3, 4], [$linea['pasajeros_vendidos'], $linea['pasajeros_nuevos']]);
        $this->assertContains('para_revisar', $this->tiposDeBloqueo($dif));
        $idsBloqueados = collect($dif['bloqueos'])->where('tipo', 'para_revisar')->pluck('alternativa_item_id')->all();
        // El hotel (también tarifa fija, para todos) queda para revisar por lo mismo.
        $this->assertEqualsCanonicalizing([$habitacion->id, $this->nuevo($m, 'hotel')->id], $idsBloqueados);
        $this->assertFalse($dif['se_puede_aplicar']);

        // Confirmar: deja de bloquear (primero la habitación, después el hotel).
        $controller = app(ReservaModificacionController::class);
        $respuesta = $controller->marcarRevisado((string) $reserva->id, (string) $habitacion->id);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
        $dif = $this->diferencia($reserva);
        $this->assertTrue($this->linea($dif, $habitacion)['revisado']);
        $this->assertFalse($dif['se_puede_aplicar']); // falta el hotel
        $controller->marcarRevisado((string) $reserva->id, (string) $this->nuevo($m, 'hotel')->id);
        $dif = $this->diferencia($reserva);
        $this->assertNotContains('para_revisar', $this->tiposDeBloqueo($dif));
        $this->assertTrue($dif['se_puede_aplicar']);

        // Si después se edita, vuelve a bloquear.
        $habitacion->fresh()->update(['descripcion_manual' => 'Habitación triple']);
        $this->assertNull($habitacion->fresh()->revisado_en_modificacion);
        $this->assertContains('para_revisar', $this->tiposDeBloqueo($this->diferencia($reserva)));
    }

    public function test_ca7_la_confirmacion_tambien_se_pierde_si_cambian_los_pasajeros(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $this->cambiarPasajeros($m, [], [28]);
        $habitacion = $this->nuevo($m, 'habitacion');
        app(ReservaModificacionController::class)->marcarRevisado((string) $reserva->id, (string) $habitacion->id);
        $this->assertNotNull($habitacion->fresh()->revisado_en_modificacion);

        $this->cambiarPasajeros($m, [], [5]);

        $this->assertNull($habitacion->fresh()->revisado_en_modificacion);
        $this->assertContains('para_revisar', $this->tiposDeBloqueo($this->diferencia($reserva)));
    }

    public function test_confirmar_un_servicio_de_otra_alternativa_responde_404_y_uno_que_no_esta_para_revisar_422(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $controller = app(ReservaModificacionController::class);

        // Un ítem de la versión VENDIDA no es de esta modificación.
        try {
            $controller->marcarRevisado((string) $reserva->id, (string) $this->items['habitacion']->id);
            $this->fail('Debió responder 404.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertNull($this->items['habitacion']->fresh()->revisado_en_modificacion);
        }

        $respuesta = $controller->marcarRevisado((string) $reserva->id, (string) $this->nuevo($m, 'habitacion')->id);
        $this->assertSame(422, $respuesta->getStatusCode());
    }

    // Nota de la revisión de T-002: un servicio exclusivo de pasajeros que ya no están.
    public function test_servicio_exclusivo_de_un_pasajero_quitado_no_se_cobra_a_todos_y_bloquea_hasta_asignarlo(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $this->cambiarPasajeros($m, [$this->reservaPasajeroId($reserva, 'carla')]);
        $soloCarla = $this->nuevo($m, 'solo_carla');
        $this->assertSame([], $soloCarla->pax_incluidos); // en el sistema, [] se lee como "todos"

        $dif = $this->diferencia($reserva);
        $linea = $this->linea($dif, $soloCarla);
        $this->assertSame('para_revisar', $linea['estado']);
        $this->assertTrue($linea['sin_pasajeros']);
        $this->assertSame(0, $linea['diferencia_centavos']); // no se cobró a los demás
        $this->assertContains('servicio_sin_pasajeros', $this->tiposDeBloqueo($dif));

        // No se puede confirmar como si nada.
        $respuesta = app(ReservaModificacionController::class)->marcarRevisado((string) $reserva->id, (string) $soloCarla->id);
        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertContains('servicio_sin_pasajeros', $this->tiposDeBloqueo($this->diferencia($reserva)));

        // Asignarlo a alguien lo destraba.
        $ana = CotizacionPasajero::where('cotizacion_id', $m->cotizacion_id)
            ->where('reserva_pasajero_id', $this->reservaPasajeroId($reserva, 'ana'))->firstOrFail();
        $soloCarla->update(['pax_incluidos' => [$ana->id]]);
        $this->assertNotContains('servicio_sin_pasajeros', $this->tiposDeBloqueo($this->diferencia($reserva)));
    }

    public function test_servicio_exclusivo_de_un_pasajero_quitado_se_destraba_tambien_quitandolo(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $this->cambiarPasajeros($m, [$this->reservaPasajeroId($reserva, 'carla')]);
        $this->nuevo($m, 'solo_carla')->delete();

        $dif = $this->diferencia($reserva);

        $this->assertNotContains('servicio_sin_pasajeros', $this->tiposDeBloqueo($dif));
        $this->assertSame('quitado', collect($dif['lineas'])->firstWhere('item_origen_id', $this->items['solo_carla']->id)['estado']);
    }

    // Revisión de T-003: el mismo servicio, pero sin respaldo en la reserva (sincronizar-items no
    // crea un servicio solo de pasajeros quitados). No puede salir "agregado" para todos.
    public function test_servicio_sin_pasajeros_y_sin_servicio_en_la_reserva_no_sale_agregado(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $reservaItem = ReservaItem::where('reserva_id', $reserva->id)
            ->where('alternativa_item_id', $this->items['solo_carla']->id)->firstOrFail();
        DB::table('reserva_item_pasajero')->where('reserva_item_id', $reservaItem->id)->delete();
        $reservaItem->delete();
        $this->cambiarPasajeros($m, [$this->reservaPasajeroId($reserva, 'carla')]);
        $soloCarla = $this->nuevo($m, 'solo_carla');

        $dif = $this->diferencia($reserva);
        $linea = $this->linea($dif, $soloCarla);

        $this->assertSame('para_revisar', $linea['estado']);
        $this->assertTrue($linea['sin_pasajeros']);
        $this->assertContains('servicio_sin_pasajeros', $this->tiposDeBloqueo($dif));
        $this->assertFalse($dif['se_puede_aplicar']);
    }

    // ── CA8 ────────────────────────────────────────────────────────────────────

    public function test_ca8_sin_cambios_todo_igual_diferencia_cero_y_nada_que_aplicar(): void
    {
        [$reserva] = $this->reservaConModificacion();

        $dif = $this->diferencia($reserva);

        $this->assertCount(4, $dif['lineas']);
        $this->assertSame(['igual'], collect($dif['lineas'])->pluck('estado')->unique()->values()->all());
        $this->assertSame(['igual'], collect($dif['pasajeros'])->pluck('estado')->unique()->values()->all());
        $this->assertSame(0, $dif['totales']['diferencia']);
        $this->assertSame(83000, $dif['totales']['vendido']);
        $this->assertSame($dif['totales']['vendido'], $dif['totales']['nuevo']);
        $this->assertSame([], $dif['bloqueos']);
        $this->assertFalse($dif['hay_cambios']);
        $this->assertFalse($dif['se_puede_aplicar']);
    }

    // ── T-073: por persona que no se reprecia solo ──────────────────────────────

    public function test_t073_ca1_pasaje_aereo_por_persona_con_un_pasajero_agregado_queda_para_revisar_y_bloquea(): void
    {
        [$reserva, $m] = $this->reservaConModificacion(false, ['pasaje']);
        $this->cambiarPasajeros($m, [], [28]);

        $dif = $this->diferencia($reserva);

        $pasaje = $this->linea($dif, $this->nuevo($m, 'pasaje'));
        $this->assertSame('para_revisar', $pasaje['estado']);
        $this->assertSame(0, $pasaje['diferencia_centavos']);
        $this->assertSame([3, 4], [$pasaje['pasajeros_vendidos'], $pasaje['pasajeros_nuevos']]);
        $this->assertFalse($pasaje['revisado']);
        $this->assertContains($this->nuevo($m, 'pasaje')->id, collect($dif['bloqueos'])->where('tipo', 'para_revisar')->pluck('alternativa_item_id')->all());
        $this->assertFalse($dif['se_puede_aplicar']);
    }

    public function test_t073_ca2_el_mismo_caso_con_el_pasaje_facturado_tambien_queda_para_revisar_y_si_sube_sale_ajuste(): void
    {
        [$reserva, $m] = $this->reservaConModificacion(false, ['pasaje']);
        $todos = collect(['ana', 'beto', 'carla'])->map(fn ($c) => $this->reservaPasajeroId($reserva, $c))->all();
        $this->facturar($reserva, ['pasaje'], $todos);
        $this->cambiarPasajeros($m, [], [28]);

        $pasaje = $this->linea($this->diferencia($reserva), $this->nuevo($m, 'pasaje'));
        $this->assertTrue($pasaje['facturado']);
        $this->assertSame('para_revisar', $pasaje['estado']);
        $this->assertSame([3, 4], [$pasaje['pasajeros_vendidos'], $pasaje['pasajeros_nuevos']]);
        $this->assertFalse($this->diferencia($reserva)['se_puede_aplicar']);

        // El vendedor corrige el precio en el cotizador (el cuarto pasaje): entonces es un ajuste.
        $this->nuevo($m, 'pasaje')->update(['precio_venta_snapshot' => 800, 'precio_convertido' => 800]);
        $pasaje = $this->linea($this->diferencia($reserva), $this->nuevo($m, 'pasaje'));
        $this->assertSame('ajuste', $pasaje['estado']);
        $this->assertSame(20000, $pasaje['diferencia_centavos']);
    }

    public function test_t073_ca3_revisado_lo_saca_de_los_bloqueos_y_si_cambian_otra_vez_los_pasajeros_vuelve_a_bloquear(): void
    {
        [$reserva, $m] = $this->reservaConModificacion(false, ['pasaje']);
        $this->cambiarPasajeros($m, [], [28]);
        $pasaje = $this->nuevo($m, 'pasaje');

        $respuesta = app(ReservaModificacionController::class)->marcarRevisado((string) $reserva->id, (string) $pasaje->id);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
        $dif = $this->diferencia($reserva);
        $this->assertTrue($this->linea($dif, $pasaje)['revisado']);
        $this->assertNotContains($pasaje->id, collect($dif['bloqueos'])->where('tipo', 'para_revisar')->pluck('alternativa_item_id')->all());

        $this->cambiarPasajeros($m, [], [5]);

        $this->assertNull($pasaje->fresh()->revisado_en_modificacion);
        $dif = $this->diferencia($reserva);
        $this->assertSame([3, 5], [$this->linea($dif, $pasaje)['pasajeros_vendidos'], $this->linea($dif, $pasaje)['pasajeros_nuevos']]);
        $this->assertContains($pasaje->id, collect($dif['bloqueos'])->where('tipo', 'para_revisar')->pluck('alternativa_item_id')->all());
    }

    public function test_t073_ca4_por_persona_de_proveedor_con_tarifa_se_reprecia_y_no_queda_para_revisar(): void
    {
        [$reserva, $m] = $this->reservaConModificacion(false, ['pasaje']);
        $this->cambiarPasajeros($m, [], [28]);

        $traslado = $this->linea($this->diferencia($reserva), $this->nuevo($m, 'traslado'));

        $this->assertSame('cambio_precio', $traslado['estado']);
        $this->assertSame(10000, $traslado['diferencia_centavos']);
        $this->assertNull($traslado['pasajeros_vendidos']);
    }

    public function test_t073_ca5_por_persona_sin_cambio_de_pasajeros_sigue_igual(): void
    {
        [$reserva, $m] = $this->reservaConModificacion(false, ['pasaje']);
        // Cambia un precio que no depende de pasajeros, para que haya modificación en curso.
        $this->nuevo($m, 'hotel')->update(['precio_venta_snapshot' => 310, 'precio_convertido' => 310]);

        $dif = $this->diferencia($reserva);

        $this->assertSame('igual', $this->linea($dif, $this->nuevo($m, 'pasaje'))['estado']);
        $this->assertNotContains($this->nuevo($m, 'pasaje')->id, collect($dif['bloqueos'])->pluck('alternativa_item_id')->all());
    }

    public function test_t073_por_persona_con_la_tarifa_borrada_deja_de_repreciarse_y_queda_para_revisar(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $nuevo = $this->nuevo($m, 'traslado');
        // Sin tarifa detrás, recalcular() no lo toca: el precio no se mueve y no puede salir "igual".
        DB::table('alternativa_items')->where('id', $nuevo->id)->update(['proveedor_tarifa_id' => null]);
        $this->cambiarPasajeros($m, [], [28]);

        $traslado = $this->linea($this->diferencia($reserva), $nuevo->fresh());

        $this->assertSame('para_revisar', $traslado['estado']);
        $this->assertSame([3, 4], [$traslado['pasajeros_vendidos'], $traslado['pasajeros_nuevos']]);
    }

    public function test_t073_ca5b_tour_con_precio_a_mano_y_un_pasajero_agregado_deja_sus_lineas_para_revisar(): void
    {
        [$reserva, $m] = $this->reservaConModificacion(false, ['tour_manual']);
        $this->cambiarPasajeros($m, [], [28]);

        $dif = $this->diferencia($reserva);

        $a = $this->linea($dif, $this->nuevo($m, 'tour_manual_a'));
        $b = $this->linea($dif, $this->nuevo($m, 'tour_manual_b'));
        foreach ([$a, $b] as $linea) {
            $this->assertSame('para_revisar', $linea['estado']);
            $this->assertSame(0, $linea['diferencia_centavos']);
            $this->assertSame([3, 4], [$linea['pasajeros_vendidos'], $linea['pasajeros_nuevos']]);
        }
        // Cada línea de IGV es su propio bloqueo.
        $bloqueados = collect($dif['bloqueos'])->where('tipo', 'para_revisar')->pluck('alternativa_item_id')->all();
        $this->assertContains($a['alternativa_item_id'], $bloqueados);
        $this->assertContains($b['alternativa_item_id'], $bloqueados);

        // Se confirman una por una.
        $controller = app(ReservaModificacionController::class);
        $controller->marcarRevisado((string) $reserva->id, (string) $a['alternativa_item_id']);
        $bloqueados = collect($this->diferencia($reserva)['bloqueos'])->where('tipo', 'para_revisar')->pluck('alternativa_item_id')->all();
        $this->assertNotContains($a['alternativa_item_id'], $bloqueados);
        $this->assertContains($b['alternativa_item_id'], $bloqueados);
    }

    public function test_t073_ca5b_el_mismo_tour_sin_precio_a_mano_se_reprecia_y_sale_cambio_de_precio(): void
    {
        [$reserva, $m] = $this->reservaConModificacion(false, ['tour_real']);
        $this->cambiarPasajeros($m, [], [28]);

        $dif = $this->diferencia($reserva);

        $lineas = [$this->linea($dif, $this->nuevo($m, 'tour_real_a')), $this->linea($dif, $this->nuevo($m, 'tour_real_b'))];
        foreach ($lineas as $linea) {
            $this->assertSame('cambio_precio', $linea['estado']);
            $this->assertNull($linea['pasajeros_vendidos']);
        }
        $this->assertSame(10000, array_sum(array_column($lineas, 'diferencia_centavos'))); // un adulto más: 100,00
    }

    // ── CA9 ────────────────────────────────────────────────────────────────────

    public function test_ca9_un_tour_repartido_en_dos_lineas_de_igv_cuadra_al_centimo(): void
    {
        [$reserva, $m] = $this->reservaConModificacion(true);
        // Precios con céntimos que no se reparten exacto: 100,01 → 133,34 y 50,02 → 66,67.
        $this->nuevo($m, 'tour_gravado')->update(['precio_venta_snapshot' => 133.34, 'precio_convertido' => 133.34]);
        $this->nuevo($m, 'tour_exonerado')->update(['precio_venta_snapshot' => 66.67, 'precio_convertido' => 66.67]);

        $dif = $this->diferencia($reserva);

        $gravado = $this->linea($dif, $this->nuevo($m, 'tour_gravado'));
        $exonerado = $this->linea($dif, $this->nuevo($m, 'tour_exonerado'));
        $this->assertSame(3333, $gravado['diferencia_centavos']);
        $this->assertSame(1665, $exonerado['diferencia_centavos']);
        $this->assertSame('10', $gravado['tip_afe_igv']);
        $this->assertSame('20', $exonerado['tip_afe_igv']);

        // Nuevo − vendido, calculado aparte desde la base, en centavos.
        $nuevoTour = (int) round(
            (float) DB::table('alternativa_items')->whereIn('id', [$this->nuevo($m, 'tour_gravado')->id, $this->nuevo($m, 'tour_exonerado')->id])->sum('precio_convertido') * 100
        );
        $vendidoTour = (int) round(
            (float) DB::table('alternativa_items')->whereIn('id', [$this->items['tour_gravado']->id, $this->items['tour_exonerado']->id])->sum('precio_convertido') * 100
        );
        $this->assertSame($nuevoTour - $vendidoTour, $gravado['diferencia_centavos'] + $exonerado['diferencia_centavos']);

        // La diferencia total es la suma de todas las líneas, y vendido/nuevo también.
        $this->assertSame(array_sum(array_column($dif['lineas'], 'diferencia_centavos')), $dif['totales']['diferencia']);
        $this->assertSame(array_sum(array_column($dif['lineas'], 'vendido_centavos')), $dif['totales']['vendido']);
        $this->assertSame($dif['totales']['nuevo'] - $dif['totales']['vendido'], $dif['totales']['diferencia']);
        $this->assertSame(4998, $dif['totales']['diferencia']);
    }

    // ── CA10 ───────────────────────────────────────────────────────────────────

    public function test_ca10_la_huella_es_estable_y_cambia_con_cualquier_cambio_de_precio(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();

        $primera = $this->diferencia($reserva)['huella'];
        $this->assertSame($primera, $this->diferencia($reserva)['huella']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $primera);

        $this->nuevo($m, 'hotel')->update(['precio_venta_snapshot' => 300.01, 'precio_convertido' => 300.01]);
        $conPrecio = $this->diferencia($reserva)['huella'];
        $this->assertNotSame($primera, $conPrecio);
        $this->assertSame($conPrecio, $this->diferencia($reserva)['huella']);

        $this->cambiarPasajeros($m, [], [28]);
        $this->assertNotSame($conPrecio, $this->diferencia($reserva)['huella']);
    }

    // ── CA11 ───────────────────────────────────────────────────────────────────

    public static function rutasProvider(): array
    {
        return [
            'GET diferencia' => ['GET', 'api/reservas/{id}/modificacion/diferencia'],
            'POST revisado' => ['POST', 'api/reservas/{id}/modificacion/items/{itemId}/revisado'],
        ];
    }

    /** @dataProvider rutasProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('rutasProvider')]
    public function test_ca11_las_rutas_exigen_reservas_modificar_y_el_middleware_bloquea_sin_el_permiso(string $metodo, string $uri): void
    {
        $ruta = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === $uri && in_array($metodo, $r->methods(), true)
        );
        $this->assertNotNull($ruta, "No existe la ruta {$metodo} {$uri}.");
        $this->assertContains('permission:reservas.modificar', $ruta->gatherMiddleware());

        $sin = User::factory()->create(['role_id' => Role::create(['name' => 'rol-sin-'.uniqid(), 'guard_name' => 'api'])->id]);
        Auth::guard('api')->setUser($sin->fresh());
        $this->expectException(UnauthorizedException::class);
        (new PermissionMiddleware())->handle(new Request(), fn () => 'ok', 'reservas.modificar');
    }

    public function test_sin_modificacion_abierta_la_diferencia_responde_404(): void
    {
        $reserva = $this->reservaConTresPasajeros();

        $respuesta = app(ReservaModificacionController::class)->diferencia((string) $reserva->id);

        $this->assertSame(404, $respuesta->getStatusCode());
    }
}
