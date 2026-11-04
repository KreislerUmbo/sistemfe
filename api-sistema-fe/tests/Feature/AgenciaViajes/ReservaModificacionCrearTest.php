<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\CotizacionController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaModificacionController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\PasajeroCatalogo;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaModificacion;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\AgenciaViajes\Servicio;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// T-002 — Modificar reserva: crear, consultar y descartar la modificación (R-AGE-01, R-AGE-02,
// R-AGE-10, D-AGE-030). Cada test parte de una reserva creada por el camino real
// (ReservaController::aceptar()), para que pasajeros, vínculos e ítems sean los de producción.
// Postgres real (sistemafe_test_migrations), una transacción por test que se revierte.
class ReservaModificacionCrearTest extends TestCase
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

    // Reserva activa de 3 pasajeros (Ana y Beto adultos, Carla niña de 8) y 4 servicios:
    // un traslado por persona (100 adulto / 50 niño), un servicio solo para Carla, un hotel
    // exonerado (Amazonía) y un servicio suelto.
    private function reservaConTresPasajeros(): Reserva
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10000000, 99999999),
            'full_name' => 'Cliente Modificación', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacion = Cotizacion::create([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-MOD-'.uniqid(), 'cliente_id' => $clienteId,
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
        $this->items['solo_carla'] = $this->item($alternativa, [
            'descripcion_manual' => 'Taller para niños', 'pax_incluidos' => [$this->pax['carla']->id],
            'precio_venta_snapshot' => 80, 'precio_convertido' => 80,
        ]);
        $this->items['hotel'] = $this->item($alternativa, [
            'descripcion_manual' => 'Hotel', 'precio_venta_snapshot' => 300, 'precio_convertido' => 300,
            'tip_afe_igv' => '20', 'destino_tributario' => 'amazonia',
        ]);
        $this->items['suelto'] = $this->item($alternativa, [
            'descripcion_manual' => 'Entrada', 'precio_venta_snapshot' => 120, 'precio_convertido' => 120,
        ]);

        $respuesta = app(ReservaController::class)->aceptar(new Request(), (string) $alternativa->id);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());

        return Reserva::where('alternativa_id', $alternativa->id)->firstOrFail();
    }

    private function crear(Reserva $reserva): \Illuminate\Http\JsonResponse
    {
        return app(ReservaModificacionController::class)->store((string) $reserva->id);
    }

    // ── CA1 ────────────────────────────────────────────────────────────────────

    public function test_ca1_crea_la_cotizacion_de_modificacion_con_pasajeros_y_servicios_copiados(): void
    {
        $reserva = $this->reservaConTresPasajeros();
        $this->assertCount(3, $reserva->pasajeros);

        $respuesta = $this->crear($reserva);
        $datos = $respuesta->getData(true);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());

        $cotizacion = Cotizacion::findOrFail($datos['cotizacion_id']);
        $this->assertSame(Cotizacion::ORIGEN_MODIFICACION, $cotizacion->origen);
        $this->assertSame($reserva->codigo.'-M1', $cotizacion->codigo);
        $this->assertSame('2026-11-10', $cotizacion->fecha_viaje_desde->toDateString());

        $copias = CotizacionPasajero::where('cotizacion_id', $cotizacion->id)->orderBy('id')->get();
        $this->assertCount(3, $copias);
        $this->assertEqualsCanonicalizing(
            $reserva->pasajeros->pluck('id')->all(),
            $copias->pluck('reserva_pasajero_id')->all(),
        );
        $this->assertSame(['adulto', 'adulto', 'nino'], $copias->pluck('tipo_pax')->all());
        $this->assertSame([30, 35, 8], $copias->pluck('edad')->all());

        $modificacion = ReservaModificacion::findOrFail($datos['modificacion']['id']);
        $this->assertSame('abierta', $modificacion->estado);
        $this->assertSame($reserva->alternativa_id, $modificacion->alternativa_vendida_id);
        $this->assertSame($this->usuario->id, $modificacion->creado_por);

        $nueva = Alternativa::findOrFail($modificacion->alternativa_nueva_id);
        $this->assertSame('borrador', $nueva->estado);
        $this->assertSame($cotizacion->id, $nueva->cotizacion_id);

        $itemsNuevos = $nueva->items()->get();
        $this->assertCount(4, $itemsNuevos);
        foreach ($this->items as $clave => $original) {
            $copia = $itemsNuevos->firstWhere('item_origen_id', $original->id);
            $this->assertNotNull($copia, "el servicio {$clave} se copió con su item_origen_id");
            $this->assertEqualsWithDelta((float) $original->precio_convertido, (float) $copia->precio_convertido, 0.001);
            $this->assertSame($original->tip_afe_igv, $copia->tip_afe_igv);
            $this->assertSame($original->destino_tributario, $copia->destino_tributario);
        }

        // La versión vendida no se tocó.
        $this->assertSame(4, Alternativa::findOrFail($reserva->alternativa_id)->items()->count());
        $this->assertSame('aceptada', Alternativa::findOrFail($reserva->alternativa_id)->estado);
        $this->assertSame([], $datos['pasajeros_edad_estimada']);
        $this->assertSame(0, $datos['items_recalculados']);
    }

    // ── CA2 ────────────────────────────────────────────────────────────────────

    public function test_ca2_el_servicio_solo_para_carla_apunta_a_la_copia_de_carla(): void
    {
        $reserva = $this->reservaConTresPasajeros();
        $carlaReserva = $reserva->pasajeros->firstWhere('cotizacion_pasajero_id', $this->pax['carla']->id);
        $this->assertNotNull($carlaReserva);

        $datos = $this->crear($reserva)->getData(true);

        $copiaCarla = CotizacionPasajero::where('cotizacion_id', $datos['cotizacion_id'])
            ->where('reserva_pasajero_id', $carlaReserva->id)->firstOrFail();
        $copiaItem = AlternativaItem::where('alternativa_id', $datos['modificacion']['alternativa_nueva_id'])
            ->where('item_origen_id', $this->items['solo_carla']->id)->firstOrFail();

        $this->assertSame([$copiaCarla->id], $copiaItem->pax_incluidos);
        $this->assertNotSame([$this->pax['carla']->id], $copiaItem->pax_incluidos);
    }

    // ── CA3 ────────────────────────────────────────────────────────────────────

    public function test_ca3_pasajero_agregado_sin_vinculo_se_copia_recalcula_y_avisa_la_edad_estimada(): void
    {
        $reserva = $this->reservaConTresPasajeros();
        $nuevo = ReservaPasajero::create(['reserva_id' => $reserva->id, 'tipo_pax' => 'adulto', 'nombre' => 'Dani']);
        $this->assertNull($nuevo->cotizacion_pasajero_id);

        $datos = $this->crear($reserva)->getData(true);

        $copias = CotizacionPasajero::where('cotizacion_id', $datos['cotizacion_id'])->get();
        $this->assertCount(4, $copias);
        $copiaNuevo = $copias->firstWhere('reserva_pasajero_id', $nuevo->id);
        $this->assertSame('adulto', $copiaNuevo->tipo_pax);

        $this->assertCount(1, $datos['pasajeros_edad_estimada']);
        $this->assertSame($nuevo->id, $datos['pasajeros_edad_estimada'][0]['reserva_pasajero_id']);
        $this->assertSame($copiaNuevo->edad, $datos['pasajeros_edad_estimada'][0]['edad_estimada']);

        // El traslado por persona se recalculó: 3 adultos × 100 + 1 niño × 50.
        $traslado = AlternativaItem::where('alternativa_id', $datos['modificacion']['alternativa_nueva_id'])
            ->where('item_origen_id', $this->items['traslado']->id)->firstOrFail();
        $this->assertEqualsWithDelta(350.0, (float) $traslado->precio_venta_snapshot, 0.001);
        $this->assertGreaterThanOrEqual(1, $datos['items_recalculados']);
        // ... y el de la versión vendida quedó igual.
        $this->assertEqualsWithDelta(250.0, (float) $this->items['traslado']->fresh()->precio_venta_snapshot, 0.001);
    }

    public function test_ca3_si_el_catalogo_trae_la_fecha_de_nacimiento_no_se_estima_la_edad(): void
    {
        $reserva = $this->reservaConTresPasajeros();
        // Viaja el 10-nov-2026: tiene 9 años cumplidos.
        $catalogo = PasajeroCatalogo::create(['nombre' => 'Eli', 'fecha_nacimiento' => '2017-03-01']);
        $nuevo = ReservaPasajero::create([
            'reserva_id' => $reserva->id, 'tipo_pax' => 'adulto', 'nombre' => 'Eli', 'pasajero_catalogo_id' => $catalogo->id,
        ]);

        $datos = $this->crear($reserva)->getData(true);

        $this->assertSame([], $datos['pasajeros_edad_estimada']);
        $copia = CotizacionPasajero::where('cotizacion_id', $datos['cotizacion_id'])
            ->where('reserva_pasajero_id', $nuevo->id)->firstOrFail();
        $this->assertSame(9, $copia->edad);
        $this->assertSame('nino', $copia->tipo_pax);
    }

    // ── CA4 ────────────────────────────────────────────────────────────────────

    public function test_ca4_con_una_abierta_crear_otra_responde_422(): void
    {
        $reserva = $this->reservaConTresPasajeros();

        $this->assertSame(200, $this->crear($reserva)->getStatusCode());
        $segunda = $this->crear($reserva);

        $this->assertSame(422, $segunda->getStatusCode());
        $this->assertSame(1, ReservaModificacion::where('reserva_id', $reserva->id)->count());
    }

    public function test_ca4_el_indice_unico_parcial_impide_dos_abiertas_aunque_se_salte_el_servicio(): void
    {
        $reserva = $this->reservaConTresPasajeros();
        $datos = $this->crear($reserva)->getData(true)['modificacion'];

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::transaction(fn () => ReservaModificacion::create([
            'reserva_id' => $reserva->id,
            'cotizacion_id' => $datos['cotizacion_id'],
            'alternativa_vendida_id' => $datos['alternativa_vendida_id'],
            'alternativa_nueva_id' => $datos['alternativa_nueva_id'],
            'estado' => 'abierta',
            'creado_por' => $this->usuario->id,
        ]));
    }

    // Con dos conexiones: la "otra pestaña" tiene tomada la fila de la reserva; crear espera su
    // lock (mismo patrón que ReservaAceptarConcurrencyTest).
    public function test_ca4_crear_espera_el_lock_de_la_reserva(): void
    {
        $reserva = $this->reservaConTresPasajeros();

        config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
        DB::purge('pgsql_b');
        $permisosQueYaEstaban = DB::connection('pgsql_b')->table('permissions')->pluck('id')->all();

        // Un lock entre dos conexiones solo se ve con los datos guardados de verdad: se hace
        // commit y el finally borra a mano todo lo creado.
        DB::commit();

        $bloqueada = false;
        $codigoError = null;
        $cotizacionId = $reserva->alternativa->cotizacion_id;

        try {
            DB::connection('pgsql_b')->beginTransaction();
            DB::connection('pgsql_b')->select('select * from reserva where id = ? for update', [$reserva->id]);

            DB::beginTransaction();
            DB::statement("SET LOCAL lock_timeout = '300ms'");
            try {
                $this->crear($reserva);
            } catch (\Throwable $error) {
                $bloqueada = true;
                $codigoError = $error->getCode();
            }
            DB::rollBack();
            DB::connection('pgsql_b')->rollBack();

            $this->assertTrue($bloqueada, 'crear() debe esperar el lock de la reserva.');
            $this->assertSame('55P03', (string) $codigoError, 'Postgres corta la espera por lock_timeout.');
            $this->assertSame(0, ReservaModificacion::where('reserva_id', $reserva->id)->count());

            // Liberado el lock, se crea una sola vez.
            $primera = $this->crear($reserva);
            $segunda = $this->crear($reserva);
            $this->assertSame(200, $primera->getStatusCode(), $primera->getContent());
            $this->assertSame(422, $segunda->getStatusCode());
            $this->assertSame(1, ReservaModificacion::where('reserva_id', $reserva->id)->count());
        } finally {
            if (DB::connection('pgsql_b')->transactionLevel() > 0) {
                DB::connection('pgsql_b')->rollBack();
            }
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->limpiarLoCommiteado($reserva->id, $cotizacionId, $permisosQueYaEstaban);
            DB::beginTransaction();
        }
    }

    // Borra todo lo que reservaConTresPasajeros() y crear() dejaron guardado por el commit del
    // test del lock, hijos primero.
    private function limpiarLoCommiteado(int $reservaId, int $cotizacionOriginalId, array $permisosQueYaEstaban): void
    {
        $modificaciones = ReservaModificacion::where('reserva_id', $reservaId)->get();
        $cotizaciones = array_merge([$cotizacionOriginalId], $modificaciones->pluck('cotizacion_id')->all());
        $alternativas = DB::table('alternativas')->whereIn('cotizacion_id', $cotizaciones)->pluck('id');
        $clienteId = DB::table('cotizaciones')->where('id', $cotizacionOriginalId)->value('cliente_id');

        ReservaModificacion::where('reserva_id', $reservaId)->delete();
        $itemIds = DB::table('alternativa_items')->whereIn('alternativa_id', $alternativas)->pluck('id');
        $reservaItemIds = DB::table('reserva_items')->where('reserva_id', $reservaId)->pluck('id');
        DB::table('reserva_item_pasajero')->whereIn('reserva_item_id', $reservaItemIds)->delete();
        DB::table('reserva_items')->where('reserva_id', $reservaId)->delete();
        DB::table('reserva_pasajeros')->where('reserva_id', $reservaId)->delete();
        DB::table('reserva')->where('id', $reservaId)->delete();
        DB::table('alternativa_items')->whereIn('id', $itemIds)->delete();
        DB::table('alternativas')->whereIn('id', $alternativas)->delete();
        DB::table('cotizacion_pasajeros')->whereIn('cotizacion_id', $cotizaciones)->delete();
        DB::table('cotizaciones')->whereIn('id', $cotizaciones)->delete();
        // T-017: aceptar() crea la ficha de pasajero del cliente; la FK impide borrar el cliente sin ella.
        DB::table('pasajero_documentos')->whereIn('pasajero_catalogo_id', DB::table('pasajeros_catalogo')->where('cliente_id', $clienteId)->pluck('id'))->delete();
        DB::table('pasajeros_catalogo')->where('cliente_id', $clienteId)->delete();
        DB::table('clients')->where('id', $clienteId)->delete();

        // Catálogo que creó crearTarifa(): también quedó guardado por el commit.
        $proveedores = DB::table('proveedores')->where('razon_social', 'Transportes Test SAC')->pluck('id');
        $proveedorServicios = DB::table('proveedor_servicios')->whereIn('proveedor_id', $proveedores)->pluck('id');
        $servicios = DB::table('servicios')->where('nombre', 'like', 'Traslado %')->pluck('id');
        DB::table('proveedor_tarifas')->whereIn('proveedor_servicio_id', $proveedorServicios)->delete();
        DB::table('proveedor_servicios')->whereIn('id', $proveedorServicios)->delete();
        DB::table('destino_servicio')->whereIn('servicio_id', $servicios)->delete();
        DB::table('servicios')->whereIn('id', $servicios)->delete();
        DB::table('proveedores')->whereIn('id', $proveedores)->delete();
        DB::table('destinos_atractivos')->where('nombre', 'Alto Mayo')->delete();

        $roleId = $this->usuario->role_id;
        DB::table('role_audit_logs')->where('target_type', 'user')->where('target_id', $this->usuario->id)->delete();
        DB::table('model_has_permissions')->where('model_id', $this->usuario->id)->where('model_type', User::class)->delete();
        DB::table('model_has_roles')->where('model_id', $this->usuario->id)->where('model_type', User::class)->delete();
        DB::table('users')->where('id', $this->usuario->id)->delete();
        DB::table('roles')->where('id', $roleId)->delete();
        DB::table('permissions')->whereNotIn('id', $permisosQueYaEstaban)->delete();
    }

    // ── CA5 ────────────────────────────────────────────────────────────────────

    public function test_ca5_una_reserva_cancelada_no_se_puede_modificar(): void
    {
        $reserva = $this->reservaConTresPasajeros();
        $reserva->update(['estado' => 'cancelada']);

        $respuesta = $this->crear($reserva);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertSame(0, ReservaModificacion::where('reserva_id', $reserva->id)->count());
        $this->assertSame(0, Cotizacion::where('codigo', 'like', $reserva->codigo.'-M%')->count());
    }

    // ── CA6 ────────────────────────────────────────────────────────────────────

    // Foto de lo que importa de la reserva: nada de esto puede cambiar al abrir ni descartar.
    private function fotoDeLaReserva(Reserva $reserva): array
    {
        $reserva = $reserva->fresh();

        return [
            'alternativa_id' => $reserva->alternativa_id,
            'estado' => $reserva->estado,
            'fechas' => [$reserva->fecha_viaje_desde?->toDateString(), $reserva->fecha_viaje_hasta?->toDateString()],
            'pasajeros' => $reserva->pasajeros()->orderBy('id')->get(['id', 'tipo_pax', 'cotizacion_pasajero_id', 'nombre'])->toArray(),
            'items' => $reserva->items()->orderBy('id')->get(['id', 'alternativa_item_id'])->toArray(),
            'items_vendidos' => AlternativaItem::where('alternativa_id', $reserva->alternativa_id)->orderBy('id')
                ->get(['id', 'precio_convertido', 'pax_incluidos'])->toArray(),
        ];
    }

    public function test_ca6_descartar_marca_descartada_no_cambia_la_reserva_y_permite_abrir_otra(): void
    {
        $reserva = $this->reservaConTresPasajeros();
        $antes = $this->fotoDeLaReserva($reserva);

        $abierta = $this->crear($reserva)->getData(true);
        $this->assertSame($antes, $this->fotoDeLaReserva($reserva), 'abrir no cambia la reserva');

        $respuesta = app(ReservaModificacionController::class)->destroy((string) $reserva->id);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());

        $modificacion = ReservaModificacion::findOrFail($abierta['modificacion']['id']);
        $this->assertSame('descartada', $modificacion->estado);
        $this->assertSame($this->usuario->id, $modificacion->descartado_por);
        $this->assertNotNull($modificacion->descartado_en);
        $this->assertSame($antes, $this->fotoDeLaReserva($reserva), 'descartar no cambia la reserva');

        // La cotización y la alternativa quedan como registro.
        $this->assertTrue(Cotizacion::whereKey($abierta['cotizacion_id'])->exists());
        $this->assertTrue(Alternativa::whereKey($modificacion->alternativa_nueva_id)->exists());

        // Ya no hay una abierta: la consulta da 404, descartar de nuevo da 404 y se puede abrir otra (M2).
        $this->assertSame(404, app(ReservaModificacionController::class)->show((string) $reserva->id)->getStatusCode());
        $this->assertSame(404, app(ReservaModificacionController::class)->destroy((string) $reserva->id)->getStatusCode());

        $otra = $this->crear($reserva);
        $this->assertSame(200, $otra->getStatusCode(), $otra->getContent());
        $this->assertSame($reserva->codigo.'-M2', Cotizacion::findOrFail($otra->getData(true)['cotizacion_id'])->codigo);
    }

    public function test_consultar_devuelve_la_abierta(): void
    {
        $reserva = $this->reservaConTresPasajeros();
        $abierta = $this->crear($reserva)->getData(true);

        $respuesta = app(ReservaModificacionController::class)->show((string) $reserva->id);

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame($abierta['modificacion']['id'], $respuesta->getData(true)['modificacion']['id']);
        $this->assertSame($abierta['cotizacion_id'], $respuesta->getData(true)['cotizacion_id']);
    }

    // ── T-007: forma de respuesta para las pantallas ───────────────────────────

    public function test_t007_el_detalle_de_la_reserva_trae_la_abierta_solo_con_reservas_modificar(): void
    {
        $reserva = $this->reservaConTresPasajeros();
        $abierta = $this->crear($reserva)->getData(true);

        $con = app(ReservaController::class)->show((string) $reserva->id)->getData(true);
        $this->assertSame(
            ['id' => $abierta['modificacion']['id'], 'cotizacion_id' => $abierta['cotizacion_id']],
            $con['modificacion_abierta']
        );
        $this->assertSame([], $con['modificaciones_aplicadas']);

        // Ve todas las reservas pero no tiene reservas.modificar: la reserva se ve, la modificación no.
        $this->verTodasLasReservas();
        $sin = app(ReservaController::class)->show((string) $reserva->id)->getData(true);
        $this->assertNull($sin['modificacion_abierta']);
        $this->assertSame([], $sin['modificaciones_aplicadas']);
    }

    public function test_t007_el_show_de_la_cotizacion_dice_a_que_reserva_pertenece_la_modificacion(): void
    {
        $reserva = $this->reservaConTresPasajeros();
        $abierta = $this->crear($reserva)->getData(true);

        $deModificacion = app(CotizacionController::class)->show((string) $abierta['cotizacion_id'])->getData(true);
        $this->assertSame([
            'id' => $abierta['modificacion']['id'],
            'estado' => 'abierta',
            'reserva_id' => $reserva->id,
            'reserva_codigo' => $reserva->codigo,
        ], $deModificacion['modificacion']);

        $original = app(CotizacionController::class)->show((string) $reserva->alternativa->cotizacion_id)->getData(true);
        $this->assertNull($original['modificacion']);
    }

    // ── CA7 ────────────────────────────────────────────────────────────────────

    public function test_ca7_aceptar_la_alternativa_de_una_modificacion_responde_422_y_no_crea_reserva(): void
    {
        $reserva = $this->reservaConTresPasajeros();
        $datos = $this->crear($reserva)->getData(true);
        $reservasAntes = Reserva::count();

        $respuesta = app(ReservaController::class)->aceptar(new Request(), (string) $datos['modificacion']['alternativa_nueva_id']);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertSame($reservasAntes, Reserva::count());
        $this->assertSame('borrador', Alternativa::findOrFail($datos['modificacion']['alternativa_nueva_id'])->estado);
    }

    // ── CA8 ────────────────────────────────────────────────────────────────────

    public function test_ca8_la_cotizacion_de_modificacion_no_aparece_en_el_listado_del_cotizador(): void
    {
        $reserva = $this->reservaConTresPasajeros();
        $datos = $this->crear($reserva)->getData(true);

        $codigos = collect(app(CotizacionController::class)->index(new Request(['per_page' => 100]))
            ->getData(true)['cotizaciones'])->pluck('codigo');

        $this->assertNotContains($reserva->codigo.'-M1', $codigos->all());
        $this->assertContains(Cotizacion::findOrFail($reserva->alternativa->cotizacion_id)->codigo, $codigos->all());
        $this->assertSame(0, Cotizacion::propias()->delCotizador()->whereKey($datos['cotizacion_id'])->count());
    }

    // ── CA9 ────────────────────────────────────────────────────────────────────

    public static function rutasProvider(): array
    {
        return [
            'POST' => ['POST'],
            'GET' => ['GET'],
            'DELETE' => ['DELETE'],
        ];
    }

    /** @dataProvider rutasProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('rutasProvider')]
    public function test_ca9_la_ruta_exige_reservas_modificar_y_el_middleware_bloquea_sin_el_permiso(string $metodo): void
    {
        $ruta = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/reservas/{id}/modificacion' && in_array($metodo, $r->methods(), true)
        );
        $this->assertNotNull($ruta, "No existe la ruta {$metodo} api/reservas/{id}/modificacion.");
        $this->assertContains('permission:reservas.modificar', $ruta->gatherMiddleware());

        $middleware = new PermissionMiddleware();
        $sin = User::factory()->create(['role_id' => Role::create(['name' => 'rol-sin-'.uniqid(), 'guard_name' => 'api'])->id]);
        Auth::guard('api')->setUser($sin->fresh());
        $this->expectException(UnauthorizedException::class);
        $middleware->handle(new Request(), fn () => 'ok', 'reservas.modificar');
    }

    public function test_ca9_sin_poder_ver_la_reserva_de_otro_vendedor_la_policy_bloquea_las_tres(): void
    {
        $reserva = $this->reservaConTresPasajeros();

        // Un vendedor con reservas.modificar pero sin reservas.ver_todas, y la reserva es de otro.
        $otro = User::factory()->create(['role_id' => Role::create(['name' => 'rol-otro-'.uniqid(), 'guard_name' => 'api'])->id]);
        foreach (['reservas.ver', 'reservas.editar', 'reservas.modificar'] as $permiso) {
            $otro->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'api']));
        }
        Auth::guard('api')->setUser($otro->fresh());

        $controller = app(ReservaModificacionController::class);
        foreach ([
            fn () => $controller->store((string) $reserva->id),
            fn () => $controller->show((string) $reserva->id),
            fn () => $controller->destroy((string) $reserva->id),
        ] as $accion) {
            try {
                $accion();
                $this->fail('La Policy debía bloquear la acción sobre una reserva ajena.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, ReservaModificacion::where('reserva_id', $reserva->id)->count());
    }
}
