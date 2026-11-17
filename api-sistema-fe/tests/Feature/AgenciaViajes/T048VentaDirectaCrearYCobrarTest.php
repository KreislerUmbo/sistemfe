<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\VentaDirectaController;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\Cash\Branch;
use App\Models\Cash\CashMovement;
use App\Models\Cash\CashRegister;
use App\Models\Cash\CashSession;
use App\Models\Cash\PaymentMethod;
use App\Models\Sale\Sale;
use App\Models\Sale\SaleDetail;
use App\Models\Sale\SalePayment;
use App\Models\Sale\SerieComprobante;
use App\Models\TipoCambio\TipoCambioSunat;
use App\Models\User;
use App\Services\HoraPeru;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// T-048 — Venta directa: «Crear y cobrar» (la reserva, su comprobante y el cobro en
// una transacción) y la columna «Afectación» por línea (D-AGE-037). Mismo armado que
// T011CobroFacturarReservaTest: Postgres real, transacción por test, controllers directo.
class T048VentaDirectaCrearYCobrarTest extends TestCase
{
    use ConAccesoATodasLasReservas;

    private const PAX = [['edad' => 30], ['edad' => 40]];

    private User $usuario;

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
            'database.connections.central.database' => 'sistemafe_test_migrations',
        ]);
        DB::purge('pgsql');
        DB::purge('central');
        DB::beginTransaction();
        DB::connection('central')->beginTransaction();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $tenant = new \App\Models\Tenant();
        $tenant->id = 'test-tenant-'.uniqid();
        $tenant->facturacion_habilitada = true;
        app(\Stancl\Tenancy\Tenancy::class)->tenant = $tenant;

        config(['agencia.cobro_al_facturar' => true]);
        foreach ([['EFECTIVO', true], ['YAPE', false], ['TRANSFERENCIA', false]] as [$code, $efectivo]) {
            PaymentMethod::firstOrCreate(
                ['code' => $code],
                ['name' => ucfirst(strtolower($code)), 'is_active' => true, 'sort_order' => 1, 'affects_cash_count' => $efectivo]
            )->update(['monedas' => ['PEN', 'USD'], 'is_active' => true, 'affects_cash_count' => $efectivo]);
        }

        $this->usuario = $this->vendedor();
    }

    protected function tearDown(): void
    {
        app(\Stancl\Tenancy\Tenancy::class)->tenant = null;
        DB::rollBack();
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    // Sucursal con series 01/03 en soles, vendedor que crea, factura y emite, con su caja abierta.
    private function vendedor(array $permisos = ['reservas.crear', 'reservas.editar', 'emitir_factura', 'emitir_boleta'], bool $conCaja = true): User
    {
        $branch = Branch::create(['name' => 'Sede T048 '.uniqid(), 'is_active' => true]);
        foreach (['01' => 'F', '03' => 'B'] as $codigo => $letra) {
            SerieComprobante::create([
                'branch_id' => $branch->id, 'tipo_comprobante_codigo' => $codigo, 'moneda' => 'PEN',
                'serie' => $letra.'048', 'correlativo_actual' => 0, 'correlativo_inicial' => 1,
                'fecha_inicio' => HoraPeru::hoyTexto(), 'activo' => true,
            ]);
        }

        $rol = Role::create(['name' => 'rol-t048-'.uniqid(), 'guard_name' => 'api']);
        foreach ($permisos as $permiso) {
            $rol->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'api']));
        }
        $usuario = User::factory()->create(['branch_id' => $branch->id, 'role_id' => $rol->id]);
        $usuario->assignRole($rol);

        if ($conCaja) {
            $caja = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja T048', 'is_active' => true]);
            CashSession::create([
                'cash_register_id' => $caja->id, 'opened_by' => $usuario->id,
                'opening_amount' => 0, 'opened_at' => now(), 'status' => 'open',
            ]);
        }

        return $this->verTodasLasReservas($usuario->fresh());
    }

    private function cliente(bool $conRuc = false): int
    {
        return DB::table('clients')->insertGetId([
            'type_document' => $conRuc ? 'RUC' : 'DNI',
            'n_document' => $conRuc ? '20'.random_int(100000000, 999999999) : (string) random_int(10000000, 99999999),
            'full_name' => 'Cliente T048', 'type_client' => $conRuc ? 2 : 1, 'cod_tipo_doc_sunat' => $conRuc ? '6' : '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // Tarifa por unidad de $precio con su afectación (tarifa_fija × 1 = $precio).
    private function tarifa(float $precio, string $tip = '10', string $destino = 'nacional', string $servicio = 'Traslado'): int
    {
        $destinoId = DB::table('destinos_atractivos')->insertGetId(['nombre' => 'Tarapoto '.uniqid(), 'tipo' => 'lugar', 'created_at' => now(), 'updated_at' => now()]);
        $servicioId = DB::table('servicios')->insertGetId(['nombre' => $servicio, 'created_at' => now(), 'updated_at' => now()]);
        $destinoServicioId = DB::table('destino_servicio')->insertGetId(['destino_atractivo_id' => $destinoId, 'servicio_id' => $servicioId, 'created_at' => now(), 'updated_at' => now()]);
        $proveedorId = DB::table('proveedores')->insertGetId(['razon_social' => 'Proveedor '.$servicio, 'estado' => true, 'created_at' => now(), 'updated_at' => now()]);
        $proveedorServicioId = DB::table('proveedor_servicios')->insertGetId(['proveedor_id' => $proveedorId, 'destino_servicio_id' => $destinoServicioId, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('proveedor_tarifas')->insertGetId([
            'proveedor_servicio_id' => $proveedorServicioId, 'tipo_tarifa' => 'publica', 'modalidad' => 'privado', 'moneda' => 'PEN',
            'precio_costo' => $precio * 0.5, 'margen_tipo' => 'fijo', 'margen_valor' => $precio * 0.5,
            'precio_venta_adulto' => $precio, 'vigente_desde' => '2026-01-01',
            'tip_afe_igv' => $tip, 'destino_tributario' => $destino, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function linea(int $tarifaId, array $extra = []): array
    {
        return $extra + ['origen_tipo' => 'proveedor', 'dia' => 1, 'proveedor_tarifa_id' => $tarifaId, 'modo_precio' => 'tarifa_fija', 'cantidad' => 1];
    }

    private function crear(array $lineas, array $extra = [], ?int $clienteId = null): array
    {
        $respuesta = app(VentaDirectaController::class)->store(new Request($extra + [
            'cliente_id' => $clienteId ?? $this->cliente(),
            'destino' => 'Tarapoto',
            'fecha_servicio' => HoraPeru::hoyTexto(),
            'pax' => self::PAX,
            'lineas' => $lineas,
        ]));

        return ['status' => $respuesta->getStatusCode()] + $respuesta->getData(true);
    }

    private function cobrar(string $tipo, array $pagos, array $extra = []): array
    {
        return $extra + ['cobrar' => true, 'tipo_comprobante_codigo' => $tipo, 'pagos' => $pagos];
    }

    private function vistaPrevia(array $lineas): array
    {
        return app(VentaDirectaController::class)->vistaPrevia(new Request([
            'fecha_servicio' => HoraPeru::hoyTexto(), 'pax' => self::PAX, 'lineas' => $lineas,
        ]))->getData(true);
    }

    /** Lo que no tiene que quedar si el cobro falla. */
    private function conteos(): array
    {
        return [Cotizacion::withoutGlobalScopes()->count(), Reserva::count(), Sale::count(), SalePayment::count(), CashMovement::count()];
    }

    // CA1
    public function test_ca1_crear_y_cobrar_con_boleta_en_efectivo(): void
    {
        $body = $this->crear([$this->linea($this->tarifa(120))], $this->cobrar('03', [['method_payment' => 'EFECTIVO', 'monto_pagado' => 120]]));

        $this->assertSame(200, $body['status'], $body['message'] ?? '');
        $venta = Sale::findOrFail($body['sale_id']);
        $this->assertSame('03', $venta->tipo_comprobante_codigo);
        $this->assertSame('B048', $body['serie']);
        $this->assertSame(3, (int) $venta->state_payment);
        $this->assertEquals(0.0, (float) $venta->saldo_pendiente);
        $this->assertEquals(120.0, (float) $venta->total);
        $this->assertSame((int) $body['reserva']['alternativa']['cotizacion']['cliente_id'], (int) $venta->client_id);
        $this->assertSame(1, SalePayment::where('sale_id', $venta->id)->count());
        $this->assertSame(1, CashMovement::where('reference_type', 'sale')->where('reference_id', $venta->id)->count());
        $this->assertSame((int) $body['reserva']['id'], (int) DB::table('reserva_ventas')->where('sale_id', $venta->id)->value('reserva_id'));
    }

    // CA2
    public function test_ca2_transferencia_guarda_el_numero_de_operacion(): void
    {
        $body = $this->crear([$this->linea($this->tarifa(120))], $this->cobrar('03', [
            ['method_payment' => 'TRANSFERENCIA', 'monto_pagado' => 120, 'nro_operacion' => '00482915'],
        ]));

        $this->assertSame(200, $body['status'], $body['message'] ?? '');
        $this->assertSame('00482915', SalePayment::where('sale_id', $body['sale_id'])->value('nro_operacion'));
    }

    // CA3
    public function test_ca3_sin_tipo_de_comprobante_responde_422_y_no_crea_nada(): void
    {
        $antes = $this->conteos();

        $body = $this->crear([$this->linea($this->tarifa(120))], ['cobrar' => true, 'pagos' => [['method_payment' => 'EFECTIVO', 'monto_pagado' => 120]]]);

        $this->assertSame(422, $body['status']);
        $this->assertSame('Elige el comprobante: boleta o factura.', $body['message']);
        $this->assertSame($antes, $this->conteos());
    }

    // CA4
    public function test_ca4_lo_cobrado_no_cubre_el_total_y_no_existe_la_reserva(): void
    {
        $antes = $this->conteos();

        $body = $this->crear([$this->linea($this->tarifa(120))], $this->cobrar('03', [['method_payment' => 'EFECTIVO', 'monto_pagado' => 100]]));

        $this->assertSame(422, $body['status']);
        $this->assertSame($antes, $this->conteos());
    }

    // CA4
    public function test_ca4_sin_caja_abierta_responde_422_y_no_existe_la_reserva(): void
    {
        $this->usuario = $this->vendedor(conCaja: false);
        $antes = $this->conteos();

        $body = $this->crear([$this->linea($this->tarifa(120))], $this->cobrar('03', [['method_payment' => 'EFECTIVO', 'monto_pagado' => 120]]));

        $this->assertSame(422, $body['status']);
        $this->assertSame('Abre tu caja para cobrar.', $body['message']);
        $this->assertSame($antes, $this->conteos());
    }

    // CA5
    public function test_ca5_factura_a_un_cliente_sin_ruc_con_el_mensaje_de_facturar_una_reserva(): void
    {
        $antes = $this->conteos();

        $body = $this->crear([$this->linea($this->tarifa(120))], $this->cobrar('01', [['method_payment' => 'EFECTIVO', 'monto_pagado' => 120]]));

        $this->assertSame(422, $body['status']);
        $this->assertSame('No se puede emitir Factura a un cliente sin RUC. Selecciona Boleta o cambia el cliente.', $body['message']);
        $this->assertSame($antes, $this->conteos());
    }

    public function test_factura_a_un_cliente_con_ruc(): void
    {
        $body = $this->crear([$this->linea($this->tarifa(120))], $this->cobrar('01', [['method_payment' => 'EFECTIVO', 'monto_pagado' => 120]]), $this->cliente(conRuc: true));

        $this->assertSame(200, $body['status'], $body['message'] ?? '');
        $this->assertSame('F048', $body['serie']);
    }

    // CA6
    public function test_ca6_nota_de_venta_responde_422(): void
    {
        $antes = $this->conteos();

        $body = $this->crear([$this->linea($this->tarifa(120))], $this->cobrar('00', [['method_payment' => 'EFECTIVO', 'monto_pagado' => 120]]));

        $this->assertSame(422, $body['status']);
        $this->assertSame('El comprobante de una venta directa es boleta o factura.', $body['message']);
        $this->assertSame($antes, $this->conteos());
    }

    public function test_sin_permiso_de_emitir_boleta_responde_403_y_no_crea_nada(): void
    {
        $this->usuario = $this->vendedor(['reservas.crear', 'reservas.editar', 'emitir_factura']);
        $antes = $this->conteos();

        $body = $this->crear([$this->linea($this->tarifa(120))], $this->cobrar('03', [['method_payment' => 'EFECTIVO', 'monto_pagado' => 120]]));

        $this->assertSame(403, $body['status']);
        $this->assertSame($antes, $this->conteos());
    }

    public function test_sin_permiso_de_facturar_reservas_responde_403(): void
    {
        $this->usuario = $this->vendedor(['reservas.crear', 'emitir_boleta']);
        $antes = $this->conteos();

        $body = $this->crear([$this->linea($this->tarifa(120))], $this->cobrar('03', [['method_payment' => 'EFECTIVO', 'monto_pagado' => 120]]));

        $this->assertSame(403, $body['status']);
        $this->assertSame($antes, $this->conteos());
    }

    // Revisión de T-048: si ReservaPolicy niega facturar la reserva recién creada, 403 en el
    // formato {code, message} de esta API (no el render en inglés de Laravel) y no queda nada.
    public function test_si_la_policy_niega_facturar_responde_403_en_espanol_y_no_crea_nada(): void
    {
        \Illuminate\Support\Facades\Gate::before(fn ($usuario, string $habilidad, array $args) =>
            $habilidad === 'update' && ($args[0] ?? null) instanceof Reserva ? false : null);
        $antes = $this->conteos();

        $body = $this->crear([$this->linea($this->tarifa(120))], $this->cobrar('03', [['method_payment' => 'EFECTIVO', 'monto_pagado' => 120]]));

        $this->assertSame(403, $body['status']);
        $this->assertStringContainsString('No tienes permiso para facturar esta reserva', $body['message']);
        $this->assertSame($antes, $this->conteos());
    }

    // CA7
    public function test_ca7_linea_de_la_amazonia_sin_confirmar_sale_exonerada_con_leyenda(): void
    {
        $body = $this->crear([$this->linea($this->tarifa(300, '20', 'amazonia', 'Hotel Amazonía'))], $this->cobrar('03', [['method_payment' => 'EFECTIVO', 'monto_pagado' => 300]]));

        $this->assertSame(200, $body['status'], $body['message'] ?? '');
        $venta = Sale::findOrFail($body['sale_id']);
        $this->assertEquals(300.0, (float) $venta->mto_oper_exoneradas);
        $this->assertEquals(0.0, (float) $venta->igv);
        $this->assertSame('amazonia', $venta->destino);
        $this->assertSame(['20'], SaleDetail::where('sale_id', $venta->id)->pluck('tip_afe_igv')->unique()->values()->all());
    }

    // CA8
    public function test_ca8_exonerada_y_gravada_van_en_un_comprobante(): void
    {
        $lineas = [$this->linea($this->tarifa(300, '20', 'amazonia', 'Hotel Amazonía')), $this->linea($this->tarifa(118))];

        $previa = $this->vistaPrevia($lineas);
        $this->assertEquals(['gravadas' => 100.0, 'exoneradas' => 300.0, 'inafectas' => 0.0, 'igv' => 18.0, 'total' => 418.0], $previa['resumen']);

        $body = $this->crear($lineas, $this->cobrar('03', [['method_payment' => 'EFECTIVO', 'monto_pagado' => 418]]));

        $this->assertSame(200, $body['status'], $body['message'] ?? '');
        $this->assertSame(1, DB::table('reserva_ventas')->where('reserva_id', $body['reserva']['id'])->count());
        $venta = Sale::findOrFail($body['sale_id']);
        $this->assertEquals(300.0, (float) $venta->mto_oper_exoneradas);
        $this->assertEquals(100.0, (float) $venta->mto_oper_gravadas);
        $this->assertEquals(18.0, (float) $venta->igv);
        $this->assertEquals(418.0, (float) $venta->total);
        $this->assertEquals(418.0, (float) SalePayment::where('sale_id', $venta->id)->sum('amount'));
    }

    // CA8b
    public function test_ca8b_exportacion_no_se_cobra_pero_se_crea_sin_cobrar(): void
    {
        $lineas = [$this->linea($this->tarifa(200, '10', 'extranjero', 'Tour exportación'))];
        $motivo = $this->vistaPrevia($lineas)['facturable']['motivo'];
        $this->assertNotEmpty($motivo);
        $antes = $this->conteos();

        $body = $this->crear($lineas, $this->cobrar('03', [['method_payment' => 'EFECTIVO', 'monto_pagado' => 200]]));

        $this->assertSame(422, $body['status']);
        $this->assertSame($motivo, $body['message']);
        $this->assertSame($antes, $this->conteos());

        $sinCobrar = $this->crear($lineas);
        $this->assertSame(200, $sinCobrar['status'], $sinCobrar['message'] ?? '');
        $this->assertNull($sinCobrar['sale_id']);
    }

    // CA9
    public function test_ca9_con_el_cobro_apagado_responde_422_y_la_vista_previa_lo_dice(): void
    {
        config(['agencia.cobro_al_facturar' => false]);
        $lineas = [$this->linea($this->tarifa(120))];
        $antes = $this->conteos();

        $body = $this->crear($lineas, $this->cobrar('03', [['method_payment' => 'EFECTIVO', 'monto_pagado' => 120]]));

        $this->assertSame(422, $body['status']);
        $this->assertSame($antes, $this->conteos());
        $this->assertFalse($this->vistaPrevia($lineas)['cobro']['cobro_habilitado']);
    }

    public function test_la_vista_previa_trae_el_bloque_de_cobro(): void
    {
        $cobro = $this->vistaPrevia([$this->linea($this->tarifa(120))])['cobro'];

        $this->assertTrue($cobro['cobro_habilitado']);
        $this->assertTrue($cobro['caja_abierta']);
        $this->assertContains('YAPE', array_column($cobro['formas_pago'], 'code'));
    }

    // Revisión de T-048: la columna es decimal (Eloquent la da como «3.8500»); el bloque de cobro
    // la espera como número, en Venta directa y en los modales de facturar una reserva.
    public function test_el_tipo_de_cambio_del_bloque_de_cobro_llega_como_numero(): void
    {
        TipoCambioSunat::create(['fecha' => HoraPeru::hoyTexto(), 'compra' => 3.8, 'venta' => 3.85, 'fuente' => 'test', 'consultado_en' => now()]);

        $tc = $this->vistaPrevia([$this->linea($this->tarifa(120))])['cobro']['tipo_cambio_dia'];

        $this->assertIsFloat($tc);
        $this->assertSame(3.85, $tc);
    }

    // CA12
    public function test_ca12_traslado_pasado_a_inafecto_en_la_vista_previa_y_al_crear(): void
    {
        $traslado = $this->tarifa(118);
        $hotel = $this->tarifa(300, '20', 'amazonia', 'Hotel Amazonía');

        $previa = $this->vistaPrevia([$this->linea($traslado, ['tip_afe_igv' => '30']), $this->linea($hotel)]);
        $this->assertSame('30', $previa['lineas'][0]['tip_afe_igv']);
        $this->assertSame('nacional', $previa['lineas'][0]['destino_tributario']);
        $this->assertTrue($previa['lineas'][0]['cambiado']);
        $this->assertTrue($previa['lineas'][0]['editable']);
        $this->assertFalse($previa['lineas'][1]['cambiado']);
        $this->assertEquals(['gravadas' => 0.0, 'exoneradas' => 300.0, 'inafectas' => 118.0, 'igv' => 0.0, 'total' => 418.0], $previa['resumen']);

        $body = $this->crear(
            [$this->linea($traslado, ['tip_afe_igv' => '30']), $this->linea($hotel)],
            $this->cobrar('03', [['method_payment' => 'EFECTIVO', 'monto_pagado' => 418]])
        );

        $this->assertSame(200, $body['status'], $body['message'] ?? '');
        $items = ReservaItem::with('alternativaItem')->where('reserva_id', $body['reserva']['id'])->get()
            ->keyBy(fn (ReservaItem $i) => (int) $i->alternativaItem->proveedor_tarifa_id);
        $this->assertSame('30', $items[$traslado]->tip_afe_igv);
        $this->assertSame('nacional', $items[$traslado]->destino_tributario);
        $this->assertNotNull($items[$traslado]->fecha_override_tributario);
        $this->assertNull($items[$hotel]->fecha_override_tributario);
        $venta = Sale::findOrFail($body['sale_id']);
        $this->assertEquals(118.0, (float) $venta->mto_oper_inafectas);
        $this->assertEquals(300.0, (float) $venta->mto_oper_exoneradas);
        $this->assertEquals(0.0, (float) $venta->igv);
    }

    public function test_ca12_elegir_la_misma_afectacion_no_cuenta_como_cambio(): void
    {
        $traslado = $this->tarifa(118);

        $previa = $this->vistaPrevia([$this->linea($traslado, ['tip_afe_igv' => '10'])]);
        $this->assertFalse($previa['lineas'][0]['cambiado']);

        $body = $this->crear([$this->linea($traslado, ['tip_afe_igv' => '10'])]);
        $this->assertSame(200, $body['status'], $body['message'] ?? '');
        $this->assertNull(ReservaItem::where('reserva_id', $body['reserva']['id'])->value('fecha_override_tributario'));
    }

    // CA12: «Crear sin cobrar» también guarda la afectación elegida.
    public function test_crear_sin_cobrar_guarda_la_afectacion_elegida(): void
    {
        $body = $this->crear([$this->linea($this->tarifa(118), ['tip_afe_igv' => '20'])]);

        $this->assertSame(200, $body['status'], $body['message'] ?? '');
        $item = ReservaItem::where('reserva_id', $body['reserva']['id'])->firstOrFail();
        $this->assertSame('20', $item->tip_afe_igv);
        $this->assertSame('amazonia', $item->destino_tributario);
        $this->assertNotNull($item->fecha_override_tributario);
    }

    public function test_concepto_libre_tambien_se_corrige(): void
    {
        $libre = ['origen_tipo' => 'manual', 'dia' => 1, 'descripcion_manual' => 'Entrada', 'precio_venta_snapshot' => 30, 'costo_snapshot' => 0, 'cantidad' => 1, 'tip_afe_igv' => '30'];

        $previa = $this->vistaPrevia([$libre]);

        $this->assertSame('30', $previa['lineas'][0]['tip_afe_igv']);
        $this->assertEquals(30.0, $previa['resumen']['inafectas']);
    }

    public function test_tip_afe_igv_invalido_responde_422(): void
    {
        $respuesta = app(VentaDirectaController::class)->vistaPrevia(new Request([
            'fecha_servicio' => HoraPeru::hoyTexto(), 'pax' => self::PAX, 'lineas' => [$this->linea($this->tarifa(118), ['tip_afe_igv' => '40'])],
        ]));

        $this->assertSame(422, $respuesta->getStatusCode());
    }
}
