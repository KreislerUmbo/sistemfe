<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\CotizacionController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaModificacionController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaModificacion;
use App\Models\AgenciaViajes\ReservaVenta;
use App\Models\AgenciaViajes\Servicio;
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

// T-004 — Modificar reserva: penalidad por quitar un pasajero (R-AGE-05, R-AGE-06, D-AGE-033).
// Los fixtures son los de ReservaModificacionDiferenciaTest (T-003). Cada test parte de una reserva creada por el camino real
// (aceptar → crear la modificación) y cambia los pasajeros por el endpoint real del
// cotizador. Postgres real, una transacción
// por test que se revierte. Todo el dinero se compara en centavos.
class ReservaModificacionPenalidadTest extends TestCase
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
    private function reservaConTresPasajeros(bool $conTour = false): Reserva
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

        $respuesta = app(ReservaController::class)->aceptar(new Request(), (string) $alternativa->id);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());

        return Reserva::where('alternativa_id', $alternativa->id)->firstOrFail();
    }

    private function abrir(Reserva $reserva): ReservaModificacion
    {
        $respuesta = app(ReservaModificacionController::class)->store((string) $reserva->id);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());

        return ReservaModificacion::findOrFail($respuesta->getData(true)['modificacion']['id']);
    }

    // Reserva con la modificación ya abierta.
    private function reservaConModificacion(bool $conTour = false): array
    {
        $reserva = $this->reservaConTresPasajeros($conTour);

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


    // ── Ayudas de T-004 ────────────────────────────────────────────────────────

    private const DATOS = ['monto' => 50, 'motivo' => 'No se presentó', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional'];

    // Llama al controller como lo haría la ruta: el FormRequest valida primero y, si falla,
    // responde 422 (HttpResponseException) antes de entrar al controller.
    private function llamar(string $metodo, Reserva $reserva, array $datos, ?int $itemId = null): \Illuminate\Http\JsonResponse
    {
        $controller = app(\App\Http\Controllers\AgenciaViajes\ReservaModificacionPenalidadController::class);

        try {
            if ($metodo === 'DELETE') {
                return $controller->destroy((string) $reserva->id, (string) $itemId);
            }

            $peticion = \App\Http\Requests\AgenciaViajes\PenalidadPasajeroRequest::create('/', $metodo, $datos);
            $peticion->setContainer(app())->setRedirector(app('redirect'));
            $peticion->validateResolved();

            return $metodo === 'POST'
                ? $controller->store($peticion, (string) $reserva->id)
                : $controller->update($peticion, (string) $reserva->id, (string) $itemId);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            return $e->getResponse();
        }
    }

    private function crearPenalidad(Reserva $reserva, int $pasajeroId, array $cambios = []): \Illuminate\Http\JsonResponse
    {
        return $this->llamar('POST', $reserva, ['reserva_pasajero_id' => $pasajeroId] + $cambios + self::DATOS);
    }

    // Reserva con la modificación abierta y Beto quitado; devuelve también su id de pasajero.
    private function conBetoQuitado(): array
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $beto = $this->reservaPasajeroId($reserva, 'beto');
        $this->cambiarPasajeros($m, [$beto]);

        return [$reserva, $m, $beto];
    }

    // ── CA1 ────────────────────────────────────────────────────────────────────

    public function test_ca1_la_penalidad_se_agrega_a_la_version_nueva_y_la_diferencia_deja_de_bloquear_por_el_pasajero(): void
    {
        [$reserva, $m, $beto] = $this->conBetoQuitado();
        $antes = $this->diferencia($reserva);
        $this->assertContains('falta_penalidad', $this->tiposDeBloqueo($antes));
        $destinoNuevo = AlternativaDestino::create([
            'alternativa_id' => $m->alternativa_nueva_id,
            'destino_atractivo_id' => DestinoAtractivo::firstOrFail()->id,
            'orden' => 1,
        ]);

        $respuesta = $this->crearPenalidad($reserva, $beto);

        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
        $item = AlternativaItem::where('alternativa_id', $m->alternativa_nueva_id)
            ->where('penalidad_reserva_pasajero_id', $beto)->firstOrFail();
        $this->assertSame(AlternativaItem::ORIGEN_MANUAL, $item->origen_tipo);
        $this->assertStringStartsWith('Penalidad por cancelación — ', $item->descripcion_manual);
        $this->assertStringContainsString('No se presentó', $item->descripcion_manual);
        $this->assertSame(['10', 'nacional'], [$item->tip_afe_igv, $item->destino_tributario]);
        $this->assertNull($item->item_origen_id);
        // Mismo destino que tomaría un ítem manual del cotizador (el primero de la alternativa).
        $this->assertSame($destinoNuevo->id, (int) $item->alternativa_destino_id);

        // La respuesta ya trae la diferencia nueva.
        $this->assertNotContains('falta_penalidad', collect($respuesta->getData(true)['bloqueos'])->pluck('tipo')->all());

        $dif = $this->diferencia($reserva);
        $this->assertNotContains('falta_penalidad', $this->tiposDeBloqueo($dif));
        $linea = $this->linea($dif, $item);
        $this->assertSame('agregado', $linea['estado']);
        $this->assertSame(5000, $linea['diferencia_centavos']);
        $this->assertSame(5000, $dif['totales']['diferencia'] - $antes['totales']['diferencia']);

        $fila = collect($dif['pasajeros'])->firstWhere('reserva_pasajero_id', $beto);
        $this->assertFalse($fila['falta_penalidad']);
        $this->assertSame($item->id, $fila['penalidad_alternativa_item_id']);
    }

    // ── CA2 ────────────────────────────────────────────────────────────────────

    public function test_ca2_monto_cero_con_motivo_es_valido_y_sin_motivo_es_422(): void
    {
        [$reserva, $m, $beto] = $this->conBetoQuitado();

        $this->assertSame(422, $this->crearPenalidad($reserva, $beto, ['motivo' => ''])->getStatusCode());
        $this->assertSame(422, $this->crearPenalidad($reserva, $beto, ['motivo' => null])->getStatusCode());
        $this->assertSame(0, AlternativaItem::where('penalidad_reserva_pasajero_id', $beto)->count());

        $respuesta = $this->crearPenalidad($reserva, $beto, ['monto' => 0]);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
        $item = AlternativaItem::where('penalidad_reserva_pasajero_id', $beto)->firstOrFail();
        $this->assertSame(0, $this->linea($this->diferencia($reserva), $item)['nuevo_centavos']);
    }

    public function test_el_monto_no_puede_ser_negativo_ni_tener_mas_de_dos_decimales(): void
    {
        [$reserva, $m, $beto] = $this->conBetoQuitado();

        foreach ([-1, '10.005', 'abc', null] as $monto) {
            $this->assertSame(422, $this->crearPenalidad($reserva, $beto, ['monto' => $monto])->getStatusCode(), json_encode($monto));
        }
        $this->assertSame(200, $this->crearPenalidad($reserva, $beto, ['monto' => '12.34'])->getStatusCode());
        $item = AlternativaItem::where('penalidad_reserva_pasajero_id', $beto)->firstOrFail();
        $this->assertSame(1234, $this->linea($this->diferencia($reserva), $item)['nuevo_centavos']);
    }

    // ── CA3 ────────────────────────────────────────────────────────────────────

    public function test_ca3_sin_tratamiento_de_igv_es_422_y_no_hay_valor_por_defecto(): void
    {
        [$reserva, $m, $beto] = $this->conBetoQuitado();

        foreach ([['tip_afe_igv' => null], ['destino_tributario' => null], ['tip_afe_igv' => '99'], ['destino_tributario' => 'luna']] as $cambio) {
            $this->assertSame(422, $this->crearPenalidad($reserva, $beto, $cambio)->getStatusCode(), json_encode($cambio));
        }
        $this->assertSame(0, AlternativaItem::where('penalidad_reserva_pasajero_id', $beto)->count());
    }

    // ── CA4 ────────────────────────────────────────────────────────────────────

    public function test_ca4_un_pasajero_que_no_esta_quitado_o_esta_facturado_es_422(): void
    {
        [$reserva, $m, $beto] = $this->conBetoQuitado();
        $ana = $this->reservaPasajeroId($reserva, 'ana');

        $this->assertSame(422, $this->crearPenalidad($reserva, $ana)->getStatusCode());

        $carla = $this->reservaPasajeroId($reserva, 'carla');
        $this->facturar($reserva, ['hotel'], [$carla]);
        $this->cambiarPasajeros($m, [$beto, $carla]);
        $respuesta = $this->crearPenalidad($reserva, $carla);
        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertStringContainsString('facturado', $respuesta->getData(true)['message']);

        $this->assertSame(0, AlternativaItem::whereNotNull('penalidad_reserva_pasajero_id')->count());
    }

    // ── CA5 ────────────────────────────────────────────────────────────────────

    public function test_ca5_una_segunda_penalidad_para_el_mismo_pasajero_es_422(): void
    {
        [$reserva, $m, $beto] = $this->conBetoQuitado();

        $this->assertSame(200, $this->crearPenalidad($reserva, $beto)->getStatusCode());
        $this->assertSame(422, $this->crearPenalidad($reserva, $beto, ['monto' => 10])->getStatusCode());

        $this->assertSame(1, AlternativaItem::where('penalidad_reserva_pasajero_id', $beto)->count());
    }

    // ── CA6 ────────────────────────────────────────────────────────────────────

    // Hoy el cotizador no devuelve a un pasajero quitado con su vínculo (quitar borra la copia y
    // volver a agregar crea un pasajero nuevo), así que se re-vincula a mano: es el estado que
    // la diferencia debe poder detectar (ver Hallazgos de T-004).
    public function test_ca6_si_el_pasajero_vuelve_su_penalidad_sobra_bloquea_y_no_se_borra_sola(): void
    {
        [$reserva, $m, $beto] = $this->conBetoQuitado();
        $this->assertSame(200, $this->crearPenalidad($reserva, $beto)->getStatusCode());
        $item = AlternativaItem::where('penalidad_reserva_pasajero_id', $beto)->firstOrFail();

        CotizacionPasajero::create([
            'cotizacion_id' => $m->cotizacion_id, 'tipo_pax' => 'adulto', 'edad' => 35, 'reserva_pasajero_id' => $beto,
        ]);

        $dif = $this->diferencia($reserva);
        $this->assertContains('penalidad_sobrante', $this->tiposDeBloqueo($dif));
        $this->assertFalse($dif['se_puede_aplicar']);
        $bloqueo = collect($dif['bloqueos'])->firstWhere('tipo', 'penalidad_sobrante');
        $this->assertSame($item->id, $bloqueo['alternativa_item_id']);
        $this->assertSame($beto, $bloqueo['reserva_pasajero_id']);
        $this->assertNotNull(AlternativaItem::find($item->id), 'La penalidad no se borra sola.');

        // Se destraba borrándola.
        $this->assertSame(200, $this->llamar('DELETE', $reserva, [], $item->id)->getStatusCode());
        $this->assertNotContains('penalidad_sobrante', $this->tiposDeBloqueo($this->diferencia($reserva)));
    }

    // ── CA7 ────────────────────────────────────────────────────────────────────

    public function test_ca7_editar_y_borrar_actualizan_la_diferencia_y_el_total_de_la_alternativa(): void
    {
        [$reserva, $m, $beto] = $this->conBetoQuitado();
        $this->crearPenalidad($reserva, $beto);
        $item = AlternativaItem::where('penalidad_reserva_pasajero_id', $beto)->firstOrFail();
        $totalConPenalidad = (float) Alternativa::findOrFail($m->alternativa_nueva_id)->total;

        $respuesta = $this->llamar('PUT', $reserva, ['monto' => 80, 'motivo' => 'Cancelación tardía', 'tip_afe_igv' => '20', 'destino_tributario' => 'amazonia'], $item->id);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());

        $item->refresh();
        $this->assertSame(['20', 'amazonia'], [$item->tip_afe_igv, $item->destino_tributario]);
        $this->assertStringContainsString('Cancelación tardía', $item->descripcion_manual);
        $this->assertSame($beto, (int) $item->penalidad_reserva_pasajero_id);
        $this->assertSame(8000, $this->linea($this->diferencia($reserva), $item)['nuevo_centavos']);
        $this->assertEqualsWithDelta($totalConPenalidad + 30, (float) Alternativa::findOrFail($m->alternativa_nueva_id)->total, 0.001);

        // No se puede editar sin tratamiento de IGV.
        $this->assertSame(422, $this->llamar('PUT', $reserva, ['monto' => 80, 'motivo' => 'x'], $item->id)->getStatusCode());

        $this->assertSame(200, $this->llamar('DELETE', $reserva, [], $item->id)->getStatusCode());
        $this->assertNull(AlternativaItem::find($item->id));
        $dif = $this->diferencia($reserva);
        $this->assertContains('falta_penalidad', $this->tiposDeBloqueo($dif));
        $this->assertEqualsWithDelta($totalConPenalidad - 50, (float) Alternativa::findOrFail($m->alternativa_nueva_id)->total, 0.001);
    }

    public function test_borrar_algo_que_no_es_una_penalidad_de_esta_modificacion_es_404(): void
    {
        [$reserva, $m, $beto] = $this->conBetoQuitado();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->llamar('DELETE', $reserva, [], $this->nuevo($m, 'habitacion')->id);
    }

    // ── CA8 ────────────────────────────────────────────────────────────────────

    public static function rutasProvider(): array
    {
        return [
            'POST penalidades' => ['POST', 'api/reservas/{id}/modificacion/penalidades'],
            'PUT penalidad' => ['PUT', 'api/reservas/{id}/modificacion/penalidades/{itemId}'],
            'DELETE penalidad' => ['DELETE', 'api/reservas/{id}/modificacion/penalidades/{itemId}'],
        ];
    }

    /** @dataProvider rutasProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('rutasProvider')]
    public function test_ca8_las_rutas_exigen_reservas_modificar_y_el_middleware_bloquea_sin_el_permiso(string $metodo, string $uri): void
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

    public function test_ca8_quien_no_es_el_vendedor_ni_ve_todas_las_reservas_recibe_403_por_la_policy(): void
    {
        [$reserva, $m, $beto] = $this->conBetoQuitado();

        $ajeno = User::factory()->create(['role_id' => Role::create(['name' => 'rol-ajeno-'.uniqid(), 'guard_name' => 'api'])->id]);
        $ajeno->givePermissionTo('reservas.modificar');
        Auth::guard('api')->setUser($ajeno->fresh());

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->crearPenalidad($reserva, $beto);
    }

    public function test_ca8_un_pasajero_de_otra_reserva_es_422(): void
    {
        [$reserva, $m, $beto] = $this->conBetoQuitado();
        $otra = $this->reservaConTresPasajeros();
        $ajeno = (int) $otra->pasajeros()->first()->id;

        $respuesta = $this->crearPenalidad($reserva, $ajeno);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertSame(0, AlternativaItem::where('penalidad_reserva_pasajero_id', $ajeno)->count());
    }

    public function test_sin_modificacion_abierta_responde_404(): void
    {
        $reserva = $this->reservaConTresPasajeros();

        $this->assertSame(404, $this->crearPenalidad($reserva, (int) $reserva->pasajeros->first()->id)->getStatusCode());
    }
}
