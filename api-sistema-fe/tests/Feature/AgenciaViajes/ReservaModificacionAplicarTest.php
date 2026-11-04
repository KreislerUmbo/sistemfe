<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\CotizacionController;
use App\Http\Controllers\AgenciaViajes\AlternativaController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaFacturacionController;
use App\Http\Controllers\AgenciaViajes\ReservaModificacionController;
use App\Http\Controllers\AgenciaViajes\SalidaOperativaController;
use App\Http\Requests\AgenciaViajes\AplicarModificacionRequest;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PasajeroCatalogo;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaItemCheckin;
use App\Models\AgenciaViajes\ReservaItemPasajero;
use App\Models\AgenciaViajes\ReservaModificacion;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\AgenciaViajes\ReservaVenta;
use App\Models\AgenciaViajes\SalidaMayorista;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Models\AgenciaViajes\SalidaServicio;
use App\Models\AgenciaViajes\SalidaVehiculo;
use App\Models\AgenciaViajes\Servicio;
use App\Models\Cash\Branch;
use App\Models\Client\Client;
use App\Models\Sale\Sale;
use App\Models\Sale\SerieComprobante;
use App\Models\User;
use App\Services\AgenciaViajes\PenalidadPasajeroService;
use App\Services\AgenciaViajes\AlternativaPdfService;
use App\Services\AgenciaViajes\QuitarPasajeroReservaService;
use App\Services\AgenciaViajes\SalidaOperacionService;
use App\Services\Dashboard\DashboardService;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\Feature\AgenciaViajes\Concerns\FacturaCobrando;
use Tests\TestCase;

// T-005 — Modificar reserva: aplicar la modificación (R-AGE-04, R-AGE-06 a R-AGE-09, D-AGE-030 a
// D-AGE-032). Cada test parte de una reserva creada por el camino real (aceptar → abrir la
// modificación), cambia la versión nueva como lo haría el cotizador y aplica por el controller.
// Postgres real, una transacción por test que se revierte (salvo el del lock entre dos
// conexiones, que guarda y limpia a mano). Todo el dinero se compara en centavos.
class ReservaModificacionAplicarTest extends TestCase
{
    use ConAccesoATodasLasReservas;
    use FacturaCobrando;

    private User $usuario;

    private bool $facturacionPreparada = false;

    /** @var array<string, CotizacionPasajero> */
    private array $pax = [];

    /** @var array<string, AlternativaItem> */
    private array $items = [];

    private ?ProveedorTarifa $tarifaCompartida = null;

    private ?PaquetePlantilla $tour = null;

    private ?SalidaMayorista $salidaMayorista = null;

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
        if ($this->facturacionPreparada) {
            app(\Stancl\Tenancy\Tenancy::class)->tenant = null;
            DB::connection('central')->rollBack();
        }
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    // ── Fixtures ────────────────────────────────────────────────────────────────

    private function crearTarifa(float $ventaAdulto, float $ventaNino, string $modalidad = 'privado'): ProveedorTarifa
    {
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);
        $servicio = Servicio::create(['nombre' => 'Traslado '.uniqid()]);
        $destinoServicio = DestinoServicio::create(['destino_atractivo_id' => $destino->id, 'servicio_id' => $servicio->id]);
        $proveedor = Proveedor::create(['razon_social' => 'Transportes Test SAC', 'estado' => true]);
        $proveedorServicio = ProveedorServicio::create(['proveedor_id' => $proveedor->id, 'destino_servicio_id' => $destinoServicio->id]);

        return ProveedorTarifa::create([
            'proveedor_servicio_id' => $proveedorServicio->id,
            'tipo_tarifa' => 'publica',
            'modalidad' => $modalidad,
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

    private function crearTour(): PaquetePlantilla
    {
        return $this->tour ??= PaquetePlantilla::create([
            'categoria' => 'local',
            'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE,
            'nombre' => 'Alto Mayo Full Day',
            'destino_atractivo_id' => (DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']))->id,
            'duracion_horas' => 8,
        ]);
    }

    // Reserva activa de 3 pasajeros (Ana y Beto adultos, Carla niña de 8) y servicios:
    //  traslado    por persona (100 adulto / 50 niño) = 250
    //  habitacion  tarifa fija, para todos = 200
    //  solo_carla  taller solo para Carla = 80
    //  hotel       exonerado (Amazonía) = 300
    // Total 830. Opciones: 'salida' suma una excursión compartida (120) enganchada a una salida
    // operativa; 'cupo_total' crea un mayorista elegido con esa salida de cupo.
    private function reservaConTresPasajeros(array $opciones = []): Reserva
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10000000, 99999999),
            'full_name' => 'Cliente Aplicar', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacion = Cotizacion::create([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-APL-'.uniqid(), 'cliente_id' => $clienteId,
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

        // T-073 — Pasaje aéreo por persona, manual: nadie lo reprecia solo (3 × 200 = 600).
        if (! empty($opciones['pasaje'])) {
            $this->items['pasaje'] = $this->item($alternativa, [
                'descripcion_manual' => 'Pasaje aéreo', 'modo_precio' => 'por_persona', 'pax_incluidos' => null,
                'precio_venta_snapshot' => 600, 'precio_convertido' => 600,
            ]);
        }

        if (! empty($opciones['salida'])) {
            $this->tarifaCompartida = $this->crearTarifa(120, 120, 'compartido');
            $this->items['excursion'] = $this->item($alternativa, [
                'origen_tipo' => AlternativaItem::ORIGEN_PROVEEDOR, 'proveedor_tarifa_id' => $this->tarifaCompartida->id,
                'tour_origen_id' => $this->crearTour()->id, 'dia_referencial' => 1, 'costo_snapshot' => 60,
                'precio_venta_snapshot' => 120, 'precio_convertido' => 120,
            ]);
        }

        if (isset($opciones['cupo_total'])) {
            $proveedor = Proveedor::create(['razon_social' => 'Mayorista Test SAC', 'estado' => true]);
            $this->salidaMayorista = SalidaMayorista::create([
                'proveedor_id' => $proveedor->id, 'nombre' => 'Salida test', 'fecha_salida' => '2026-11-10', 'fecha_retorno' => '2026-11-13',
                'cupo_total' => $opciones['cupo_total'], 'cupo_ocupado' => 0, 'moneda' => 'PEN', 'estado' => 'abierta',
            ]);
            OpcionMayorista::create([
                'alternativa_id' => $alternativa->id, 'proveedor_id' => $proveedor->id,
                'salida_mayorista_id' => $this->salidaMayorista->id, 'moneda' => 'PEN', 'estado' => 'elegida',
            ]);
        }

        $respuesta = app(ReservaController::class)->aceptar(new Request(), (string) $alternativa->id);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());

        return Reserva::where('alternativa_id', $alternativa->id)->firstOrFail();
    }

    // Otra reserva (otra cotización) enganchada a la misma salida operativa que la excursión.
    private function otraReservaEnLaMismaSalida(): ReservaItem
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10000000, 99999999),
            'full_name' => 'Otro cliente', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-OTRA-'.uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-11-10', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
        $item = $this->item($alternativa, [
            'origen_tipo' => AlternativaItem::ORIGEN_PROVEEDOR, 'proveedor_tarifa_id' => $this->tarifaCompartida->id,
            'tour_origen_id' => $this->tour->id, 'dia_referencial' => 1, 'costo_snapshot' => 60,
            'precio_venta_snapshot' => 120, 'precio_convertido' => 120,
        ]);
        app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        return ReservaItem::where('alternativa_item_id', $item->id)->firstOrFail();
    }

    private function abrir(Reserva $reserva): ReservaModificacion
    {
        $respuesta = app(ReservaModificacionController::class)->store((string) $reserva->id);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());

        return ReservaModificacion::findOrFail($respuesta->getData(true)['modificacion']['id']);
    }

    private function reservaConModificacion(array $opciones = []): array
    {
        $reserva = $this->reservaConTresPasajeros($opciones);

        return [$reserva, $this->abrir($reserva)];
    }

    /** El ítem de la versión nueva que viene de un servicio vendido. */
    private function nuevo(ReservaModificacion $m, string $clave): AlternativaItem
    {
        return AlternativaItem::where('alternativa_id', $m->alternativa_nueva_id)
            ->where('item_origen_id', $this->items[$clave]->id)->firstOrFail();
    }

    private function cambiarPrecio(AlternativaItem $item, float $precio): void
    {
        $item->update(['precio_venta_snapshot' => $precio, 'precio_convertido' => $precio]);
    }

    // Cambia los pasajeros de la modificación por el endpoint real del cotizador.
    private function cambiarPasajeros(ReservaModificacion $m, array $quitar = [], array $agregarEdades = []): void
    {
        $lista = [];
        foreach (CotizacionPasajero::where('cotizacion_id', $m->cotizacion_id)->orderBy('id')->get() as $copia) {
            if (in_array($copia->reserva_pasajero_id, $quitar, true)) {
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
        return (int) $reserva->pasajeros()->where('cotizacion_pasajero_id', $this->pax[$clave]->id)->firstOrFail()->id;
    }

    private function ri(Reserva $reserva, string $clave): ReservaItem
    {
        return ReservaItem::where('reserva_id', $reserva->id)->where('alternativa_item_id', $this->items[$clave]->id)->firstOrFail();
    }

    // Facturado "de mentira": solo la fila puente, como en los tests de T-003.
    private function facturar(Reserva $reserva, array $claves = [], array $pasajeroIds = []): Sale
    {
        $sale = Sale::factory()->create(['user_id' => $this->usuario->id]);
        ReservaVenta::create([
            'reserva_id' => $reserva->id, 'sale_id' => $sale->id,
            'reserva_item_ids' => collect($claves)->map(fn (string $c) => $this->ri($reserva, $c)->id)->all(),
            'reserva_pasajero_ids' => $pasajeroIds,
        ]);

        return $sale;
    }

    // Facturar de verdad (store() con cobro). Usuario con permiso, sucursal y serie propias.
    private function prepararFacturacion(): void
    {
        config(['database.connections.central.database' => 'sistemafe_test_migrations']);
        DB::purge('central');
        DB::connection('central')->beginTransaction();
        $this->facturacionPreparada = true;

        if (! DB::table('roles')->where('id', 1)->exists()) {
            DB::table('roles')->insert(['id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api', 'created_at' => now(), 'updated_at' => now()]);
            DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");
        }

        $tenant = new \App\Models\Tenant();
        $tenant->id = 'test-tenant-'.uniqid();
        $tenant->facturacion_habilitada = true;
        app(\Stancl\Tenancy\Tenancy::class)->tenant = $tenant;

        $branch = Branch::create(['name' => 'Sede Test Aplicar', 'is_active' => true]);
        SerieComprobante::create([
            'branch_id' => $branch->id, 'tipo_comprobante_codigo' => '01', 'moneda' => 'PEN', 'serie' => 'F001',
            'correlativo_actual' => 0, 'correlativo_inicial' => 1, 'fecha_inicio' => \App\Services\HoraPeru::hoyTexto(), 'activo' => true,
        ]);

        $user = User::factory()->create(['branch_id' => $branch->id]);
        $rol = Role::create(['name' => 'rol-facturar-'.uniqid(), 'guard_name' => 'api']);
        $rol->givePermissionTo(Permission::firstOrCreate(['name' => 'emitir_factura', 'guard_name' => 'api']));
        $user->assignRole($rol);
        $user->role_id = $rol->id;
        $user->save();
        $this->verTodasLasReservas($user);
        $this->usuario = $user->fresh();
    }

    // Factura de verdad a los pasajeros dados, sumando a mano los servicios "sin asignar" pedidos.
    private function facturarDeVerdad(Reserva $reserva, array $pasajeroIds, array $reservaItemIdsManual = []): Sale
    {
        $cliente = Client::factory()->create(['type_client' => 2, 'cod_tipo_doc_sunat' => '6']);
        $respuesta = $this->facturarCobrando(new Request([
            'pasajero_ids' => $pasajeroIds,
            'reserva_item_ids_manual' => $reservaItemIdsManual,
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $reserva->id);
        $cuerpo = $respuesta->getData(true);
        $this->assertSame(200, $cuerpo['code'], json_encode($cuerpo));

        return Sale::findOrFail($cuerpo['sale_id']);
    }

    private function diferencia(Reserva $reserva): array
    {
        $respuesta = app(ReservaModificacionController::class)->diferencia((string) $reserva->id);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());

        return $respuesta->getData(true);
    }

    // Llama al controller como lo haría la ruta: el FormRequest valida primero.
    private function aplicar(Reserva $reserva, ?string $huella = null): JsonResponse
    {
        $huella ??= $this->diferencia($reserva)['huella'];

        try {
            $peticion = AplicarModificacionRequest::create('/', 'POST', ['huella' => $huella]);
            $peticion->setContainer(app())->setRedirector(app('redirect'));
            $peticion->validateResolved();

            return app(ReservaModificacionController::class)->aplicar($peticion, (string) $reserva->id);
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }
    }

    // Lo que hace el vendedor en la pantalla: al cambiar los pasajeros, los servicios de tarifa fija
    // quedan "para revisar" y los confirma uno por uno antes de poder aplicar (T-003).
    private function revisarTodo(Reserva $reserva): void
    {
        foreach ($this->diferencia($reserva)['lineas'] as $linea) {
            if ($linea['estado'] === 'para_revisar' && ! $linea['sin_pasajeros'] && ! $linea['revisado']) {
                $respuesta = app(ReservaModificacionController::class)->marcarRevisado((string) $reserva->id, (string) $linea['alternativa_item_id']);
                $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
            }
        }
    }

    private function aplicarOk(Reserva $reserva): array
    {
        $this->revisarTodo($reserva);
        $respuesta = $this->aplicar($reserva);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());

        return $respuesta->getData(true);
    }

    // Total que facturar leería hoy: el precio en vivo del ítem al que apunta cada servicio, en centavos.
    private function totalDeLaReserva(Reserva $reserva): int
    {
        return (int) round(ReservaItem::where('reserva_id', $reserva->id)->with('alternativaItem')->get()
            ->sum(fn (ReservaItem $ri) => (float) $ri->alternativaItem->total_convertido) * 100);
    }

    // Todo lo que aplicar podría tocar, para comprobar "nada cambia".
    private function foto(Reserva $reserva, ReservaModificacion $m): array
    {
        $itemIds = ReservaItem::where('reserva_id', $reserva->id)->pluck('id');

        return [
            'reserva' => Reserva::find($reserva->id)->only(['alternativa_id', 'estado', 'mayorista_elegida_id']),
            'pasajeros' => ReservaPasajero::where('reserva_id', $reserva->id)->orderBy('id')->get(['id', 'cotizacion_pasajero_id', 'tipo_pax'])->toArray(),
            'items' => ReservaItem::where('reserva_id', $reserva->id)->orderBy('id')->get(['id', 'alternativa_item_id', 'fecha', 'fecha_origen', 'salida_operativa_id', 'tip_afe_igv'])->toArray(),
            'asignaciones' => ReservaItemPasajero::whereIn('reserva_item_id', $itemIds)->orderBy('id')->get()->toArray(),
            'alternativas' => Alternativa::whereIn('id', [$m->alternativa_vendida_id, $m->alternativa_nueva_id])->orderBy('id')->pluck('estado', 'id')->all(),
            'items_alternativa_nueva' => AlternativaItem::where('alternativa_id', $m->alternativa_nueva_id)->count(),
            'modificacion' => ReservaModificacion::find($m->id)->only(['estado', 'aplicado_por']),
            'cupo' => SalidaMayorista::orderBy('id')->pluck('cupo_ocupado', 'id')->all(),
            'salidas' => SalidaOperativa::count(),
        ];
    }

    private function penalidad(ReservaModificacion $m, int $reservaPasajeroId, float $monto = 50): AlternativaItem
    {
        return app(PenalidadPasajeroService::class)->crear($m, [
            'reserva_pasajero_id' => $reservaPasajeroId, 'monto' => $monto, 'motivo' => 'No se presentó',
            'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ]);
    }

    // ── CA1 ────────────────────────────────────────────────────────────────────

    public function test_ca1_agregar_un_adulto_suma_un_pasajero_y_el_precio_exacto_y_facturar_cobra_el_monto_nuevo(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $antes = $this->totalDeLaReserva($reserva);
        $this->assertSame(83000, $antes);
        $trasladoId = $this->ri($reserva, 'traslado')->id;
        $this->cambiarPasajeros($m, [], [30]);

        $this->assertSame(10000, $this->diferencia($reserva)['totales']['diferencia']);
        $cuerpo = $this->aplicarOk($reserva);

        $this->assertSame('aplicada', $cuerpo['modificacion']['estado']);
        $reserva->refresh();
        $this->assertSame(4, $reserva->pasajeros()->count());
        // El pasajero nuevo nace vinculado a su copia, y los tres de siempre quedan vinculados a SU copia.
        $copiaNueva = CotizacionPasajero::where('cotizacion_id', $m->cotizacion_id)->orderByDesc('id')->firstOrFail();
        $nuevoPasajero = ReservaPasajero::findOrFail($copiaNueva->reserva_pasajero_id);
        $this->assertSame($copiaNueva->id, $nuevoPasajero->cotizacion_pasajero_id);
        $this->assertSame('adulto', $nuevoPasajero->tipo_pax);
        $this->assertSame(
            4,
            ReservaPasajero::where('reserva_id', $reserva->id)
                ->whereIn('cotizacion_pasajero_id', CotizacionPasajero::where('cotizacion_id', $m->cotizacion_id)->pluck('id'))->count(),
            'los cuatro pasajeros apuntan a una copia de la modificación'
        );

        // El tour (traslado) apunta al ítem nuevo y el total sube exactamente un adulto.
        $this->assertSame($this->nuevo($m, 'traslado')->id, ReservaItem::find($trasladoId)->alternativa_item_id);
        $this->assertSame($antes + 10000, $this->totalDeLaReserva($reserva));

        // Facturar después cobra el monto nuevo: traslado (350) + habitación (200) + taller de Carla (80).
        $this->prepararFacturacion();
        $pasajeros = ReservaPasajero::where('reserva_id', $reserva->id)->pluck('id')->all();
        $sale = $this->facturarDeVerdad($reserva, $pasajeros, [
            ReservaItem::where('reserva_id', $reserva->id)->where('alternativa_item_id', $this->nuevo($m, 'traslado')->id)->value('id'),
            ReservaItem::where('reserva_id', $reserva->id)->where('alternativa_item_id', $this->nuevo($m, 'habitacion')->id)->value('id'),
        ]);
        $this->assertSame(63000, (int) round((float) $sale->total * 100));
    }

    // T-007: el historial del detalle de la reserva lista la aplicada con quién, cuándo y la diferencia.
    public function test_t007_el_detalle_de_la_reserva_lista_la_modificacion_aplicada(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $this->cambiarPasajeros($m, [], [30]);
        $this->aplicarOk($reserva);

        $detalle = app(ReservaController::class)->show((string) $reserva->id)->getData(true);

        $this->assertNull($detalle['modificacion_abierta']);
        $this->assertCount(1, $detalle['modificaciones_aplicadas']);
        $aplicada = $detalle['modificaciones_aplicadas'][0];
        $this->assertSame($m->id, $aplicada['id']);
        $this->assertSame($this->usuario->name, $aplicada['aplicado_por']);
        $this->assertSame(10000, $aplicada['diferencia_centavos']);
        $this->assertNotNull($aplicada['aplicado_en']);

        $cotizacion = app(CotizacionController::class)->show((string) $m->cotizacion_id)->getData(true);
        $this->assertSame('aplicada', $cotizacion['modificacion']['estado']);
    }

    // ── CA2 ────────────────────────────────────────────────────────────────────

    public function test_ca2_quitar_un_pasajero_con_penalidad_lo_saca_y_la_penalidad_queda_como_servicio_facturable(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $beto = $this->reservaPasajeroId($reserva, 'beto');
        $ana = $this->reservaPasajeroId($reserva, 'ana');
        $this->cambiarPasajeros($m, [$beto]);
        $penalidad = $this->penalidad($m, $beto, 50);

        $this->aplicarOk($reserva);

        $this->assertNull(ReservaPasajero::find($beto), 'el pasajero salió de la reserva');
        $this->assertSame(2, $reserva->pasajeros()->count());
        // La penalidad perdió su columna (FK nullOnDelete) pero su servicio de reserva ya existía.
        $this->assertNull($penalidad->fresh()->penalidad_reserva_pasajero_id);
        $servicio = ReservaItem::where('reserva_id', $reserva->id)->where('alternativa_item_id', $penalidad->id)->first();
        $this->assertNotNull($servicio, 'la penalidad es un servicio de la reserva');
        $this->assertSame('10', $servicio->tip_afe_igv);
        $this->assertSame('nacional', $servicio->destino_tributario);
        $this->assertSame(0, ReservaItemPasajero::where('reserva_item_id', $servicio->id)->count(), 'sin pasajeros: servicio sin asignar');

        // Se factura: a quien elija el vendedor, sumándola a mano. Cobra exactamente el monto.
        $this->prepararFacturacion();
        $sale = $this->facturarDeVerdad($reserva, [$ana], [$servicio->id]);
        $this->assertSame(5000, (int) round((float) $sale->total * 100));
    }

    // ── CA3 ────────────────────────────────────────────────────────────────────

    public function test_ca3_un_servicio_facturado_que_sube_sigue_en_la_version_vendida_y_aparece_el_ajuste(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $this->facturar($reserva, ['hotel']);
        $hotelRi = $this->ri($reserva, 'hotel');
        $nuevoHotel = $this->nuevo($m, 'hotel');
        $this->cambiarPrecio($nuevoHotel, 350);

        $this->aplicarOk($reserva);

        // El facturado no se tocó.
        $this->assertSame($this->items['hotel']->id, $hotelRi->fresh()->alternativa_item_id);
        // Y hay un servicio "Ajuste — Hotel" por la diferencia, con el tratamiento del hotel.
        $ajuste = AlternativaItem::where('alternativa_id', $m->alternativa_nueva_id)->where('ajuste_de_item_id', $nuevoHotel->id)->firstOrFail();
        $this->assertStringContainsString('Ajuste — Hotel', $ajuste->descripcion_manual);
        $this->assertSame(5000, (int) round((float) $ajuste->precio_convertido * 100));
        $this->assertSame(1, $ajuste->cantidad);
        $this->assertSame('20', $ajuste->tip_afe_igv);
        $this->assertSame('amazonia', $ajuste->destino_tributario);
        $servicio = ReservaItem::where('reserva_id', $reserva->id)->where('alternativa_item_id', $ajuste->id)->firstOrFail();
        $this->assertSame('20', $servicio->tip_afe_igv);
        $this->assertSame('amazonia', $servicio->destino_tributario);
        // Total de la reserva = lo vendido + el ajuste (el ítem nuevo no se cuenta, nadie lo lee).
        $this->assertSame(83000 + 5000, $this->totalDeLaReserva($reserva));
        $this->assertFalse(ReservaVenta::itemIdsFacturados($reserva->id)->contains($servicio->id), 'el ajuste se factura aparte');

        // Facturable aparte: facturar lo ofrece como servicio sin asignar.
        $this->prepararFacturacion();
        $vista = app(ReservaFacturacionController::class)->prepararFactura(new Request([
            'pasajero_ids' => ReservaPasajero::where('reserva_id', $reserva->id)->pluck('id')->all(),
        ]), (string) $reserva->id)->getData(true);
        $this->assertContains($servicio->id, collect($vista['items_sin_asignar_disponibles'])->pluck('reserva_item_id')->all());
    }

    public function test_ca3_aplicar_dos_modificaciones_seguidas_no_cuenta_el_ajuste_dos_veces(): void
    {
        [$reserva, $m1] = $this->reservaConModificacion();
        $this->facturar($reserva, ['hotel']);
        $this->cambiarPrecio($this->nuevo($m1, 'hotel'), 350);
        $this->aplicarOk($reserva);
        $hotelNuevo1 = $this->nuevo($m1, 'hotel');

        // El servicio nuevo del hotel NO queda como pendiente de sincronizar (se facturaría dos veces).
        $sync = app(ReservaController::class)->sincronizarItems((string) $reserva->id);
        $this->assertSame(422, $sync->getStatusCode());
        $this->assertSame([], app(ReservaController::class)->show((string) $reserva->id)->getData(true)['items_pendientes_sincronizar']);

        // Segunda modificación: la copia deja el ajuste afuera.
        $m2 = $this->abrir($reserva);
        $this->assertStringEndsWith('-M2', Cotizacion::find($m2->cotizacion_id)->codigo);
        $this->assertSame(0, AlternativaItem::where('alternativa_id', $m2->alternativa_nueva_id)->whereNotNull('ajuste_de_item_id')->count());
        $this->assertSame(4, AlternativaItem::where('alternativa_id', $m2->alternativa_nueva_id)->count());
        $this->assertFalse($this->diferencia($reserva)['hay_cambios']);

        $hotelM2 = AlternativaItem::where('alternativa_id', $m2->alternativa_nueva_id)->where('item_origen_id', $hotelNuevo1->id)->firstOrFail();
        $this->cambiarPrecio($hotelM2, 380);
        $dif = $this->diferencia($reserva);

        // Solo +30 sobre lo vendido (350), no +80: el ajuste de 50 no se suma otra vez.
        $this->assertSame(88000, $dif['totales']['vendido']);
        $this->assertSame(91000, $dif['totales']['nuevo']);
        $this->assertSame(3000, $dif['totales']['diferencia']);
        $this->assertSame(['ajuste'], collect($dif['lineas'])->pluck('estado')->reject(fn ($e) => $e === 'igual')->values()->all());
        $this->assertSame([], $dif['bloqueos']);

        $this->aplicarOk($reserva);

        $this->assertSame(2, AlternativaItem::whereIn('alternativa_id', [$m1->alternativa_nueva_id, $m2->alternativa_nueva_id])->whereNotNull('ajuste_de_item_id')->count());
        $this->assertSame($this->items['hotel']->id, $this->ri($reserva, 'hotel')->alternativa_item_id, 'el hotel facturado sigue en la versión vendida original');
        $this->assertSame(91000, $this->totalDeLaReserva($reserva), '300 facturado + 50 + 30 de ajustes + el resto');
    }

    // ── CA4 ────────────────────────────────────────────────────────────────────

    public function test_ca4_los_datos_operativos_se_conservan(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $ana = $this->reservaPasajeroId($reserva, 'ana');
        $carla = $this->reservaPasajeroId($reserva, 'carla');

        // Pasajero con datos del catálogo.
        $catalogo = PasajeroCatalogo::create(['nombre' => 'Ana Pérez', 'nacionalidad' => 'PE']);
        ReservaPasajero::whereKey($ana)->update(['nombre' => 'Ana Pérez', 'documento' => '12345678', 'pasajero_catalogo_id' => $catalogo->id]);
        // Fecha manual, proveedor reasignado y tratamiento tributario corregido a mano en el traslado.
        $otraTarifa = $this->crearTarifa(90, 45);
        $traslado = $this->ri($reserva, 'traslado');
        $traslado->update([
            'fecha' => '2026-11-12', 'fecha_origen' => 'manual',
            'proveedor_tarifa_original_id' => $traslado->proveedor_tarifa_id, 'proveedor_tarifa_id' => $otraTarifa->id,
            'tip_afe_igv' => '20', 'destino_tributario' => 'amazonia', 'motivo_override_tributario' => 'Confirmado con el cliente',
        ]);
        // Check-in de Carla en el taller; en la versión nueva se suma el pasajero nuevo al taller.
        $taller = $this->ri($reserva, 'solo_carla');
        ReservaItemCheckin::create(['reserva_item_id' => $taller->id, 'reserva_pasajero_id' => $carla, 'checkin_hora' => now()]);

        $this->cambiarPasajeros($m, [], [30]);
        $copiaNueva = CotizacionPasajero::where('cotizacion_id', $m->cotizacion_id)->whereNull('reserva_pasajero_id')->firstOrFail();
        $copiaCarla = CotizacionPasajero::where('cotizacion_id', $m->cotizacion_id)->where('reserva_pasajero_id', $carla)->firstOrFail();
        $this->nuevo($m, 'solo_carla')->update(['pax_incluidos' => [$copiaCarla->id, $copiaNueva->id]]);

        $this->aplicarOk($reserva);

        $traslado->refresh();
        $this->assertSame($this->nuevo($m, 'traslado')->id, $traslado->alternativa_item_id, 'el precio sí viene de la versión nueva');
        $this->assertSame('2026-11-12', $traslado->fecha->toDateString());
        $this->assertSame('manual', $traslado->fecha_origen);
        $this->assertSame($otraTarifa->id, $traslado->proveedor_tarifa_id, 'el proveedor reasignado sigue');
        $this->assertSame('20', $traslado->tip_afe_igv);
        $this->assertSame('amazonia', $traslado->destino_tributario);

        $this->assertTrue(
            ReservaItemCheckin::where('reserva_item_id', $taller->id)->where('reserva_pasajero_id', $carla)->exists(),
            'el check-in de quien sigue en el servicio no se pierde'
        );
        $this->assertSame(2, ReservaItemPasajero::where('reserva_item_id', $taller->id)->count(), 'el pasajero nuevo entra al taller');

        $pasajeroAna = ReservaPasajero::findOrFail($ana);
        $this->assertSame('Ana Pérez', $pasajeroAna->nombre);
        $this->assertSame('12345678', $pasajeroAna->documento);
        $this->assertSame($catalogo->id, $pasajeroAna->pasajero_catalogo_id);
    }

    // ── CA5 ────────────────────────────────────────────────────────────────────

    public function test_ca5_una_salida_compartida_con_otra_reserva_sigue_existiendo_y_la_otra_no_cambia(): void
    {
        [$reserva, $m] = $this->reservaConModificacion(['salida' => true]);
        $otra = $this->otraReservaEnLaMismaSalida();
        $excursion = $this->ri($reserva, 'excursion');
        $salidaId = $excursion->salida_operativa_id;
        $this->assertNotNull($salidaId);
        $this->assertSame($salidaId, $otra->salida_operativa_id);
        $this->assertSame(1, SalidaOperativa::count());

        // Sube el precio de la excursión: el servicio se re-apunta y conserva su salida.
        $this->cambiarPrecio($this->nuevo($m, 'excursion'), 150);
        $this->aplicarOk($reserva);
        $this->assertSame($salidaId, $excursion->fresh()->salida_operativa_id);
        $this->assertSame($salidaId, $otra->fresh()->salida_operativa_id);

        // Ahora se quita la excursión en otra modificación: la salida compartida NO se borra.
        $m2 = $this->abrir($reserva);
        $copia = AlternativaItem::where('alternativa_id', $m2->alternativa_nueva_id)->where('proveedor_tarifa_id', $this->tarifaCompartida->id)->firstOrFail();
        $copia->delete();
        $this->aplicarOk($reserva);

        $this->assertNull(ReservaItem::find($excursion->id), 'la excursión salió de la reserva');
        $this->assertNotNull(SalidaOperativa::find($salidaId), 'la salida sigue existiendo');
        $this->assertSame($salidaId, $otra->fresh()->salida_operativa_id, 'la otra reserva no cambia');
    }

    // ── T-040 (CA3c, CA4): avisos a los proveedores de las salidas ────────────

    public function test_t040_quitar_un_pasajero_avisa_a_cada_salida_de_la_reserva(): void
    {
        [$reserva, $m] = $this->reservaConModificacion(['salida' => true]);
        $salidaId = $this->ri($reserva, 'excursion')->salida_operativa_id;
        $proveedor = Proveedor::create(['razon_social' => 'El Mirador '.uniqid(), 'nombre_comercial' => 'El Mirador', 'estado' => true]);
        SalidaServicio::create(['salida_operativa_id' => $salidaId, 'nombre' => 'Almuerzo', 'proveedor_id' => $proveedor->id]);
        $beto = $this->reservaPasajeroId($reserva, 'beto');
        $this->cambiarPasajeros($m, [$beto]);
        $this->penalidad($m, $beto);

        $respuesta = $this->aplicarOk($reserva);

        $this->assertSame([[
            'salida_id' => $salidaId,
            'tour' => 'Alto Mayo Full Day',
            'fecha' => '10/11/2026',
            'avisos' => ['El Mirador (Almuerzo): ahora son 2, no 3.'],
        ]], $respuesta['avisos_salidas']);
    }

    public function test_t040_sin_salidas_aplicar_no_trae_avisos(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $beto = $this->reservaPasajeroId($reserva, 'beto');
        $this->cambiarPasajeros($m, [$beto]);
        $this->penalidad($m, $beto);

        $this->assertSame([], $this->aplicarOk($reserva)['avisos_salidas']);
    }

    // ── CA6 ────────────────────────────────────────────────────────────────────

    public function test_ca6_el_cupo_del_mayorista_sube_y_baja_exactamente_la_diferencia_de_pasajeros(): void
    {
        [$reserva, $m] = $this->reservaConModificacion(['cupo_total' => 10]);
        $this->assertSame(3, $this->salidaMayorista->fresh()->cupo_ocupado);

        // Suben 2 pasajeros.
        $beto = $this->reservaPasajeroId($reserva, 'beto');
        $this->cambiarPasajeros($m, [], [30, 25]);
        $cuerpo = $this->aplicarOk($reserva);
        $this->assertSame(5, $this->salidaMayorista->fresh()->cupo_ocupado);
        $this->assertFalse($cuerpo['alerta_cupo_excedido']);

        // Baja 1 pasajero (con su penalidad).
        $m2 = $this->abrir($reserva);
        $this->cambiarPasajeros($m2, [$beto]);
        $this->penalidad($m2, $beto, 0);
        $this->aplicarOk($reserva);
        $this->assertSame(4, $this->salidaMayorista->fresh()->cupo_ocupado);
    }

    public function test_ca6_avisa_si_el_cupo_se_excede(): void
    {
        [$reserva, $m] = $this->reservaConModificacion(['cupo_total' => 3]);
        $this->cambiarPasajeros($m, [], [30]);

        $cuerpo = $this->aplicarOk($reserva);

        $this->assertSame(4, $this->salidaMayorista->fresh()->cupo_ocupado);
        $this->assertTrue($cuerpo['alerta_cupo_excedido']);
    }

    // ── CA7 ────────────────────────────────────────────────────────────────────

    public function test_ca7_con_una_huella_vieja_responde_422_y_no_cambia_nada(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $this->cambiarPrecio($this->nuevo($m, 'habitacion'), 210);
        $huellaVista = $this->diferencia($reserva)['huella'];
        // Después de que el usuario vio la diferencia, el vendedor cambia otro precio.
        $this->cambiarPrecio($this->nuevo($m, 'hotel'), 320);
        $antes = $this->foto($reserva, $m);

        $respuesta = $this->aplicar($reserva, $huellaVista);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertStringContainsString('La reserva cambió desde que viste la diferencia', $respuesta->getData(true)['message']);
        $this->assertEquals($antes, $this->foto($reserva, $m));
    }

    public function test_ca7_si_se_factura_despues_de_ver_la_diferencia_no_se_aplica_y_nada_queda_a_medias(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $this->cambiarPrecio($this->nuevo($m, 'habitacion'), 210);
        $huellaVista = $this->diferencia($reserva)['huella'];

        $this->facturar($reserva, ['habitacion']);
        $antes = $this->foto($reserva, $m);

        $respuesta = $this->aplicar($reserva, $huellaVista);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertEquals($antes, $this->foto($reserva, $m));
        $this->assertSame(1, ReservaVenta::where('reserva_id', $reserva->id)->count());

        // Con la huella nueva (la habitación ya está facturada: sube y sale como ajuste) sí se aplica,
        // y lo facturado queda en la versión vendida.
        $this->aplicarOk($reserva);
        $this->assertSame($this->items['habitacion']->id, $this->ri($reserva, 'habitacion')->alternativa_item_id);
    }

    public function test_ca7_aplicar_dos_veces_la_misma_modificacion_no_duplica_nada(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $this->cambiarPasajeros($m, [], [30]);
        $this->revisarTodo($reserva);
        $huella = $this->diferencia($reserva)['huella'];
        $this->aplicarOk($reserva);
        $despues = $this->foto($reserva, $m);

        // El controller ya no la encuentra abierta; el servicio la rechaza con la misma huella.
        $this->assertSame(404, $this->aplicar($reserva, $huella)->getStatusCode());
        try {
            app(\App\Services\AgenciaViajes\ModificacionReservaService::class)->aplicar($m->fresh(), $huella, $this->usuario);
            $this->fail('debía rechazar una modificación ya aplicada');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertEquals($despues, $this->foto($reserva, $m));
        $this->assertSame(4, $reserva->pasajeros()->count());
    }

    public function test_ca7_lo_primero_que_se_bloquea_es_la_fila_de_la_reserva_como_facturar_y_cancelar(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $this->cambiarPasajeros($m, [], [30]);
        $this->revisarTodo($reserva);
        $huella = $this->diferencia($reserva)['huella'];

        $consultas = [];
        DB::listen(function ($consulta) use (&$consultas) {
            $consultas[] = $consulta->sql;
        });
        $this->assertSame(200, $this->aplicar($reserva, $huella)->getStatusCode());

        $conLock = array_values(array_filter($consultas, fn (string $sql) => preg_match('/for update/i', $sql) === 1));
        $this->assertNotEmpty($conLock);
        $this->assertMatchesRegularExpression('/^select .* from "reserva" where .*"id" = \?.* for update$/i', $conLock[0], 'el primer lock es el de la reserva');
        // Y se toma antes de escribir nada: el único SQL que lo precede es el del controller (leer la reserva y la modificación).
        $anteriores = array_slice($consultas, 0, array_search($conLock[0], $consultas, true));
        $this->assertSame([], array_values(array_filter($anteriores, fn (string $sql) => preg_match('/^(insert|update|delete)/i', $sql) === 1)));
    }

    // Dos conexiones de verdad: si otra pestaña tiene tomada la reserva (como cuando factura o cancela),
    // aplicar espera ese mismo lock. Guarda de verdad y limpia a mano (igual que ReservaAceptarConcurrencyTest).
    public function test_ca7_aplicar_espera_el_mismo_lock_de_la_reserva_que_toman_facturar_y_cancelar(): void
    {
        $reserva = $this->reservaConTresPasajeros();
        $cotizacionId = $reserva->alternativa->cotizacion_id;
        $clienteId = DB::table('cotizaciones')->where('id', $cotizacionId)->value('cliente_id');

        config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
        DB::purge('pgsql_b');
        $permisosQueYaEstaban = DB::connection('pgsql_b')->table('permissions')->pluck('id')->all();
        $itemIds = AlternativaItem::where('alternativa_id', $reserva->alternativa_id)->pluck('id')->all();
        $tarifaIds = ProveedorTarifa::pluck('id')->all();

        DB::commit();

        $codigoError = null;
        $bloqueada = false;

        try {
            DB::connection('pgsql_b')->beginTransaction();
            DB::connection('pgsql_b')->select('select * from reserva where id = ? for update', [$reserva->id]);

            DB::beginTransaction();
            DB::statement("SET LOCAL lock_timeout = '300ms'");

            try {
                // Solo hace falta el id de la reserva: el lock de la reserva es lo primero que toma aplicar.
                app(\App\Services\AgenciaViajes\ModificacionReservaService::class)
                    ->aplicar(new ReservaModificacion(['reserva_id' => $reserva->id]), str_repeat('a', 64), $this->usuario);
            } catch (\Throwable $error) {
                $bloqueada = true;
                $codigoError = $error->getCode();
            }

            DB::rollBack();
            DB::connection('pgsql_b')->rollBack();

            $this->assertTrue($bloqueada, 'aplicar debe esperar el lock de la reserva.');
            $this->assertSame('55P03', (string) $codigoError, 'Postgres corta la espera por lock_timeout.');
        } finally {
            if (DB::connection('pgsql_b')->transactionLevel() > 0) {
                DB::connection('pgsql_b')->rollBack();
            }
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $alternativaId = $reserva->alternativa_id;
            DB::table('reserva_item_pasajero')->whereIn('reserva_item_id', ReservaItem::where('reserva_id', $reserva->id)->pluck('id'))->delete();
            ReservaItem::where('reserva_id', $reserva->id)->delete();
            ReservaPasajero::where('reserva_id', $reserva->id)->delete();
            Reserva::whereKey($reserva->id)->delete();
            AlternativaItem::whereIn('id', $itemIds)->delete();
            Alternativa::whereKey($alternativaId)->delete();
            CotizacionPasajero::where('cotizacion_id', $cotizacionId)->delete();
            DB::table('cotizaciones')->where('id', $cotizacionId)->delete();
            // T-017: aceptar() crea la ficha de pasajero del cliente; la FK impide borrar el cliente sin ella.
            DB::table('pasajero_documentos')->whereIn('pasajero_catalogo_id', DB::table('pasajeros_catalogo')->where('cliente_id', $clienteId)->pluck('id'))->delete();
            DB::table('pasajeros_catalogo')->where('cliente_id', $clienteId)->delete();
            DB::table('clients')->where('id', $clienteId)->delete();
            $nuevasTarifas = ProveedorTarifa::whereNotIn('id', $tarifaIds)->get();
            foreach ($nuevasTarifas as $tarifa) {
                $proveedorServicio = ProveedorServicio::find($tarifa->proveedor_servicio_id);
                $tarifa->delete();
                if ($proveedorServicio) {
                    $destinoServicioId = $proveedorServicio->destino_servicio_id;
                    $proveedorId = $proveedorServicio->proveedor_id;
                    $proveedorServicio->delete();
                    $servicioId = DestinoServicio::whereKey($destinoServicioId)->value('servicio_id');
                    DestinoServicio::whereKey($destinoServicioId)->delete();
                    Servicio::whereKey($servicioId)->delete();
                    Proveedor::whereKey($proveedorId)->delete();
                }
            }
            $roleId = $this->usuario->role_id;
            DB::table('role_audit_logs')->where('target_type', 'user')->where('target_id', $this->usuario->id)->delete();
            DB::table('model_has_permissions')->where('model_id', $this->usuario->id)->where('model_type', User::class)->delete();
            DB::table('model_has_roles')->where('model_id', $this->usuario->id)->where('model_type', User::class)->delete();
            DB::table('users')->where('id', $this->usuario->id)->delete();
            DB::table('roles')->where('id', $roleId)->delete();
            DB::table('permissions')->whereNotIn('id', $permisosQueYaEstaban)->delete();
            DB::beginTransaction();
        }
    }

    // ── CA8 ────────────────────────────────────────────────────────────────────

    public function test_ca8_con_bloqueos_responde_422_con_el_motivo_y_no_cambia_nada(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $beto = $this->reservaPasajeroId($reserva, 'beto');
        $this->cambiarPasajeros($m, [$beto]); // quitado sin penalidad
        $this->revisarTodo($reserva);
        $antes = $this->foto($reserva, $m);

        $respuesta = $this->aplicar($reserva);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertStringContainsString('penalidad', $respuesta->getData(true)['message']);
        $this->assertEquals($antes, $this->foto($reserva, $m));
    }

    // T-073 — Como la reserva 29 de agencia-demo: pasaje aéreo por persona ya facturado y se
    // agrega un adulto. Antes salía "igual", se aplicaba sin avisar y el pasajero nuevo viajaba
    // sin cobrarse. Ahora bloquea; con el precio corregido en el cotizador se aplica como ajuste.
    public function test_t073_pasaje_facturado_con_un_pasajero_agregado_no_se_aplica_hasta_revisarlo(): void
    {
        [$reserva, $m] = $this->reservaConModificacion(['pasaje' => true]);
        $todos = collect(['ana', 'beto', 'carla'])->map(fn ($c) => $this->reservaPasajeroId($reserva, $c))->all();
        $this->facturar($reserva, ['pasaje'], $todos);
        $this->cambiarPasajeros($m, [], [30]);
        // El vendedor confirma las tarifas fijas (habitación, hotel…), pero no el pasaje.
        foreach ($this->diferencia($reserva)['lineas'] as $linea) {
            if ($linea['estado'] === 'para_revisar' && $linea['alternativa_item_id'] !== $this->nuevo($m, 'pasaje')->id) {
                app(ReservaModificacionController::class)->marcarRevisado((string) $reserva->id, (string) $linea['alternativa_item_id']);
            }
        }
        $antes = $this->foto($reserva, $m);

        $respuesta = $this->aplicar($reserva);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertStringContainsString('Pasaje aéreo', $respuesta->getData(true)['message']);
        $this->assertEquals($antes, $this->foto($reserva, $m));

        // El vendedor sube el precio del pasaje (4 × 200): se aplica y el cuarto se cobra como ajuste.
        $this->cambiarPrecio($this->nuevo($m, 'pasaje'), 800);
        $cuerpo = $this->aplicarOk($reserva);
        $this->assertSame('aplicada', $cuerpo['modificacion']['estado']);
        $ajuste = AlternativaItem::where('alternativa_id', $m->alternativa_nueva_id)->where('ajuste_de_item_id', $this->nuevo($m, 'pasaje')->id)->first();
        $this->assertNotNull($ajuste);
        $this->assertSame(20000, (int) round((float) $ajuste->precio_convertido * 100));
    }

    public function test_ca8_sin_cambios_responde_422_y_no_cambia_nada(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $antes = $this->foto($reserva, $m);

        $respuesta = $this->aplicar($reserva);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertStringContainsString('no tiene cambios', $respuesta->getData(true)['message']);
        $this->assertEquals($antes, $this->foto($reserva, $m));
    }

    public function test_ca8_la_huella_es_obligatoria(): void
    {
        [$reserva] = $this->reservaConModificacion();

        $this->assertSame(422, $this->aplicar($reserva, '')->getStatusCode());
        $this->assertSame(422, $this->aplicar($reserva, 'corta')->getStatusCode());
    }

    // ── CA9 ────────────────────────────────────────────────────────────────────

    public function test_ca9_si_falla_un_paso_intermedio_la_reserva_queda_exactamente_como_estaba(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $beto = $this->reservaPasajeroId($reserva, 'beto');
        $this->cambiarPasajeros($m, [$beto], [30]);
        $this->penalidad($m, $beto, 50);
        $this->cambiarPrecio($this->nuevo($m, 'habitacion'), 260);
        $this->revisarTodo($reserva);
        $antes = $this->foto($reserva, $m);

        // Quitar al pasajero es el último paso antes del cambio de versión: ya se re-vincularon
        // pasajeros, se re-apuntaron servicios y se crearon la penalidad y el pasajero nuevo.
        $this->mock(QuitarPasajeroReservaService::class, function ($mock) {
            $mock->shouldReceive('quitar')->once()->andThrow(new \RuntimeException('fallo forzado'));
        });

        try {
            $this->aplicar($reserva);
            $this->fail('debía propagar el fallo');
        } catch (\RuntimeException $e) {
            $this->assertSame('fallo forzado', $e->getMessage());
        }

        $this->assertEquals($antes, $this->foto($reserva, $m));
        $this->assertSame(3, $reserva->pasajeros()->count());
    }

    // ── CA10 ───────────────────────────────────────────────────────────────────

    public function test_ca10_en_la_cotizacion_original_no_se_puede_aceptar_otra_alternativa_y_se_puede_abrir_otra_modificacion(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $vendida = Alternativa::findOrFail($m->alternativa_vendida_id);
        $originalId = $vendida->cotizacion_id;
        $this->cambiarPasajeros($m, [], [30]);
        $this->aplicarOk($reserva);

        // Otra alternativa de la cotización original y una copia (duplicada) de la vendida.
        $otra = Alternativa::create([
            'cotizacion_id' => $originalId, 'nombre' => 'Otra', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
        [$duplicada] = app(\App\Services\AgenciaViajes\AlternativaCopiaService::class)->copiar($vendida->fresh(), $originalId, 'Copia', $vendida->items()->get());

        foreach ([$otra, $duplicada] as $alternativa) {
            $respuesta = app(ReservaController::class)->aceptar(new Request(), (string) $alternativa->id);
            $this->assertSame(422, $respuesta->getStatusCode(), $respuesta->getContent());
            $this->assertSame('Esta cotización ya tiene una reserva activa.', $respuesta->getData(true)['message']);
        }

        // Y se puede abrir una modificación nueva sobre la reserva (desde la versión vigente).
        $m2 = $this->abrir($reserva);
        $this->assertSame($reserva->fresh()->alternativa_id, $m2->alternativa_vendida_id);
        $this->assertSame(ReservaModificacion::ESTADO_ABIERTA, $m2->estado);
        // Mientras sigue aplicada y activa, tampoco se acepta en la cotización de la primera modificación.
        $this->assertSame(422, app(ReservaController::class)->aceptar(new Request(), (string) $otra->id)->getStatusCode());
    }

    public function test_ca10_cancelada_la_reserva_la_cotizacion_original_vuelve_a_poder_aceptar(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $originalId = Alternativa::findOrFail($m->alternativa_vendida_id)->cotizacion_id;
        $this->cambiarPasajeros($m, [], [30]);
        $this->aplicarOk($reserva);
        $reserva->refresh()->update(['estado' => 'cancelada']);
        $otra = Alternativa::create([
            'cotizacion_id' => $originalId, 'nombre' => 'Otra', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);

        $this->assertSame(200, app(ReservaController::class)->aceptar(new Request(), (string) $otra->id)->getStatusCode());
    }

    // ── T-072: la cotización original tras aplicar, el código en el tablero y el ajuste ────────

    // La cotización de la versión vendida (la original) de una modificación.
    private function cotizacionOriginal(ReservaModificacion $m): Cotizacion
    {
        return Cotizacion::with(Cotizacion::RELACIONES_ESTADO_RESUMEN)
            ->findOrFail(Alternativa::findOrFail($m->alternativa_vendida_id)->cotizacion_id);
    }

    private function reservadasDelInicio(): int
    {
        $metodo = new \ReflectionMethod(DashboardService::class, 'cotizaciones');
        $metodo->setAccessible(true);

        return $metodo->invoke(app(DashboardService::class), \App\Services\HoraPeru::hoy()->toImmutable())['reservadas'];
    }

    public function test_cotizacion_original_tras_aplicar_ca1_sigue_reservada_en_el_listado_y_en_el_inicio(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $original = $this->cotizacionOriginal($m);
        $this->assertSame('reservada', $original->estadoResumen());
        $reservadasAntes = $this->reservadasDelInicio();

        $this->cambiarPrecio($this->nuevo($m, 'habitacion'), 230);
        $this->aplicarOk($reserva);

        $this->assertSame(Alternativa::ESTADO_REEMPLAZADA, Alternativa::find($m->alternativa_vendida_id)->estado);
        $this->assertSame('reservada', $this->cotizacionOriginal($m)->estadoResumen());
        $this->assertSame($reservadasAntes, $this->reservadasDelInicio(), 'la original sigue contando en "reservadas" del Inicio');

        $fila = collect(app(CotizacionController::class)->index(Request::create('/', 'GET', ['per_page' => 100]))->getData(true)['cotizaciones'])
            ->firstWhere('id', $original->id);
        $this->assertSame('reservada', $fila['estado_resumen']);

        $filtradas = app(CotizacionController::class)->index(Request::create('/', 'GET', ['estado_resumen' => 'reservada', 'per_page' => 100]))->getData(true)['cotizaciones'];
        $this->assertContains($original->id, collect($filtradas)->pluck('id')->all());
    }

    public function test_cotizacion_original_tras_aplicar_ca2_con_la_reserva_cancelada_sale_anulada(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $this->cambiarPrecio($this->nuevo($m, 'habitacion'), 230);
        $this->aplicarOk($reserva);

        $reserva->refresh()->update(['estado' => 'cancelada']);

        $this->assertSame('anulada', $this->cotizacionOriginal($m)->estadoResumen());
    }

    public function test_cotizacion_original_tras_aplicar_ca3_con_dos_modificaciones_sigue_reservada(): void
    {
        [$reserva, $m1] = $this->reservaConModificacion();
        $this->cambiarPrecio($this->nuevo($m1, 'habitacion'), 230);
        $this->aplicarOk($reserva);

        $m2 = $this->abrir($reserva);
        $habitacionM2 = AlternativaItem::where('alternativa_id', $m2->alternativa_nueva_id)->where('descripcion_manual', 'Habitación doble')->firstOrFail();
        $this->cambiarPrecio($habitacionM2, 260);
        $this->aplicarOk($reserva);

        $this->assertSame('reservada', $this->cotizacionOriginal($m1)->estadoResumen());
        $reserva->refresh()->update(['estado' => 'cancelada']);
        $this->assertSame('anulada', $this->cotizacionOriginal($m1)->estadoResumen());
    }

    public function test_cotizacion_original_tras_aplicar_ca5_el_listado_no_agrega_consultas_por_fila(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $this->cambiarPrecio($this->nuevo($m, 'habitacion'), 230);
        $this->aplicarOk($reserva);
        // Más cotizaciones reemplazadas y reservadas en la misma página.
        for ($i = 0; $i < 2; $i++) {
            [$otra, $otraM] = $this->reservaConModificacion();
            $this->cambiarPrecio($this->nuevo($otraM, 'habitacion'), 230);
            $this->aplicarOk($otra);
        }

        $consultas = function (int $porPagina): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $filas = app(CotizacionController::class)->index(Request::create('/', 'GET', ['per_page' => $porPagina]))->getData(true)['cotizaciones'];
            // Se cuentan todas, también las de alternativa_items: el listado ya no serializa
            // tiene_grupos_sin_resolver (que cargaba los ítems de cada alternativa, T-053).
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertCount($porPagina, $filas);

            return $total;
        };

        $this->assertGreaterThanOrEqual(3, Cotizacion::delCotizador()->count());
        $consultas(1); // calienta permisos y cachés: lo que se compara es lo que escala con las filas
        $this->assertSame($consultas(1), $consultas(3), 'las mismas consultas con 1 fila que con 3');
    }

    public function test_cotizacion_original_tras_aplicar_ca4_el_tablero_de_salidas_muestra_el_codigo_de_la_reserva(): void
    {
        [$reserva, $m] = $this->reservaConModificacion(['salida' => true]);
        $this->cambiarPrecio($this->nuevo($m, 'excursion'), 150);
        $this->aplicarOk($reserva);
        $reserva->refresh();
        $salidaId = ReservaItem::where('reserva_id', $reserva->id)->whereNotNull('salida_operativa_id')->value('salida_operativa_id');
        $this->assertNotNull($salidaId);
        $this->assertNotNull($reserva->codigo);
        $this->assertStringEndsWith('-M1', $reserva->alternativa->cotizacion->codigo);

        $salida = app(SalidaOperativaController::class)->show((string) $salidaId)->getData(true);
        $this->assertSame($reserva->codigo, $salida['reservas'][0]['codigo']);
        $this->assertSame($reserva->codigo, $salida['pasajeros'][0]['reserva_codigo']);

        // La orden de servicio (vehículo): mismo criterio.
        $vehiculo = SalidaVehiculo::create(['salida_operativa_id' => $salidaId, 'proveedor_id' => Proveedor::first()->id, 'descripcion' => 'Van', 'capacidad' => 10]);
        $vehiculo->reservas()->attach($reserva->id);
        $ordenes = app(SalidaOperacionService::class)->ordenes(SalidaOperativa::findOrFail($salidaId));
        $this->assertSame($reserva->codigo, $ordenes[0]['vehiculos'][0]['reservas'][0]['codigo']);

        // Sin código propio (reserva anterior al módulo de códigos): el de la cotización.
        $reserva->update(['codigo' => null]);
        $salida = app(SalidaOperativaController::class)->show((string) $salidaId)->getData(true);
        $this->assertSame($reserva->fresh()->alternativa->cotizacion->codigo, $salida['reservas'][0]['codigo']);
    }

    // Servicio facturado (hotel) que sube de 300 a 350: la versión vigente tiene el hotel a 350 y el
    // "Ajuste" de 50, que ya está dentro de esos 350.
    private function aplicarConAjusteDelHotel(): array
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $this->facturar($reserva, ['hotel']);
        $this->cambiarPrecio($this->nuevo($m, 'hotel'), 350);
        $this->aplicarOk($reserva);

        return [$reserva, $m, Alternativa::findOrFail($m->alternativa_nueva_id)];
    }

    public function test_cotizacion_original_tras_aplicar_ca6_el_ajuste_no_se_suma_al_total_de_la_version_vigente(): void
    {
        [, , $vigente] = $this->aplicarConAjusteDelHotel();

        $items = $vigente->items()->get();
        $this->assertSame(1, $items->whereNotNull('ajuste_de_item_id')->count());
        // 250 + 200 + 80 + 350 del hotel; el ajuste de 50 no suma (con él serían 930).
        $this->assertSame(88000, (int) round(AlternativaItem::calcularTotalEfectivo($items)['total'] * 100));

        // Y lo que se guarda en alternativas.total por el camino real de recalcular.
        app(AlternativaController::class)->aplicarDescuentoGlobal($vigente, 0);
        $this->assertSame(88000, (int) round((float) $vigente->fresh()->total * 100));
    }

    public function test_cotizacion_original_tras_aplicar_ca6_el_pdf_lista_el_servicio_una_vez_y_suma_el_total_sin_el_ajuste(): void
    {
        [, , $vigente] = $this->aplicarConAjusteDelHotel();
        $vigente->update(['total' => 880]);

        $datos = null;
        View::composer('pdf.agencia-viajes.alternativa', function ($vista) use (&$datos) {
            $datos = $vista->getData();
        });
        $pdf = app(AlternativaPdfService::class)->contenido((string) $vigente->id);

        $this->assertNotEmpty($pdf['bytes']);
        $this->assertNotNull($datos);
        $this->assertSame(0, $datos['alternativa']->items->whereNotNull('ajuste_de_item_id')->count(), 'el ajuste no sale como servicio');
        $this->assertSame(1, $datos['alternativa']->items->where('descripcion_manual', 'Hotel')->count());
        $this->assertEquals(880.0, $datos['totalOriginal']);
        $this->assertEquals(880.0, $datos['total']);
        $this->assertFalse($datos['hayDescuento'], 'no inventa un descuento de 50');
    }

    public function test_cotizacion_original_tras_aplicar_ca7_el_total_de_la_reserva_no_cambia_y_el_ajuste_sigue_facturable(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $this->facturar($reserva, ['hotel']);
        $this->cambiarPrecio($this->nuevo($m, 'hotel'), 350);
        $this->aplicarOk($reserva);

        // Servicio facturado a 300 + ajuste de 50 + el resto: lo mismo que antes del cambio.
        $this->assertSame(83000 + 5000, $this->totalDeLaReserva($reserva));
        $ajuste = ReservaItem::where('reserva_id', $reserva->id)
            ->whereHas('alternativaItem', fn ($q) => $q->whereNotNull('ajuste_de_item_id'))->firstOrFail();
        $this->assertFalse(ReservaVenta::itemIdsFacturados($reserva->id)->contains($ajuste->id));
        $this->assertSame(5000, (int) round((float) $ajuste->alternativaItem->total_convertido * 100));
    }

    // ── CA11 ───────────────────────────────────────────────────────────────────

    public function test_ca11_la_modificacion_queda_aplicada_con_usuario_instante_utc_y_la_diferencia(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $this->cambiarPasajeros($m, [], [30]);
        $this->revisarTodo($reserva);
        $dif = $this->diferencia($reserva);

        $this->aplicarOk($reserva);

        $m->refresh();
        $this->assertSame(ReservaModificacion::ESTADO_APLICADA, $m->estado);
        $this->assertSame($this->usuario->id, $m->aplicado_por);
        $crudo = DB::table('reserva_modificaciones')->where('id', $m->id)->value('aplicado_en');
        $this->assertLessThan(60, abs(Carbon::now('UTC')->diffInSeconds(Carbon::parse($crudo, 'UTC'), true)), 'guardado en UTC');
        $this->assertSame($dif['huella'], $m->diferencia_aplicada['huella']);
        $this->assertSame(10000, $m->diferencia_aplicada['totales']['diferencia']);
        $this->assertSame(Alternativa::ESTADO_REEMPLAZADA, Alternativa::find($m->alternativa_vendida_id)->estado);
        $this->assertSame('aceptada', Alternativa::find($m->alternativa_nueva_id)->estado);
        $this->assertSame($m->alternativa_nueva_id, $reserva->fresh()->alternativa_id);
    }

    // ── La reserva sigue siendo de su vendedor ─────────────────────────────────

    public function test_la_cotizacion_de_la_modificacion_vuelve_al_vendedor_original_y_la_reserva_no_cambia_de_dueno(): void
    {
        $reserva = $this->reservaConTresPasajeros();
        $otro = User::factory()->create(['role_id' => Role::create(['name' => 'otro-'.uniqid(), 'guard_name' => 'api'])->id]);
        $otro->givePermissionTo(Permission::firstOrCreate(['name' => 'reservas.modificar', 'guard_name' => 'api']));
        $this->verTodasLasReservas($otro);
        // Quien edita la modificación es otro usuario (con acceso a todas las reservas).
        $m = $this->abrir($reserva);
        $this->assertSame($otro->id, Cotizacion::find($m->cotizacion_id)->vendedor_id);
        $this->cambiarPasajeros($m, [], [30]);

        $this->aplicarOk($reserva);

        $this->assertSame($this->usuario->id, Cotizacion::find($m->cotizacion_id)->vendedor_id, 'la reserva sigue siendo del vendedor original');
    }

    // ── Policy ─────────────────────────────────────────────────────────────────

    public function test_sin_modificacion_abierta_responde_404(): void
    {
        $reserva = $this->reservaConTresPasajeros();

        $this->assertSame(404, $this->aplicar($reserva, str_repeat('a', 64))->getStatusCode());
    }

    public function test_quien_no_puede_editar_la_reserva_no_la_aplica(): void
    {
        [$reserva, $m] = $this->reservaConModificacion();
        $this->cambiarPasajeros($m, [], [30]);
        $huella = $this->diferencia($reserva)['huella'];
        $ajeno = User::factory()->create(['role_id' => Role::create(['name' => 'ajeno-'.uniqid(), 'guard_name' => 'api'])->id]);
        Auth::guard('api')->setUser($ajeno->fresh());
        $antes = $this->foto($reserva, $m);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        try {
            $this->aplicar($reserva, $huella);
        } finally {
            $this->assertEquals($antes, $this->foto($reserva, $m));
        }
    }
}
