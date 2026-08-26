<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReporteOperativoController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaItemController;
use App\Http\Controllers\AgenciaViajes\ReservaItemPasajeroController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaPasajero;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Sesión 11e (plan-hoja-de-ruta-ejecucion.md) — plan-modulo-cotizaciones-reservas.md §8.
// Mismo patrón de infraestructura que ReservaReprogramarTest: Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
class ReporteOperativoTest extends TestCase
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
     * Reserva activa, fecha_viaje_desde = 2026-09-01, con 2 pasajeros y 5 ítems
     * el día 1 (proveedor asignado a un proveedor real, proveedor asignado a
     * un proveedor referencial, proveedor sin asignar, guía sin asignar,
     * manual) más 1 ítem manual el día 3 (para el filtro de rango de fecha).
     * Solo el ítem "proveedor asignado" queda vinculado a un solo pasajero —
     * el resto no tiene ningún reserva_item_pasajero (caso real mayoritario).
     */
    private function crearReservaConItems(): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '33445566', 'full_name' => 'Cliente Test Reporte',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0300', 'cliente_id' => $clienteId,
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

        CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 30]);
        CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 28]);

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

        $proveedorRealId = DB::table('proveedores')->insertGetId([
            'razon_social' => 'Operador Real SAC', 'estado' => true, 'es_referencial' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorReferencialId = DB::table('proveedores')->insertGetId([
            'razon_social' => 'Operador Referencial SAC', 'estado' => true, 'es_referencial' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorServicioRealId = DB::table('proveedor_servicios')->insertGetId([
            'proveedor_id' => $proveedorRealId, 'destino_servicio_id' => $destinoServicioId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorServicioReferencialId = DB::table('proveedor_servicios')->insertGetId([
            'proveedor_id' => $proveedorReferencialId, 'destino_servicio_id' => $destinoServicioId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tarifaBase = [
            'tipo_tarifa' => 'publica', 'modalidad' => 'compartido', 'moneda' => 'PEN',
            'precio_costo' => 20, 'margen_tipo' => 'fijo', 'margen_valor' => 10, 'precio_venta_adulto' => 30,
            'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
            'created_at' => now(), 'updated_at' => now(),
        ];
        $tarifaRealId = DB::table('proveedor_tarifas')->insertGetId(
            $tarifaBase + ['proveedor_servicio_id' => $proveedorServicioRealId]
        );
        $tarifaReferencialId = DB::table('proveedor_tarifas')->insertGetId(
            $tarifaBase + ['proveedor_servicio_id' => $proveedorServicioReferencialId]
        );
        $tarifaParaVaciarId = DB::table('proveedor_tarifas')->insertGetId(
            $tarifaBase + ['proveedor_servicio_id' => $proveedorServicioRealId]
        );

        $guiaReferencialId = DB::table('guias')->insertGetId([
            'nombre' => 'Guía Referencial', 'documento' => '00000000', 'telefono' => '900000000',
            'es_referencial' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $itemsBase = [
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 20, 'precio_venta_snapshot' => 30, 'precio_convertido' => 30,
        ];

        $itemProveedorAsignado = AlternativaItem::create($itemsBase + [
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor',
            'proveedor_tarifa_id' => $tarifaRealId, 'dia_referencial' => 1,
        ]);
        $itemProveedorReferencial = AlternativaItem::create($itemsBase + [
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor',
            'proveedor_tarifa_id' => $tarifaReferencialId, 'dia_referencial' => 1,
        ]);
        $itemProveedorParaVaciar = AlternativaItem::create($itemsBase + [
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor',
            'proveedor_tarifa_id' => $tarifaParaVaciarId, 'dia_referencial' => 1,
        ]);
        $itemGuia = AlternativaItem::create($itemsBase + [
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'guia', 'dia_referencial' => 1,
        ]);
        $itemManualDia1 = AlternativaItem::create($itemsBase + [
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 1,
            'descripcion_manual' => 'Traslado suelto',
        ]);
        $itemManualDia3 = AlternativaItem::create($itemsBase + [
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 3,
            'descripcion_manual' => 'Despedida día 3',
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        $riProveedorAsignado = ReservaItem::where('alternativa_item_id', $itemProveedorAsignado->id)->firstOrFail();
        $riProveedorReferencial = ReservaItem::where('alternativa_item_id', $itemProveedorReferencial->id)->firstOrFail();
        $riProveedorSinAsignar = ReservaItem::where('alternativa_item_id', $itemProveedorParaVaciar->id)->firstOrFail();
        $riGuia = ReservaItem::where('alternativa_item_id', $itemGuia->id)->firstOrFail();
        $riManualDia1 = ReservaItem::where('alternativa_item_id', $itemManualDia1->id)->firstOrFail();
        $riManualDia3 = ReservaItem::where('alternativa_item_id', $itemManualDia3->id)->firstOrFail();

        // Simula "quitaron al operador, todavía sin reasignar" — el backend
        // ya permite vaciar este campo explícitamente (ReservaItemController::update()).
        $riProveedorSinAsignar->update(['proveedor_tarifa_id' => null]);

        [$pasajero1, $pasajero2] = ReservaPasajero::where('reserva_id', $reserva->id)->orderBy('id')->get()->all();
        $pasajero1->update([
            'nombre' => 'Pasajero Uno', 'documento' => '11111111',
            'alimentacion_especial' => 'Vegetariano', 'discapacidad' => 'Silla de ruedas',
            'vuelo_aerolinea_ida' => 'LATAM', 'vuelo_fecha_ida' => '2026-09-01', 'vuelo_hora_ida' => '08:00',
        ]);
        $pasajero2->update(['nombre' => 'Pasajero Dos', 'documento' => '22222222']);

        // Único ítem con vínculo específico — solo pasajero1, no pasajero2.
        app(ReservaItemPasajeroController::class)->store(
            new Request(['reserva_pasajero_id' => $pasajero1->id]),
            (string) $riProveedorAsignado->id
        );

        return compact(
            'reserva', 'pasajero1', 'pasajero2',
            'riProveedorAsignado', 'riProveedorReferencial', 'riProveedorSinAsignar',
            'riGuia', 'riManualDia1', 'riManualDia3', 'guiaReferencialId'
        );
    }

    public function test_item_sin_vinculo_especifico_devuelve_una_fila_por_cada_pasajero_de_la_reserva(): void
    {
        $f = $this->crearReservaConItems();

        $response = app(ReporteOperativoController::class)->index(new Request([
            'fecha_desde' => '2026-09-01', 'fecha_hasta' => '2026-09-01',
        ]));
        $body = $response->getData(true);

        $filasGuia = array_values(array_filter($body['filas'], fn ($f2) => $f2['reserva_item_id'] === $f['riGuia']->id));
        $this->assertCount(2, $filasGuia, 'el ítem sin vínculo específico debe aplicar a los 2 pasajeros de la reserva');
        $this->assertFalse($filasGuia[0]['vinculo_especifico']);
        $nombres = array_column(array_column($filasGuia, 'pasajero'), 'nombre');
        $this->assertContains('Pasajero Uno', $nombres);
        $this->assertContains('Pasajero Dos', $nombres);
    }

    public function test_item_con_vinculo_especifico_solo_devuelve_el_pasajero_vinculado(): void
    {
        $f = $this->crearReservaConItems();

        $response = app(ReporteOperativoController::class)->index(new Request([
            'fecha_desde' => '2026-09-01', 'fecha_hasta' => '2026-09-01',
        ]));
        $body = $response->getData(true);

        $filas = array_values(array_filter($body['filas'], fn ($f2) => $f2['reserva_item_id'] === $f['riProveedorAsignado']->id));
        $this->assertCount(1, $filas);
        $this->assertTrue($filas[0]['vinculo_especifico']);
        $this->assertSame('Pasajero Uno', $filas[0]['pasajero']['nombre']);
        $this->assertSame('Vegetariano', $filas[0]['pasajero']['alimentacion_especial']);
        $this->assertSame('Silla de ruedas', $filas[0]['pasajero']['discapacidad']);
        $this->assertSame('LATAM', $filas[0]['vuelo_ida']['aerolinea']);
        $this->assertNull($filas[0]['vuelo_vuelta']);
    }

    // Mejora post-11d — reasignación de proveedor inline: el reporte necesita el
    // nombre del proveedor asignado a CUALQUIER ítem origen_tipo='proveedor' (antes
    // solo se exponía para hoteles vía 'hotel'), y servicio_id para poder filtrar los
    // candidatos igual que reservas/detalle.vue.
    public function test_expone_proveedor_asignado_y_servicio_id_para_items_de_proveedor(): void
    {
        $f = $this->crearReservaConItems();

        $body = app(ReporteOperativoController::class)->index(new Request([
            'fecha_desde' => '2026-09-01', 'fecha_hasta' => '2026-09-01',
        ]))->getData(true);

        $filaProveedor = collect($body['filas'])->firstWhere('reserva_item_id', $f['riProveedorAsignado']->id);
        $this->assertSame('Operador Real SAC', $filaProveedor['proveedor']);
        $this->assertNotNull($filaProveedor['servicio_id']);

        // origen_tipo='guia'/'manual' no tienen proveedor_tarifa — el campo debe ser null,
        // no romper ni inventar un valor.
        $filaGuia = collect($body['filas'])->firstWhere('reserva_item_id', $f['riGuia']->id);
        $this->assertNull($filaGuia['proveedor']);
    }

    public function test_reserva_cancelada_queda_excluida(): void
    {
        $f = $this->crearReservaConItems();
        $f['reserva']->update(['estado' => 'cancelada']);

        $response = app(ReporteOperativoController::class)->index(new Request([
            'fecha_desde' => '2026-09-01', 'fecha_hasta' => '2026-09-01',
        ]));
        $body = $response->getData(true);

        $this->assertCount(0, $body['filas']);
    }

    public function test_sin_guia_segun_asignacion_es_referencial_y_origen_tipo(): void
    {
        $f = $this->crearReservaConItems();

        $response = app(ReporteOperativoController::class)->index(new Request([
            'fecha_desde' => '2026-09-01', 'fecha_hasta' => '2026-09-01',
        ]));
        $body = $response->getData(true);
        $sinGuiaPorItem = [];
        foreach ($body['filas'] as $fila) {
            $sinGuiaPorItem[$fila['reserva_item_id']] = $fila['sin_guia'];
        }

        $this->assertFalse($sinGuiaPorItem[$f['riProveedorAsignado']->id], 'proveedor real asignado: no debe alertar');
        $this->assertTrue($sinGuiaPorItem[$f['riProveedorReferencial']->id], 'proveedor referencial: cuenta como pendiente');
        $this->assertTrue($sinGuiaPorItem[$f['riProveedorSinAsignar']->id], 'sin proveedor_tarifa_id: pendiente');
        $this->assertTrue($sinGuiaPorItem[$f['riGuia']->id], 'guia_id null: pendiente');
        $this->assertFalse($sinGuiaPorItem[$f['riManualDia1']->id], 'manual: nunca aplica la alerta');

        // Asignar un guía real al ítem 'guia' quita la alerta.
        app(ReservaItemController::class)->update(new Request(['guia_id' => $this->crearGuiaReal()]), (string) $f['riGuia']->id);
        $response2 = app(ReporteOperativoController::class)->index(new Request([
            'fecha_desde' => '2026-09-01', 'fecha_hasta' => '2026-09-01',
        ]));
        $filaGuia = collect($response2->getData(true)['filas'])->firstWhere('reserva_item_id', $f['riGuia']->id);
        $this->assertFalse($filaGuia['sin_guia']);
        $this->assertNotNull($filaGuia['guia']);
        $this->assertFalse($filaGuia['guia']['es_referencial']);

        // Asignar el guía referencial vuelve a marcar la alerta.
        app(ReservaItemController::class)->update(new Request(['guia_id' => $f['guiaReferencialId']]), (string) $f['riGuia']->id);
        $response3 = app(ReporteOperativoController::class)->index(new Request([
            'fecha_desde' => '2026-09-01', 'fecha_hasta' => '2026-09-01',
        ]));
        $filaGuia3 = collect($response3->getData(true)['filas'])->firstWhere('reserva_item_id', $f['riGuia']->id);
        $this->assertTrue($filaGuia3['sin_guia']);
    }

    private function crearGuiaReal(): int
    {
        return DB::table('guias')->insertGetId([
            'nombre' => 'Guía Real', 'documento' => '99999999', 'telefono' => '900000001',
            'es_referencial' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_filtro_rango_de_fecha_acota_correctamente(): void
    {
        $f = $this->crearReservaConItems();

        $soloDia1 = app(ReporteOperativoController::class)->index(new Request([
            'fecha_desde' => '2026-09-01', 'fecha_hasta' => '2026-09-01',
        ]))->getData(true);
        $idsDia1 = array_unique(array_column($soloDia1['filas'], 'reserva_item_id'));
        $this->assertNotContains($f['riManualDia3']->id, $idsDia1);

        $rangoCompleto = app(ReporteOperativoController::class)->index(new Request([
            'fecha_desde' => '2026-09-01', 'fecha_hasta' => '2026-09-03',
        ]))->getData(true);
        $idsRango = array_unique(array_column($rangoCompleto['filas'], 'reserva_item_id'));
        $this->assertContains($f['riManualDia3']->id, $idsRango);
    }

    public function test_filtro_pendiente_asignar_solo_trae_filas_sin_guia(): void
    {
        $f = $this->crearReservaConItems();

        $response = app(ReporteOperativoController::class)->index(new Request([
            'fecha_desde' => '2026-09-01', 'fecha_hasta' => '2026-09-01', 'pendiente_asignar' => true,
        ]));
        $body = $response->getData(true);

        $this->assertNotEmpty($body['filas']);
        foreach ($body['filas'] as $fila) {
            $this->assertTrue($fila['sin_guia']);
        }
        $idsDevueltos = array_unique(array_column($body['filas'], 'reserva_item_id'));
        $this->assertNotContains($f['riProveedorAsignado']->id, $idsDevueltos);
        $this->assertNotContains($f['riManualDia1']->id, $idsDevueltos);
    }

    public function test_default_sin_parametros_de_fecha_usa_hoy(): void
    {
        $response = app(ReporteOperativoController::class)->index(new Request());
        $body = $response->getData(true);

        $this->assertSame(now()->toDateString(), $body['fecha_desde']);
        $this->assertSame(now()->toDateString(), $body['fecha_hasta']);
    }
}
