<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Sesión M1 — núcleo de la matriz de opciones de hotel. Brief:
// PEGAR-EN-CLAUDE-CODE-matriz-hoteles-m1-nucleo.md. Backend puro, sin UI
// (eso es M4) — los grupos se arman directo por factory, mismo criterio
// que pide el brief. Mismo patrón de infraestructura que el resto de la
// suite: Postgres real (sistemafe_test_migrations), transacción por test
// revertida.
class SesionM1MatrizHotelesNucleoTest extends TestCase
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

    private function crearAlternativa(string $estado = 'borrador'): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '44556677', 'full_name' => 'Cliente Test M1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-M1-' . random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Cusco', 'fecha_viaje_desde' => '2026-10-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa M1', 'estado' => $estado,
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
    }

    // Fixture mínima real de proveedor_tarifa (hotel), con piso opcional
    // (descuento_maximo_pct) para los tests que necesitan que el reparto
    // de descuento lo respete de verdad, no solo que no truene.
    private function crearProveedorTarifaHotel(float $precioVenta, float $costo, ?float $descuentoMaximoPct = null): int
    {
        $destinoAtractivoId = DB::table('destinos_atractivos')->insertGetId([
            'nombre' => 'Cusco M1 Test ' . random_int(1000, 9999), 'tipo' => 'lugar', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $servicioId = DB::table('servicios')->insertGetId([
            'nombre' => 'Hospedaje M1 Test', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $destinoServicioId = DB::table('destino_servicio')->insertGetId([
            'destino_atractivo_id' => $destinoAtractivoId, 'servicio_id' => $servicioId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorId = DB::table('proveedores')->insertGetId([
            'razon_social' => 'Hotel Test M1 SAC ' . random_int(1000, 9999), 'estado' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorServicioId = DB::table('proveedor_servicios')->insertGetId([
            'proveedor_id' => $proveedorId, 'destino_servicio_id' => $destinoServicioId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('proveedor_tarifas')->insertGetId([
            'proveedor_servicio_id' => $proveedorServicioId,
            'tipo_tarifa' => 'publica', 'modalidad' => 'privado', 'moneda' => 'PEN',
            'precio_costo' => $costo, 'margen_tipo' => 'fijo', 'margen_valor' => $precioVenta - $costo,
            'precio_venta_adulto' => $precioVenta, 'descuento_maximo_pct' => $descuentoMaximoPct,
            'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function crearItemHotel(Alternativa $alternativa, float $precioVenta, float $costo, ?string $grupoId = null, bool $elegida = false, ?float $descuentoMaximoPct = null): AlternativaItem
    {
        $proveedorTarifaId = $this->crearProveedorTarifaHotel($precioVenta, $costo, $descuentoMaximoPct);

        return AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => AlternativaItem::ORIGEN_PROVEEDOR,
            'proveedor_tarifa_id' => $proveedorTarifaId, 'grupo_opcion_id' => $grupoId, 'opcion_elegida' => $elegida,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => $costo, 'precio_venta_snapshot' => $precioVenta, 'precio_convertido' => $precioVenta,
        ]);
    }

    // ── §1 — regresión: ítems sin grupo se comportan exactamente igual ──

    public function test_item_sin_grupo_no_cambia_de_comportamiento(): void
    {
        $alternativa = $this->crearAlternativa();
        $item = $this->crearItemHotel($alternativa, 200, 150);

        $this->assertNull($item->grupo_opcion_id);
        $this->assertFalse($item->opcion_elegida);

        $resultado = AlternativaItem::calcularTotalEfectivo(collect([$item]));
        $this->assertSame(200.0, $resultado['total']);
        $this->assertFalse($resultado['tiene_grupos_sin_resolver']);
    }

    // ── §2 — guard en aceptar() ──────────────────────────────────────────

    public function test_aceptar_rechaza_grupo_sin_ninguna_elegida(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $this->crearItemHotel($alternativa, 200, 150, $grupo, false);
        $this->crearItemHotel($alternativa, 250, 180, $grupo, false);

        $response = app(ReservaController::class)->aceptar(new Request(), (string) $alternativa->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('grupo(s) de opciones de hotel sin resolver', $response->getData(true)['message']);
        $this->assertSame('borrador', $alternativa->fresh()->estado);
        $this->assertSame(0, \App\Models\AgenciaViajes\Reserva::where('alternativa_id', $alternativa->id)->count());
    }

    public function test_aceptar_rechaza_grupo_con_dos_elegidas(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $this->crearItemHotel($alternativa, 200, 150, $grupo, true);
        $this->crearItemHotel($alternativa, 250, 180, $grupo, true);

        $response = app(ReservaController::class)->aceptar(new Request(), (string) $alternativa->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('borrador', $alternativa->fresh()->estado);
    }

    public function test_aceptar_funciona_con_grupo_resuelto(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $this->crearItemHotel($alternativa, 200, 150, $grupo, true);
        $this->crearItemHotel($alternativa, 250, 180, $grupo, false);

        $response = app(ReservaController::class)->aceptar(new Request(), (string) $alternativa->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('aceptada', $alternativa->fresh()->estado);
    }

    public function test_aceptar_funciona_igual_sin_ningun_grupo(): void
    {
        $alternativa = $this->crearAlternativa();
        $this->crearItemHotel($alternativa, 200, 150);

        $response = app(ReservaController::class)->aceptar(new Request(), (string) $alternativa->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('aceptada', $alternativa->fresh()->estado);
    }

    // ── §3 — precio en vivo ──────────────────────────────────────────────

    public function test_recalcular_total_grupo_abierto_usa_el_minimo(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $this->crearItemHotel($alternativa, 300, 200, $grupo, false);
        $this->crearItemHotel($alternativa, 200, 150, $grupo, false); // el más barato
        $this->crearItemHotel($alternativa, 250, 180, $grupo, false);
        $this->crearItemHotel($alternativa, 50, 30); // ítem normal sin grupo

        $resultado = AlternativaItem::calcularTotalEfectivo($alternativa->items()->get());

        $this->assertSame(250.0, $resultado['total']); // 200 (mínimo) + 50 (sin grupo)
        $this->assertTrue($resultado['tiene_grupos_sin_resolver']);
    }

    public function test_recalcular_total_grupo_resuelto_usa_solo_la_elegida(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $this->crearItemHotel($alternativa, 300, 200, $grupo, false);
        $this->crearItemHotel($alternativa, 250, 180, $grupo, true); // la elegida
        $this->crearItemHotel($alternativa, 50, 30);

        $resultado = AlternativaItem::calcularTotalEfectivo($alternativa->items()->get());

        $this->assertSame(300.0, $resultado['total']); // 250 (elegida) + 50 (sin grupo)
        $this->assertFalse($resultado['tiene_grupos_sin_resolver']);
    }

    public function test_alternativa_tiene_grupos_sin_resolver_refleja_el_estado_real(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $item1 = $this->crearItemHotel($alternativa, 300, 200, $grupo, false);
        $this->crearItemHotel($alternativa, 250, 180, $grupo, false);

        $this->assertTrue($alternativa->fresh()->tiene_grupos_sin_resolver);

        $item1->update(['opcion_elegida' => true]);

        $this->assertFalse($alternativa->fresh()->tiene_grupos_sin_resolver);
    }

    // ── §3 — reparto de descuento_global_pct ────────────────────────────

    public function test_descuento_global_no_aplica_a_ningun_item_de_un_grupo_abierto(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $itemA = $this->crearItemHotel($alternativa, 200, 150, $grupo, false);
        $itemB = $this->crearItemHotel($alternativa, 250, 180, $grupo, false);
        $itemNormal = $this->crearItemHotel($alternativa, 50, 30);

        app(AlternativaController::class)->update(new Request(['descuento_global_pct' => 10]), (string) $alternativa->id);

        $this->assertEquals(0, $itemA->fresh()->descuento_pct);
        $this->assertEquals(0, $itemB->fresh()->descuento_pct);
        $this->assertEquals(200.0, (float) $itemA->fresh()->precio_convertido);
        $this->assertEquals(250.0, (float) $itemB->fresh()->precio_convertido);
        // El ítem normal sí recibe el descuento como siempre.
        $this->assertEquals(10, $itemNormal->fresh()->descuento_pct);
        $this->assertEquals(45.0, (float) $itemNormal->fresh()->precio_convertido);
    }

    public function test_descuento_global_aplica_solo_a_la_elegida_del_grupo_resuelto(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $itemA = $this->crearItemHotel($alternativa, 200, 150, $grupo, false);
        $itemElegida = $this->crearItemHotel($alternativa, 250, 100, $grupo, true); // piso amplio, sin descuento_maximo_pct

        app(AlternativaController::class)->update(new Request(['descuento_global_pct' => 10]), (string) $alternativa->id);

        $this->assertEquals(0, $itemA->fresh()->descuento_pct, 'la no-elegida del grupo resuelto tampoco lleva descuento');
        $this->assertEquals(200.0, (float) $itemA->fresh()->precio_convertido);
        $this->assertEquals(10, $itemElegida->fresh()->descuento_pct);
        $this->assertEquals(225.0, (float) $itemElegida->fresh()->precio_convertido); // 250 * 0.9
    }

    public function test_descuento_global_respeta_el_piso_de_la_elegida(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        // descuento_maximo_pct=5: un descuento global de 20% debe generar
        // alerta de piso para la elegida (mismo motor que un ítem normal).
        $proveedorTarifaId = $this->crearProveedorTarifaHotel(250, 100, 5.0);
        $itemElegida = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => AlternativaItem::ORIGEN_PROVEEDOR,
            'proveedor_tarifa_id' => $proveedorTarifaId, 'grupo_opcion_id' => $grupo, 'opcion_elegida' => true,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 100, 'precio_venta_snapshot' => 250, 'precio_convertido' => 250,
        ]);

        $response = app(AlternativaController::class)->update(new Request(['descuento_global_pct' => 20]), (string) $alternativa->id);

        $lineasFueraDePiso = $response->getData(true)['lineas_fuera_de_piso'];
        $this->assertNotEmpty($lineasFueraDePiso);
        $this->assertSame($itemElegida->id, $lineasFueraDePiso[0]['alternativa_item_id']);
    }

    public function test_cambiar_la_elegida_recalcula_el_descuento_sobre_la_nueva(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $itemA = $this->crearItemHotel($alternativa, 200, 150, $grupo, true);
        $itemB = $this->crearItemHotel($alternativa, 300, 220, $grupo, false);

        app(AlternativaController::class)->update(new Request(['descuento_global_pct' => 10]), (string) $alternativa->id);
        $this->assertEquals(10, $itemA->fresh()->descuento_pct);
        $this->assertEquals(0, $itemB->fresh()->descuento_pct);

        // El vendedor cambia cuál es la elegida (caso que el usuario
        // preguntó explícito en el diseño, Ronda 2/P6).
        $itemA->update(['opcion_elegida' => false]);
        $itemB->update(['opcion_elegida' => true]);

        app(AlternativaController::class)->update(new Request(['descuento_global_pct' => 10]), (string) $alternativa->id);

        $this->assertEquals(0, $itemA->fresh()->descuento_pct, 'la que dejó de ser elegida vuelve a precio de lista');
        $this->assertEquals(200.0, (float) $itemA->fresh()->precio_convertido);
        $this->assertEquals(10, $itemB->fresh()->descuento_pct, 'el descuento se movió a la nueva elegida');
        $this->assertEquals(270.0, (float) $itemB->fresh()->precio_convertido); // 300 * 0.9
    }

    public function test_descuento_global_monto_reparte_correctamente_con_grupos(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        // Grupo abierto: cuenta 1 sola vez por el mínimo (200), no 450 (suma de ambas).
        $this->crearItemHotel($alternativa, 200, 150, $grupo, false);
        $this->crearItemHotel($alternativa, 250, 180, $grupo, false);
        $itemNormal = $this->crearItemHotel($alternativa, 100, 60);

        // sumaPreciosLista efectiva = 200 (mínimo del grupo) + 100 (normal) = 300.
        // monto=30 → 10% efectivo.
        app(AlternativaController::class)->update(new Request(['descuento_global_monto' => 30]), (string) $alternativa->id);

        $this->assertEqualsWithDelta(10.0, (float) $alternativa->fresh()->descuento_global_pct, 0.01);
        $this->assertEquals(90.0, (float) $itemNormal->fresh()->precio_convertido); // 100 * 0.9
    }

    // Guardrail (19-sep-2026, hallazgo real del usuario) — un ítem
    // 'tarifa_fija' con cantidad>1 (mayorista con 2 adultos, ej. "San
    // Francisco Plaza 3* · matrimonial" a USD 1059 x 2 adultos = 2118)
    // hacía que sumaPreciosLista sumara solo el precio UNITARIO (1059),
    // así que pctEfectivo = monto/sumaPreciosLista salía inflado por el
    // mismo factor de cantidad. Pedir un descuento de 118 sobre el total
    // (2118 → 2000) terminaba descontando 236 (118 x 2) → total real 1882,
    // no 2000. sumaPreciosLista ahora multiplica por cantidad para
    // 'tarifa_fija', igual que AlternativaItem::getTotalConvertidoAttribute().
    public function test_descuento_global_monto_con_item_cantidad_mayor_a_uno(): void
    {
        $alternativa = $this->crearAlternativa();
        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => AlternativaItem::ORIGEN_MAYORISTA,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 2, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 800, 'precio_venta_snapshot' => 1059, 'precio_convertido' => 1059,
        ]);

        app(AlternativaController::class)->update(new Request(['descuento_global_monto' => 118]), (string) $alternativa->id);

        $this->assertEqualsWithDelta(2000.0, (float) $alternativa->fresh()->total, 0.5);
        $this->assertEqualsWithDelta(1000.0, (float) $item->fresh()->precio_convertido, 0.5, 'precio unitario, sin multiplicar por cantidad');
    }

    // Guardrail (19-sep-2026, hallazgo real del usuario) — segunda mitad
    // del mismo bug reportado: aunque el TOTAL ya calculaba bien (test de
    // arriba), alternativas.descuento_global_pct nació decimal(5,2) — solo
    // 2 decimales. El % efectivo resuelto desde un monto casi nunca es
    // redondo (2/2118*100 = 0.0944...%), así que Postgres lo truncaba a
    // 0.09 al guardar. El frontend reconstruye un "monto equivalente" para
    // mostrar en el input a partir de ESE % persistido (editar.vue::
    // calcularMontoGlobalEquivalente()) — con el truncamiento, pedías "2"
    // y el campo terminaba mostrando "1.91" al recargar. Migración
    // ampliar_precision_descuento_global_pct ensancha a decimal(9,4).
    public function test_descuento_global_pct_guarda_precision_suficiente_para_reconstruir_el_monto(): void
    {
        $alternativa = $this->crearAlternativa();
        AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => AlternativaItem::ORIGEN_MAYORISTA,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 2, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 800, 'precio_venta_snapshot' => 1059, 'precio_convertido' => 1059,
        ]);

        app(AlternativaController::class)->update(new Request(['descuento_global_monto' => 2]), (string) $alternativa->id);

        $montoReconstruido = 2118 * ((float) $alternativa->fresh()->descuento_global_pct / 100);
        $this->assertEqualsWithDelta(2.0, $montoReconstruido, 0.01, 'con 2 decimales esto daba 1.91, no 2 — el campo del vendedor "saltaba" al recargar');
    }

    // Guardrail (19-sep-2026) — columna HERMANA de la de arriba, mismo bug:
    // aplicarDescuentoGlobal() guarda el % efectivo completo (0.0944, no
    // redondo) en CADA alternativa_items.descuento_pct, no solo en
    // alternativas.descuento_global_pct. Si esa columna sigue en
    // decimal(5,2), un recálculo posterior que lea descuento_pct de vuelta
    // (ej. CotizacionController::recalcularItemsPorPersona(), que preserva
    // el descuento manual ya aplicado al reconstruir precio_convertido
    // desde precio de lista) reconstruye un precio distinto en centavos al
    // que el vendedor había dejado — el mismo drift, un nivel más abajo.
    public function test_descuento_pct_del_item_guarda_precision_suficiente_para_no_derivar_de_mas(): void
    {
        $alternativa = $this->crearAlternativa();
        AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => AlternativaItem::ORIGEN_MAYORISTA,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 2, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 800, 'precio_venta_snapshot' => 1059, 'precio_convertido' => 1059,
        ]);

        app(AlternativaController::class)->update(new Request(['descuento_global_monto' => 2]), (string) $alternativa->id);

        $item = $alternativa->items()->first();
        // Mismo cálculo que haría un recálculo posterior a partir del %
        // guardado — si descuento_pct quedó truncado a 0.09 (decimal(5,2)
        // viejo), esto da 1058.0469 en vez de 1058.00 (el precio_convertido
        // real que dejó aplicarDescuentoGlobal() con el % sin truncar).
        $precioReconstruido = round(1059 * (1 - (float) $item->descuento_pct / 100), 2);
        $this->assertEqualsWithDelta((float) $item->precio_convertido, $precioReconstruido, 0.01,
            'un recálculo posterior (ej. cambio de pasajeros) reconstruiría un precio distinto al que dejó el vendedor');
    }
}
