<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\SalidaOperativaController;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Models\AgenciaViajes\SalidaServicio;
use App\Models\AgenciaViajes\SalidaVehiculo;
use App\Models\User;
use App\Services\AgenciaViajes\SalidaEngancheService;
use App\Services\AgenciaViajes\SalidaOperacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\AgenciaViajes\Concerns\SalidasFixtures;
use Tests\TestCase;

// Fila 13, fase 2c — órdenes de servicio, avisos al cancelar y reprogramar
// la salida completa (plan-tours-venta-simple-costo-real.md §3.13, D14-D17).
// Fixtures en Concerns\SalidasFixtures.
class Fila13Fase2cOperacionTest extends TestCase
{
    use SalidasFixtures;

    // ═══════════════════════════════════════════════════════════════
    // Órdenes de servicio (D14)
    // ═══════════════════════════════════════════════════════════════

    public function test_una_orden_por_proveedor_con_datos_minimos_y_enlace_de_whatsapp(): void
    {
        [$salida, $rojas] = $this->salidaArmada();
        // Un pasajero de los Rojas es vegetariano y tiene nombre.
        ReservaPasajero::where('reserva_id', $rojas->id)->first()->update(['nombre' => 'Juan Secreto', 'alimentacion_especial' => 'vegetariano']);

        $respuesta = app(SalidaOperativaController::class)->ordenes((string) $salida->id, app(SalidaOperacionService::class))->getData(true);
        $ordenes = collect($respuesta['ordenes'])->keyBy('proveedor');

        $this->assertCount(2, $ordenes);
        $mirador = $ordenes['El Mirador'];
        $this->assertSame(4, $mirador['servicios'][0]['cantidad']); // 3 adultos + 1 niño
        $this->assertSame(['vegetariano' => 1], $mirador['alimentacion_especial']);
        $this->assertStringStartsWith('https://wa.me/51987654321?text=', $mirador['whatsapp_url']);
        $this->assertStringContainsString('1 vegetariano', $mirador['mensaje']);
        // Ley 29733: el restaurante no recibe nombres.
        $this->assertStringNotContainsString('Juan Secreto', $mirador['mensaje']);
        $this->assertStringContainsString('por confirmar', $mirador['mensaje']);

        $selva = $ordenes['Turismo Selva'];
        $this->assertSame([], $selva['servicios']);
        $this->assertSame([], $selva['alimentacion_especial']);
        $this->assertCount(2, $selva['vehiculos'][0]['reservas']);
        $this->assertSame('Cliente Test Salidas', $selva['vehiculos'][0]['reservas'][0]['titular']);
    }

    public function test_el_pdf_de_la_orden_se_genera(): void
    {
        [$salida] = $this->salidaArmada();
        $mirador = SalidaServicio::where('salida_operativa_id', $salida->id)->whereNotNull('proveedor_id')->value('proveedor_id');

        $respuesta = app(SalidaOperativaController::class)->ordenPdf((string) $salida->id, (string) $mirador, app(SalidaOperacionService::class));

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertStringStartsWith('%PDF', $respuesta->getContent());
    }

    // ═══════════════════════════════════════════════════════════════
    // Avisos al cancelar una reserva (D15)
    // ═══════════════════════════════════════════════════════════════

    public function test_cancelar_una_reserva_avisa_que_ajustar_y_la_saca_del_vehiculo(): void
    {
        [$salida, $rojas] = $this->salidaArmada();

        $respuesta = app(ReservaController::class)->cancelar(new Request(['motivo_cancelacion' => 'voluntaria']), (string) $rojas->id)->getData(true);

        $this->assertSame(200, $respuesta['code'], json_encode($respuesta));
        $avisos = $respuesta['avisos_salidas'][0]['avisos'];
        // Quedan los Pérez: 1 adulto + 1 niño.
        $this->assertContains('El Mirador (Almuerzo): ahora son 2, no 4.', $avisos);
        $this->assertContains('Turismo Selva (Van 14): ahora lleva 2 pasajero(s), no 4.', $avisos);
        $this->assertNotContains($rojas->id, SalidaVehiculo::where('salida_operativa_id', $salida->id)->first()->reservas->pluck('id'));
    }

    public function test_cancelar_la_ultima_reserva_avisa_que_la_salida_ya_no_va(): void
    {
        $this->usuarioConTodasLasReservas();
        $tour = $this->tourConAlmuerzo();
        $reserva = $this->reservaConTour($tour, [['adulto', 40]]);
        $salidaId = $this->salidaDe($reserva)->id;
        SalidaServicio::where('salida_operativa_id', $salidaId)->update(['nombre' => 'Almuerzo']);

        $respuesta = app(ReservaController::class)->cancelar(new Request(['motivo_cancelacion' => 'clima']), (string) $reserva->id)->getData(true);

        $this->assertNull(SalidaOperativa::find($salidaId));
        $this->assertSame(['El Mirador (Almuerzo): la salida del 15/11/2026 ya no va.'], $respuesta['avisos_salidas'][0]['avisos']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Reprogramar la salida completa (D16, D17)
    // ═══════════════════════════════════════════════════════════════

    public function test_reprogramar_la_salida_lleva_reservas_y_configuracion_a_la_nueva_fecha(): void
    {
        [$salida, $rojas, $perez] = $this->salidaArmada();
        $guia = $this->guiaTarifa()->guia;
        $salida->update(['guia_id' => $guia->id]);
        SalidaServicio::where('salida_operativa_id', $salida->id)->update(['estado' => 'reservado', 'cantidad_manual' => 5]);

        $respuesta = $this->reprogramarSalida($salida, ['fecha_nueva' => '2026-11-20', 'motivo' => 'Lluvia']);

        $this->assertSame(200, $respuesta['code'], json_encode($respuesta));
        $this->assertNull(SalidaOperativa::find($salida->id)); // la vieja quedó vacía
        $nueva = SalidaOperativa::find($respuesta['salida_nueva_id']);
        $this->assertSame('2026-11-20', $nueva->fecha->toDateString());
        $this->assertSame($guia->id, $nueva->guia_id);
        // Reservas de un solo día: se movió la reserva completa.
        $this->assertSame('2026-11-20', $rojas->fresh()->fecha_viaje_desde->toDateString());

        $almuerzo = SalidaServicio::where('salida_operativa_id', $nueva->id)->first();
        $this->assertNotNull($almuerzo->proveedor_id); // el proveedor se conserva
        $this->assertSame(5, $almuerzo->cantidad_manual);
        $this->assertSame(SalidaServicio::ESTADO_POR_RESERVAR, $almuerzo->estado); // hay que volver a reservar

        $van = SalidaVehiculo::where('salida_operativa_id', $nueva->id)->first();
        $this->assertSame('Van 14', $van->descripcion);
        $this->assertEqualsCanonicalizing([$rojas->id, $perez->id], $van->reservas->pluck('id')->all());
        $this->assertContains('El Mirador (Almuerzo): la salida del 15/11/2026 pasa al 20/11/2026. Hay que volver a reservar.', $respuesta['avisos']);
    }

    public function test_reprogramar_puede_cancelar_algunas_reservas(): void
    {
        [$salida, $rojas, $perez] = $this->salidaArmada();

        $respuesta = $this->reprogramarSalida($salida, [
            'fecha_nueva' => '2026-11-20', 'motivo' => 'Derrumbe',
            'cancelar_reserva_ids' => [$perez->id], 'motivo_cancelacion' => 'fuerza_mayor',
        ]);

        $this->assertSame(200, $respuesta['code'], json_encode($respuesta));
        $this->assertSame('cancelada', $perez->fresh()->estado);
        $this->assertSame([$rojas->id], $respuesta['reservas_reprogramadas']);
        $van = SalidaVehiculo::where('salida_operativa_id', $respuesta['salida_nueva_id'])->first();
        $this->assertSame([$rojas->id], $van->reservas->pluck('id')->all());
    }

    public function test_en_un_viaje_de_varios_dias_solo_se_mueve_el_tour(): void
    {
        // Viaje desde el 15-nov con el tour el día 2 (16-nov).
        $reserva = $this->reservaConTour($this->tourConAlmuerzo(), [['adulto', 40]], dia: 2);
        $salida = $this->salidaDe($reserva);
        $this->assertSame('2026-11-16', $salida->fecha->toDateString());

        $respuesta = $this->reprogramarSalida($salida, ['fecha_nueva' => '2026-11-18', 'motivo' => 'Lluvia']);

        $this->assertSame(200, $respuesta['code'], json_encode($respuesta));
        // El viaje sigue empezando el 15; solo el tour pasó al 18, con fecha manual.
        $this->assertSame('2026-11-15', $reserva->fresh()->fecha_viaje_desde->toDateString());
        $item = ReservaItem::where('reserva_id', $reserva->id)->first();
        $this->assertSame('2026-11-18', $item->fecha->toDateString());
        $this->assertSame(ReservaItem::FECHA_ORIGEN_MANUAL, $item->fecha_origen);
        $this->assertSame($respuesta['salida_nueva_id'], $item->salida_operativa_id);
    }

    public function test_no_se_reprograma_a_una_fecha_pasada(): void
    {
        [$salida] = $this->salidaArmada();

        $respuesta = $this->reprogramarSalida($salida, ['fecha_nueva' => '2020-01-01', 'motivo' => 'Prueba']);

        $this->assertSame(422, $respuesta['code']);
        $this->assertNotNull(SalidaOperativa::find($salida->id));
    }

    // ═══════════════════════════════════════════════════════════════
    // Exonerado = Amazonía en Agencia (22-oct-2026)
    // ═══════════════════════════════════════════════════════════════

    public function test_en_agencia_exonerado_siempre_queda_como_amazonia(): void
    {
        $tour = $this->crearTour();
        // Aunque llegue "20 + nacional" (lo que dejaba cargar el formulario de la tarifa)...
        $item = $this->incluye($tour, 10, igv: '20', destino: 'nacional');
        $this->assertSame('amazonia', $item->fresh()->destino_tributario);

        // ...y lo mismo con el default de la agencia (columnas con otro nombre).
        $config = ConfiguracionAgencia::first();
        $config->update(['tip_afe_igv_default' => '20', 'destino_tributario_default' => 'nacional']);
        $this->assertSame('amazonia', $config->fresh()->destino_tributario_default);

        // Gravado no se toca.
        $gravado = $this->incluye($tour, 10, igv: '10', destino: 'nacional');
        $this->assertSame('nacional', $gravado->fresh()->destino_tributario);
    }

    public function test_un_tour_con_dos_servicios_exonerados_es_una_sola_linea(): void
    {
        $tour = $this->crearTour(['costo_estimado_persona' => null]);
        $this->incluye($tour, 60, igv: '20', destino: 'amazonia');
        $this->incluye($tour, 20, igv: '20', destino: 'amazonia');
        $reserva = $this->reservaConTour($tour, [['adulto', 40]]);

        $lineas = ReservaItem::where('reserva_id', $reserva->id)->get();
        $this->assertCount(1, $lineas); // para SUNAT, un solo exonerado
        $this->assertSame('amazonia', $lineas->first()->destino_tributario);
    }

    // ═══════════════════════════════════════════════════════════════
    // Hora y lugar de recojo (22-oct-2026)
    // ═══════════════════════════════════════════════════════════════

    public function test_la_salida_nace_con_la_hora_y_el_lugar_de_recojo_del_tour(): void
    {
        $tour = $this->crearTour(['hora_salida' => '07:30', 'lugar_recojo' => 'Plaza de Armas de Tarapoto']);
        $reserva = $this->reservaConTour($tour, [['adulto', 40]]);
        $salida = $this->salidaDe($reserva);

        $this->assertSame('07:30', substr((string) $salida->hora, 0, 5));
        $this->assertSame('Plaza de Armas de Tarapoto', $salida->lugar_recojo);

        // Reporte Operativo: sin hora de recojo propia, la de la salida.
        $filas = collect(app(\App\Http\Controllers\AgenciaViajes\ReporteOperativoController::class)
            ->index(new Request(['fecha_desde' => '2026-11-15', 'fecha_hasta' => '2026-11-15']))->getData(true)['filas'])
            ->where('reserva_id', $reserva->id);
        $this->assertSame('07:30', $filas->first()['hora']);

        // Con hora de recojo, esa.
        ReservaItem::where('reserva_id', $reserva->id)->update(['hora' => '07:10']);
        $filas = collect(app(\App\Http\Controllers\AgenciaViajes\ReporteOperativoController::class)
            ->index(new Request(['fecha_desde' => '2026-11-15', 'fecha_hasta' => '2026-11-15']))->getData(true)['filas'])
            ->where('reserva_id', $reserva->id);
        $this->assertSame('07:10', substr((string) $filas->first()['hora'], 0, 5));

        // La orden de servicio dice dónde es el recojo.
        $this->incluye($tour, 25, nombre: 'Almuerzo', proveedor: $this->proveedor('El Mirador'));
        app(\App\Services\AgenciaViajes\SalidaServiciosService::class)->sincronizarDesdeTour($salida);
        $orden = app(SalidaOperacionService::class)->ordenes($salida->fresh())[0];
        $this->assertStringContainsString('Recojo: Plaza de Armas de Tarapoto.', $orden['mensaje']);
        $this->assertStringContainsString('a las 07:30', $orden['mensaje']);
    }

    public function test_reprogramar_conserva_la_hora_y_el_lugar_que_puso_el_operador(): void
    {
        [$salida] = $this->salidaArmada();
        $salida->update(['hora' => '05:45', 'lugar_recojo' => 'Grifo de la entrada']);

        $respuesta = $this->reprogramarSalida($salida, ['fecha_nueva' => '2026-11-20', 'motivo' => 'Lluvia']);

        $nueva = SalidaOperativa::find($respuesta['salida_nueva_id']);
        $this->assertSame('05:45', substr((string) $nueva->hora, 0, 5));
        $this->assertSame('Grifo de la entrada', $nueva->lugar_recojo);
    }

    // ═══════════════════════════════════════════════════════════════
    // Apoyo
    // ═══════════════════════════════════════════════════════════════

    private function tourConAlmuerzo()
    {
        $tour = $this->crearTour();
        $mirador = $this->proveedor('El Mirador');
        $mirador->update(['whatsapp' => '987 654 321']);
        // El fixture le pone un sufijo único al nombre del servicio; los tests
        // lo fijan en "Almuerzo" en la salida, una vez creada.
        $this->incluye($tour, 25, nombre: 'Almuerzo', proveedor: $mirador);

        return $tour;
    }

    /**
     * Salida del 15-nov con dos reservas (Rojas: 2 adultos; Pérez: adulto,
     * niño e infante), el almuerzo de El Mirador y una van de Turismo Selva.
     *
     * @return array{0: SalidaOperativa, 1: Reserva, 2: Reserva}
     */
    private function salidaArmada(): array
    {
        $this->usuarioConTodasLasReservas();
        $tour = $this->tourConAlmuerzo();
        $rojas = $this->reservaConTour($tour, [['adulto', 40], ['adulto', 38]]);
        $perez = $this->reservaConTour($tour, [['adulto', 35], ['nino', 8], ['infante', 1]]);
        $salida = $this->salidaDe($rojas);
        SalidaServicio::where('salida_operativa_id', $salida->id)->update(['nombre' => 'Almuerzo']);

        $van = SalidaVehiculo::create([
            'salida_operativa_id' => $salida->id, 'descripcion' => 'Van 14', 'capacidad' => 14,
            'proveedor_id' => $this->proveedor('Turismo Selva')->id,
        ]);
        $van->reservas()->sync([$rojas->id, $perez->id]);

        return [$salida->fresh(), $rojas, $perez];
    }

    private function reprogramarSalida(SalidaOperativa $salida, array $datos): array
    {
        $this->usuarioConTodasLasReservas();

        return app(SalidaOperativaController::class)->reprogramar(
            new Request($datos), (string) $salida->id, app(SalidaOperacionService::class), app(SalidaEngancheService::class)
        )->getData(true);
    }

    // cancelar() revisa la política de la reserva: el operador ve todas.
    private function usuarioConTodasLasReservas(): void
    {
        if (Auth::guard('api')->user()) {
            return;
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $role = Role::create(['name' => 'rol-2c-'.uniqid(), 'guard_name' => 'api']);
        $role->givePermissionTo(Permission::firstOrCreate(['name' => 'reservas.ver_todas', 'guard_name' => 'api']));
        $user = User::factory()->create(['role_id' => $role->id]);
        $user->assignRole($role);
        Auth::guard('api')->setUser($user->fresh());
        Auth::shouldUse('api');
    }
}
