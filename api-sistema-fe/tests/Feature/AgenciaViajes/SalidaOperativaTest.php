<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaItemController;
use App\Http\Controllers\AgenciaViajes\SalidaOperativaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Models\AgenciaViajes\Servicio;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// feature/salida-operativa — "salida" (departure) agrupa reserva_items de
// DISTINTAS reservas que comparten tour_origen_id + fecha, para asignar el
// guía una sola vez por salida en vez de una vez por reserva. Mismo patrón
// que PaqueteComboTest/ReservaFechaAutoCompletadaTest: Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
//
// Gap real encontrado leyendo el schema antes de escribir esto:
// guia_tarifas.modalidad es 'dia_local'/'grupo_multidia' (eje de duración
// de contrato), NO 'compartido'/'privado' como proveedor_tarifas.modalidad
// — no hay ninguna señal confiable de si un ítem de guía puntual es
// compartible entre reservas. Confirmado con el usuario: los ítems
// origen_tipo=guia NUNCA se auto-enganchan en esta fase (solo
// origen_tipo=proveedor con modalidad=compartido) — quedan disponibles
// para engancharse a mano desde el tablero (attachReservaItem()). Por eso
// el caso "dos reservas terminan en la misma salida" se prueba acá con
// ítems origen_tipo=proveedor, no con ítems de guía.
class SalidaOperativaTest extends TestCase
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
    // Fixtures — mismo patrón que PaqueteComboTest: arma la cadena real de
    // FK (destino → servicio → destino_servicio → proveedor →
    // proveedor_servicio → proveedor_tarifa) en vez de mockear nada.
    // ═══════════════════════════════════════════════════════════════

    private function crearProveedorTarifa(string $modalidad = 'compartido'): ProveedorTarifa
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
            'precio_costo' => 60,
            'margen_tipo' => 'porcentaje',
            'margen_valor' => 20,
            'precio_venta_adulto' => 120,
            'vigente_desde' => now()->toDateString(),
            'tip_afe_igv' => '10',
            'destino_tributario' => 'nacional',
        ]);
    }

    private function crearTour(string $nombre = 'Alto Mayo Full Day'): PaquetePlantilla
    {
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);

        return PaquetePlantilla::create([
            'categoria' => 'local',
            'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE,
            'nombre' => $nombre,
            'destino_atractivo_id' => $destino->id,
            'duracion_horas' => 8,
        ]);
    }

    // Arma cliente → cotización → alternativa → alternativa_item
    // (origen_tipo=proveedor) y crea la reserva real vía
    // ReservaController::crearReservaDesdeAlternativa() — mismo camino que
    // dispara engancharSalidaOperativa() en producción (aceptar una
    // alternativa). $tourOrigenId=null simula un ítem agregado 100% suelto
    // (nunca pasó por "cargar desde plantilla").
    private function crearReservaConItemProveedor(
        ?int $tourOrigenId,
        string $fechaViajeDesde,
        int $diaReferencial,
        string $modalidad,
        string $codigoCotizacion
    ): array {
        $tarifa = $this->crearProveedorTarifa($modalidad);

        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '87654321', 'full_name' => 'Cliente Test Salida',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => $codigoCotizacion, 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => $fechaViajeDesde,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId,
            'nombre' => 'Alternativa 1',
            'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN',
            'tipo_cambio_aplicado' => 1,
            'tipo_cambio_origen' => 'dia',
        ]);

        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id,
            'origen_tipo' => AlternativaItem::ORIGEN_PROVEEDOR,
            'proveedor_tarifa_id' => $tarifa->id,
            'tour_origen_id' => $tourOrigenId,
            'dia_referencial' => $diaReferencial,
            'modo_precio' => 'tarifa_fija',
            'cantidad' => 1,
            'moneda_costo' => 'PEN',
            'costo_snapshot' => 60,
            'precio_venta_snapshot' => 120,
            'precio_convertido' => 120,
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        $reservaItem = ReservaItem::where('alternativa_item_id', $item->id)->first();

        return [$reserva, $reservaItem];
    }

    public function test_dos_reservas_mismo_tour_y_fecha_modalidad_compartido_terminan_en_la_misma_salida(): void
    {
        $tour = $this->crearTour();

        [$reservaA, $itemA] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-1001');
        [$reservaB, $itemB] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-1002');

        $this->assertNotNull($itemA->salida_operativa_id);
        $this->assertNotNull($itemB->salida_operativa_id);
        $this->assertSame($itemA->salida_operativa_id, $itemB->salida_operativa_id);
        $this->assertSame(1, SalidaOperativa::count());

        $salida = SalidaOperativa::first();
        $this->assertSame($tour->id, $salida->tour_origen_id);
        $this->assertSame('2026-09-01', $salida->fecha->toDateString());
        $this->assertSame('activa', $salida->estado);
        $this->assertNotSame($reservaA->id, $reservaB->id);
    }

    public function test_item_modalidad_privado_no_se_engancha_a_ninguna_salida(): void
    {
        $tour = $this->crearTour();

        [, $item] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'privado', 'TEST-2026-1003');

        $this->assertNull($item->salida_operativa_id);
        $this->assertSame(0, SalidaOperativa::count());
    }

    public function test_item_sin_tour_origen_id_no_se_engancha_a_ninguna_salida(): void
    {
        [, $item] = $this->crearReservaConItemProveedor(null, '2026-09-01', 1, 'compartido', 'TEST-2026-1004');

        $this->assertNull($item->salida_operativa_id);
        $this->assertSame(0, SalidaOperativa::count());
    }

    public function test_actualizar_guia_de_la_salida_se_refleja_para_ambas_reservas_al_recargar(): void
    {
        $tour = $this->crearTour();

        [, $itemA] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-1005');
        [, $itemB] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-1006');

        $guia = Guia::create(['nombre' => 'Juan Pérez', 'documento' => '12345678', 'telefono' => '999999999']);

        $salida = SalidaOperativa::find($itemA->salida_operativa_id);
        $salida->update(['guia_id' => $guia->id]);

        $guiaVistaDesdeA = $itemA->fresh()->salidaOperativa->guia;
        $guiaVistaDesdeB = $itemB->fresh()->salidaOperativa->guia;

        $this->assertSame($guia->id, $guiaVistaDesdeA->id);
        $this->assertSame($guia->id, $guiaVistaDesdeB->id);
    }

    // Condición de carrera simulada: dos llamadas seguidas sobre la misma
    // clave (tour_origen_id/fecha) deben terminar en la MISMA salida, sin
    // duplicar la fila ni fallar — el índice único parcial + el catch de
    // QueryException en engancharSalidaOperativa() son el backstop real
    // para el caso concurrente (dos requests HTTP distintas), esto prueba
    // que el camino feliz (firstOrCreate ya idempotente) no duplica nada
    // en la ejecución secuencial normal.
    public function test_dos_enganches_seguidos_sobre_la_misma_clave_no_duplican_la_salida(): void
    {
        $tour = $this->crearTour();

        [, $itemA] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-1007');
        [, $itemB] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-1008');
        [, $itemC] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-1009');

        $this->assertSame(1, SalidaOperativa::count());
        $this->assertSame($itemA->salida_operativa_id, $itemB->salida_operativa_id);
        $this->assertSame($itemA->salida_operativa_id, $itemC->salida_operativa_id);
    }

    // ── Bug real (auditoría 2026-09-22): salidas fantasma con 0 pasajeros ──
    // Una salida solo se crea al enganchar un ítem (no hay store() propio,
    // ver rutas) — así que una salida sin ítems no tiene ninguna razón para
    // seguir existiendo. Los 3 puntos donde un ítem deja de pertenecer a una
    // salida deben limpiarla si quedó vacía, sin tocarla si sigue compartida.

    public function test_detach_ultimo_item_de_la_salida_la_borra(): void
    {
        $tour = $this->crearTour();
        [, $item] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-1010');
        $salidaId = $item->salida_operativa_id;
        $this->assertNotNull($salidaId);

        $response = app(SalidaOperativaController::class)->detachReservaItem((string) $salidaId, (string) $item->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, SalidaOperativa::count(), 'la salida vacía debió borrarse sola');
        $this->assertNull($item->fresh()->salida_operativa_id);
    }

    public function test_detach_no_borra_la_salida_si_sigue_compartida_por_otra_reserva(): void
    {
        $tour = $this->crearTour();
        [, $itemA] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-1011');
        [, $itemB] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-1012');
        $salidaId = $itemA->salida_operativa_id;
        $this->assertSame($salidaId, $itemB->salida_operativa_id);

        $response = app(SalidaOperativaController::class)->detachReservaItem((string) $salidaId, (string) $itemA->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, SalidaOperativa::count(), 'sigue compartida por la reserva B, no debe borrarse');
        $this->assertSame($salidaId, $itemB->fresh()->salida_operativa_id);
    }

    public function test_quitar_ultimo_item_enganchado_a_una_salida_la_borra(): void
    {
        $tour = $this->crearTour();
        $tarifa = $this->crearProveedorTarifa('compartido');

        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '11119999', 'full_name' => 'Cliente Test Salida Destroy',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-1013', 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);

        // Ítem 1: se engancha solo a la salida (proveedor, compartido, con tour).
        $itemProveedor = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => AlternativaItem::ORIGEN_PROVEEDOR,
            'proveedor_tarifa_id' => $tarifa->id, 'tour_origen_id' => $tour->id, 'dia_referencial' => 1,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 60, 'precio_venta_snapshot' => 120, 'precio_convertido' => 120,
        ]);
        // Ítem 2: manual, nunca se engancha a ninguna salida — asegura que
        // la reserva tenga 2 ítems (el guard "último ítem" es sobre la
        // RESERVA, no sobre la salida).
        AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 1,
            'descripcion_manual' => 'Ítem manual, sin salida', 'modo_precio' => 'tarifa_fija', 'cantidad' => 1,
            'moneda_costo' => 'PEN', 'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'precio_convertido' => 15,
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());
        $reservaItemProveedor = ReservaItem::where('alternativa_item_id', $itemProveedor->id)->first();
        $this->assertNotNull($reservaItemProveedor->salida_operativa_id);
        $this->assertSame(2, $reserva->items()->count());

        $response = app(ReservaItemController::class)->destroy((string) $reservaItemProveedor->id);

        $this->assertSame(200, $response->getStatusCode(), json_encode($response->getData(true)));
        $this->assertSame(0, SalidaOperativa::count(), 'la salida quedó sin ítems, debió borrarse sola');
        $this->assertSame(1, $reserva->items()->count());
    }

    // Bug real, a raíz de la pregunta "qué debe pasar al cancelar una
    // reserva" (2026-09-22): cancelar() nunca desenganchaba los ítems de su
    // SalidaOperativa (tablero de despacho) — una reserva cancelada seguía
    // contando pax/reservas en SalidaOperativaController::resumenSalida()
    // como si fuera a viajar. cancelar() ahora desengancha y limpia si
    // queda vacía, mismo patrón que reprogramar().
    public function test_cancelar_reserva_desengancha_de_la_salida_y_la_borra_si_queda_vacia(): void
    {
        $tour = $this->crearTour();
        [$reserva, $item] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-1014');
        $salidaId = $item->salida_operativa_id;
        $this->assertNotNull($salidaId);

        if (! DB::table('roles')->where('id', 1)->exists()) {
            DB::table('roles')->insert([
                'id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");
        }
        $role = Role::firstOrCreate(['guard_name' => 'api', 'name' => 'Super-Admin']);
        $admin = User::factory()->create();
        $admin->assignRole($role);
        Auth::guard('api')->setUser($admin->fresh());

        $response = app(ReservaController::class)->cancelar(
            new Request(['motivo_cancelacion' => 'voluntaria']),
            (string) $reserva->id
        );

        $this->assertSame(200, $response->getStatusCode(), json_encode($response->getData(true)));
        $this->assertSame('cancelada', $reserva->fresh()->estado);
        $this->assertNull($item->fresh()->salida_operativa_id);
        $this->assertSame(0, SalidaOperativa::count(), 'quedó sin ítems, debió borrarse sola');
    }

    public function test_cancelar_reserva_no_borra_la_salida_si_sigue_compartida_por_otra_reserva_activa(): void
    {
        $tour = $this->crearTour();
        [$reservaA, $itemA] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-1015');
        [, $itemB] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-1016');
        $salidaId = $itemA->salida_operativa_id;
        $this->assertSame($salidaId, $itemB->salida_operativa_id);

        if (! DB::table('roles')->where('id', 1)->exists()) {
            DB::table('roles')->insert([
                'id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");
        }
        $role = Role::firstOrCreate(['guard_name' => 'api', 'name' => 'Super-Admin']);
        $admin = User::factory()->create();
        $admin->assignRole($role);
        Auth::guard('api')->setUser($admin->fresh());

        $response = app(ReservaController::class)->cancelar(
            new Request(['motivo_cancelacion' => 'voluntaria']),
            (string) $reservaA->id
        );

        $this->assertSame(200, $response->getStatusCode(), json_encode($response->getData(true)));
        $this->assertNull($itemA->fresh()->salida_operativa_id, 'la reserva cancelada se desengancha');
        $this->assertSame(1, SalidaOperativa::count(), 'sigue compartida por la reserva B, activa');
        $this->assertSame($salidaId, $itemB->fresh()->salida_operativa_id, 'la reserva B activa no se toca');
    }

    // ── attachReservaItem() — 5º camino hacia salida fantasma (auditoría 2026-09-23) ──

    public function test_attach_reengancha_a_otra_salida_y_libera_la_vieja_si_queda_vacia(): void
    {
        $tour = $this->crearTour();
        [, $item] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-2020');
        $salidaViejaId = $item->salida_operativa_id;
        $this->assertNotNull($salidaViejaId);

        // Salida B armada a mano, sin tour_origen_id (el camino real de
        // "el staff decide agrupar acá", ver docblock de attachReservaItem()).
        $salidaB = SalidaOperativa::create(['fecha' => '2026-12-25', 'estado' => 'activa']);

        $response = app(SalidaOperativaController::class)
            ->attachReservaItem(new Request(['reserva_item_id' => $item->id]), (string) $salidaB->id);

        $this->assertSame(200, $response->getStatusCode(), json_encode($response->getData(true)));
        $this->assertSame($salidaB->id, $item->fresh()->salida_operativa_id);
        $this->assertFalse(SalidaOperativa::where('id', $salidaViejaId)->exists(), 'la salida vieja quedó vacía, debió borrarse sola');
        $this->assertSame(1, SalidaOperativa::count());
    }

    public function test_attach_rechaza_item_de_reserva_no_activa(): void
    {
        $tour = $this->crearTour();
        [$reserva, $item] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-2021');
        $reserva->update(['estado' => 'cancelada']);

        $salidaB = SalidaOperativa::create(['fecha' => '2026-12-25', 'estado' => 'activa']);

        $response = app(SalidaOperativaController::class)
            ->attachReservaItem(new Request(['reserva_item_id' => $item->id]), (string) $salidaB->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNotSame($salidaB->id, $item->fresh()->salida_operativa_id);
    }

    public function test_attach_rechaza_item_ya_facturado(): void
    {
        $tour = $this->crearTour();
        [$reserva, $item] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-2022');

        // users.role_id tiene default(1) a nivel de Postgres —
        // Sale::factory() crea un User::factory() de paso, que revienta
        // con FK violation sin esto (mismo fixture que otros tests del
        // módulo que usan Sale::factory()).
        if (! DB::table('roles')->where('id', 1)->exists()) {
            DB::table('roles')->insert([
                'id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");
        }

        \App\Models\AgenciaViajes\ReservaVenta::create([
            'reserva_id' => $reserva->id, 'sale_id' => \App\Models\Sale\Sale::factory()->create()->id,
            'reserva_item_ids' => [$item->id], 'reserva_pasajero_ids' => [],
        ]);

        $salidaB = SalidaOperativa::create(['fecha' => '2026-12-25', 'estado' => 'activa']);

        $response = app(SalidaOperativaController::class)
            ->attachReservaItem(new Request(['reserva_item_id' => $item->id]), (string) $salidaB->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('facturado', $response->getData(true)['message']);
    }

    // ── resumenSalida() — no debe contar reservas canceladas (auditoría 2026-09-23) ──

    public function test_resumen_salida_no_cuenta_pax_ni_reservas_canceladas(): void
    {
        $tour = $this->crearTour();
        [$reservaA] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-2023');
        [$reservaB, $itemB] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-2024');
        $salidaId = $itemB->salida_operativa_id;

        $reservaA->update(['estado' => 'cancelada']);

        $response = app(SalidaOperativaController::class)->show((string) $salidaId);
        $body = $response->getData(true);

        $this->assertSame(1, $body['total_reservas'], 'la reserva cancelada no debe contarse');
        $this->assertSame($reservaB->pasajeros()->count(), $body['total_pax']);
    }
}
