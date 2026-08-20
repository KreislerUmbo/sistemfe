<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaItemController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\SalidaOperativa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Fase 2 del fix Cotización↔Reserva (brief "Fix fechas Cotización↔Reserva,
// FASE 2 (reprogramación)" — 2026-08-19). Mismo patrón de infraestructura
// que ReservaFechaAutoCompletadaTest: Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
class ReservaReprogramarTest extends TestCase
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

    /**
     * Arma una reserva activa con 2 ítems 'proveedor'/modalidad=compartido
     * (día 1 y día 2, mismo tour_origen_id — se enganchan solos a
     * SalidaOperativa al crearse) más 1 ítem manual (día 3, sin
     * proveedor_tarifa, nunca se engancha). fecha_viaje_desde inicial:
     * 2026-09-01.
     */
    private function crearReservaConItems(): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '22334455', 'full_name' => 'Cliente Test Reprogramar',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0200', 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-01', 'fecha_viaje_hasta' => '2026-09-03',
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

        // Fixture mínima de proveedor_tarifa modalidad=compartido, para
        // que engancharSalidaOperativa() tenga algo real que enganchar.
        $destinoAtractivoId = DB::table('destinos_atractivos')->insertGetId([
            'nombre' => 'Tarapoto', 'tipo' => 'lugar', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $servicioId = DB::table('servicios')->insertGetId([
            'nombre' => 'City tour', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $destinoServicioId = DB::table('destino_servicio')->insertGetId([
            'destino_atractivo_id' => $destinoAtractivoId, 'servicio_id' => $servicioId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorId = DB::table('proveedores')->insertGetId([
            'razon_social' => 'Operador Test SAC', 'estado' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorServicioId = DB::table('proveedor_servicios')->insertGetId([
            'proveedor_id' => $proveedorId, 'destino_servicio_id' => $destinoServicioId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorTarifaId = DB::table('proveedor_tarifas')->insertGetId([
            'proveedor_servicio_id' => $proveedorServicioId,
            'tipo_tarifa' => 'publica', 'modalidad' => 'compartido', 'moneda' => 'PEN',
            'precio_costo' => 20, 'margen_tipo' => 'fijo', 'margen_valor' => 10,
            'precio_venta_adulto' => 30,
            'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $tourOrigenId = DB::table('paquetes_plantilla')->insertGetId([
            'nombre' => 'Tour Test Reprogramar', 'categoria' => 'local',
            'destino_atractivo_id' => $destinoAtractivoId, 'duracion_horas' => 8,
            'tipo' => 'tour_simple', 'activo' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $itemDia1 = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor',
            'proveedor_tarifa_id' => $proveedorTarifaId, 'tour_origen_id' => $tourOrigenId, 'dia_referencial' => 1,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 20, 'precio_venta_snapshot' => 30, 'precio_convertido' => 30,
        ]);
        $itemDia2 = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor',
            'proveedor_tarifa_id' => $proveedorTarifaId, 'tour_origen_id' => $tourOrigenId, 'dia_referencial' => 2,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 20, 'precio_venta_snapshot' => 30, 'precio_convertido' => 30,
        ]);
        $itemManual = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 3,
            'descripcion_manual' => 'Traslado suelto', 'modo_precio' => 'tarifa_fija', 'cantidad' => 1,
            'moneda_costo' => 'PEN', 'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'precio_convertido' => 15,
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        return [$reserva, $itemDia1, $itemDia2, $itemManual];
    }

    public function test_reprogramar_mueve_items_auto_preserva_manuales_y_lista_los_no_tocados(): void
    {
        [$reserva, $itemDia1, $itemDia2, $itemManual] = $this->crearReservaConItems();

        $reservaItemDia1 = ReservaItem::where('alternativa_item_id', $itemDia1->id)->first();
        $reservaItemDia2 = ReservaItem::where('alternativa_item_id', $itemDia2->id)->first();
        $reservaItemManual = ReservaItem::where('alternativa_item_id', $itemManual->id)->first();

        $this->assertSame('2026-09-01', $reservaItemDia1->fecha->toDateString());
        $this->assertSame('2026-09-02', $reservaItemDia2->fecha->toDateString());
        $this->assertSame('2026-09-03', $reservaItemManual->fecha->toDateString());

        $salidaViejaDia1 = $reservaItemDia1->salida_operativa_id;
        $this->assertNotNull($salidaViejaDia1, 'el ítem día 1 debía engancharse solo a una SalidaOperativa al crear la reserva');

        // Un operador edita a mano el ítem manual ANTES de reprogramar —
        // debe sobrevivir intacto.
        app(ReservaItemController::class)->update(new Request(['fecha' => '2026-09-20']), (string) $reservaItemManual->id);
        $this->assertSame('manual', $reservaItemManual->fresh()->fecha_origen);

        $response = app(ReservaController::class)->reprogramar(new Request([
            'fecha_viaje_desde' => '2026-10-01',
            'fecha_viaje_hasta' => '2026-10-05',
            'motivo' => 'Cliente pidió correr el viaje 30 días.',
        ]), (string) $reserva->id);

        $body = $response->getData(true);
        $this->assertSame(200, $body['code']);

        $reservaFresh = $reserva->fresh();
        $this->assertSame('2026-10-01', $reservaFresh->fecha_viaje_desde->toDateString());
        $this->assertSame('2026-10-05', $reservaFresh->fecha_viaje_hasta->toDateString());
        $this->assertSame('2026-09-01', $reservaFresh->fecha_viaje_desde_original->toDateString());
        $this->assertSame('2026-09-03', $reservaFresh->fecha_viaje_hasta_original->toDateString());
        $this->assertNotNull($reservaFresh->fecha_reprogramacion);
        $this->assertSame('Cliente pidió correr el viaje 30 días.', $reservaFresh->motivo_reprogramacion);

        // Ítems 'auto': recalculados contra la nueva base.
        $this->assertSame('2026-10-01', $reservaItemDia1->fresh()->fecha->toDateString());
        $this->assertSame('2026-10-02', $reservaItemDia2->fresh()->fecha->toDateString());

        // Ítem 'manual': intacto, NO se movió.
        $this->assertSame('2026-09-20', $reservaItemManual->fresh()->fecha->toDateString());

        // Respuesta lista explícitamente el ítem manual como no tocado.
        $this->assertCount(1, $body['items_no_tocados']);
        $this->assertSame($reservaItemManual->id, $body['items_no_tocados'][0]['reserva_item_id']);

        // Re-enganche a SalidaOperativa: el ítem día 1 se desenganchó de la
        // salida vieja y quedó enganchado a una salida NUEVA en la fecha
        // nueva (mismo tour_origen_id).
        $salidaNuevaDia1 = $reservaItemDia1->fresh()->salida_operativa_id;
        $this->assertNotNull($salidaNuevaDia1);
        $this->assertNotSame($salidaViejaDia1, $salidaNuevaDia1);

        $salidaNueva = SalidaOperativa::find($salidaNuevaDia1);
        $this->assertSame('2026-10-01', $salidaNueva->fecha->toDateString());

        // La salida vieja sigue existiendo (no se borra, podría estar
        // compartida por otra reserva) pero ya no tiene este ítem.
        $this->assertTrue(SalidaOperativa::where('id', $salidaViejaDia1)->exists());
        $this->assertSame(0, ReservaItem::where('salida_operativa_id', $salidaViejaDia1)->count());
    }

    // Guardia de visibilidad (2026-08-20): un ítem sin dia_referencial no se
    // puede recalcular en automático, pero antes quedaba con su fecha vieja
    // SIN aparecer en items_no_tocados (indistinguible de "sí se movió" para
    // el vendedor). Ahora debe listarse igual que los 'manual', con su
    // propio motivo.
    public function test_reprogramar_lista_items_sin_dia_referencial_como_no_tocados(): void
    {
        [$reserva] = $this->crearReservaConItems();

        $itemSinDia = AlternativaItem::create([
            'alternativa_id' => $reserva->alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => null,
            'descripcion_manual' => 'Servicio sin día fijo', 'modo_precio' => 'tarifa_fija', 'cantidad' => 1,
            'moneda_costo' => 'PEN', 'costo_snapshot' => 5, 'precio_venta_snapshot' => 8, 'precio_convertido' => 8,
        ]);
        $reservaItemSinDia = ReservaItem::create([
            'reserva_id' => $reserva->id, 'alternativa_item_id' => $itemSinDia->id,
            'fecha' => '2026-09-01', 'fecha_origen' => ReservaItem::FECHA_ORIGEN_AUTO,
        ]);

        $response = app(ReservaController::class)->reprogramar(new Request([
            'fecha_viaje_desde' => '2026-10-01',
            'motivo' => 'Test dia_referencial null',
        ]), (string) $reserva->id);

        $body = $response->getData(true);
        $this->assertSame(200, $body['code']);

        // Sigue con su fecha vieja (no hay insumo para recalcularla)...
        $this->assertSame('2026-09-01', $reservaItemSinDia->fresh()->fecha->toDateString());

        // ...pero ahora SÍ aparece listado, con el motivo correcto — antes
        // quedaba invisible en la respuesta.
        $entrada = collect($body['items_no_tocados'])->firstWhere('reserva_item_id', $reservaItemSinDia->id);
        $this->assertNotNull($entrada, 'debe aparecer en items_no_tocados, no quedar invisible');
        $this->assertSame('sin_dia_referencial', $entrada['motivo']);
    }

    public function test_reprogramar_rechaza_reserva_no_activa(): void
    {
        [$reserva] = $this->crearReservaConItems();
        $reserva->update(['estado' => 'cancelada']);

        $response = app(ReservaController::class)->reprogramar(new Request([
            'fecha_viaje_desde' => '2026-10-01',
            'motivo' => 'No debería aplicar',
        ]), (string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Solo se puede reprogramar una reserva activa.', $response->getData(true)['message']);
    }

    public function test_reprogramar_exige_motivo(): void
    {
        [$reserva] = $this->crearReservaConItems();

        $response = app(ReservaController::class)->reprogramar(new Request([
            'fecha_viaje_desde' => '2026-10-01',
        ]), (string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_reprogramar_dos_veces_pisa_el_original_con_el_estado_intermedio(): void
    {
        [$reserva] = $this->crearReservaConItems();

        app(ReservaController::class)->reprogramar(new Request([
            'fecha_viaje_desde' => '2026-10-01', 'motivo' => 'Primera reprogramación',
        ]), (string) $reserva->id);

        app(ReservaController::class)->reprogramar(new Request([
            'fecha_viaje_desde' => '2026-11-01', 'motivo' => 'Segunda reprogramación',
        ]), (string) $reserva->id);

        $reservaFresh = $reserva->fresh();
        $this->assertSame('2026-11-01', $reservaFresh->fecha_viaje_desde->toDateString());
        // El "_original" quedó con el estado INTERMEDIO (tras la primera
        // reprogramación), no con la fecha real de creación (2026-09-01) —
        // trade-off documentado explícitamente en el brief y en el docblock.
        $this->assertSame('2026-10-01', $reservaFresh->fecha_viaje_desde_original->toDateString());
        $this->assertSame('Segunda reprogramación', $reservaFresh->motivo_reprogramacion);
    }
}
