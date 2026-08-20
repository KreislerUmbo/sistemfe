<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaFacturacionController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\AgenciaViajes\ReservaVenta;
use App\Models\AgenciaViajes\SaleDetailItem;
use App\Models\Cash\Branch;
use App\Models\Client\Client;
use App\Models\Sale\Sale;
use App\Models\Sale\SerieComprobante;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// Fase A del plan "Proceso de reserva: facturación + 3 fixes" (2026-08-19)
// — cierra el gap real de que ninguna reserva podía convertirse en un Sale
// real. Extendido 2026-08-20 con el guardia tributario y con facturación
// múltiple por grupo de pasajeros (PEGAR-EN-CLAUDE-CODE-facturacion-
// multiple-pasajeros.md): N Sales por reserva, cada uno con su propio
// client_id/texto_personalizado, cubriendo un subconjunto de pasajeros —
// ya no un único responsable de pago fijo a cotizacion.cliente_id. Mismo
// patrón de fixture de serie/permiso que SaleControllerSerieComprobanteTest,
// Postgres real (sistemafe_test_migrations), transacción por test revertida.
class ReservaFacturacionTest extends TestCase
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
            'database.connections.central.database' => 'sistemafe_test_migrations',
        ]);
        DB::purge('pgsql');
        DB::purge('central');
        DB::beginTransaction();
        DB::connection('central')->beginTransaction();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // users.role_id default(1) a nivel de Postgres — mismo fixture que
        // el resto de la suite (ver SaleControllerSerieComprobanteTest).
        DB::table('roles')->insert([
            'id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");

        // Facturación externa por tenant (PEGAR-EN-CLAUDE-CODE-facturacion-
        // externa-tenant.md) — default true para no romper los ~18 tests
        // existentes de este archivo (todos asumían implícitamente que
        // facturar estaba habilitado). Los tests nuevos del guard llaman
        // setUpTenantFixture(false)/(null) explícitamente.
        $this->setUpTenantFixture(true);
    }

    protected function tearDown(): void
    {
        app(\Stancl\Tenancy\Tenancy::class)->tenant = null;
        DB::rollBack();
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    // Los tests de este archivo llaman a los controllers directamente
    // (nunca vía HTTP + middleware `tenant`), así que stancl/tenancy nunca
    // se inicializa por su cuenta. Para poder gatear
    // ReservaFacturacionController con tenant('facturacion_habilitada') sin
    // disparar Tenancy::initialize() (que correría DatabaseTenancyBootstrapper
    // e intentaría cambiar de conexión física, rompiendo el fixture de
    // Postgres de este test), se asigna el tenant directamente sobre la
    // property de Tenancy — el binding de Contracts\Tenant::class (ver
    // vendor/stancl/tenancy/src/TenancyServiceProvider.php) lo resuelve
    // dinámicamente desde ahí en cada llamada a tenant().
    private function setUpTenantFixture(?bool $facturacionHabilitada): void
    {
        $tenant = new \App\Models\Tenant();
        $tenant->id = 'test-tenant-' . uniqid();
        $tenant->facturacion_habilitada = $facturacionHabilitada;

        app(\Stancl\Tenancy\Tenancy::class)->tenant = $tenant;
    }

    private function usuarioConPermisos(int $branchId, array $permisos): User
    {
        $user = User::factory()->create(['branch_id' => $branchId]);

        $role = Role::create(['name' => 'rol-test-' . uniqid(), 'guard_name' => 'api']);
        foreach ($permisos as $permisoNombre) {
            $permission = Permission::firstOrCreate(['name' => $permisoNombre, 'guard_name' => 'api']);
            $role->givePermissionTo($permission);
        }
        $user->assignRole($role);
        $user->role_id = $role->id;
        $user->save();

        Auth::guard('api')->setUser($user->fresh());

        return $user->fresh();
    }

    private function branchConSerie(string $codigo, string $serieTexto): array
    {
        $branch = Branch::create(['name' => 'Sede Test Facturación', 'is_active' => true]);
        $serie = SerieComprobante::create([
            'branch_id' => $branch->id,
            'tipo_comprobante_codigo' => $codigo,
            'moneda' => 'PEN',
            'serie' => $serieTexto,
            'correlativo_actual' => 0,
            'correlativo_inicial' => 1,
            'fecha_inicio' => now()->format('Y-m-d'),
            'activo' => true,
        ]);

        return [$branch, $serie];
    }

    // Agrega una serie adicional a una sucursal YA creada por
    // branchConSerie() — necesario cuando un mismo usuario/sucursal debe
    // poder emitir más de un tipo de comprobante (ej. boleta a un
    // pasajero y factura a otro, ambas desde la misma sucursal).
    private function serieAdicional(Branch $branch, string $codigo, string $serieTexto): SerieComprobante
    {
        return SerieComprobante::create([
            'branch_id' => $branch->id,
            'tipo_comprobante_codigo' => $codigo,
            'moneda' => 'PEN',
            'serie' => $serieTexto,
            'correlativo_actual' => 0,
            'correlativo_inicial' => 1,
            'fecha_inicio' => now()->format('Y-m-d'),
            'activo' => true,
        ]);
    }

    /**
     * Reserva con 3 pasajeros y 5 ítems, pensada para cubrir los 3 casos
     * reales del guard de selección por pasajero:
     * - $itemP1 (proveedor, HOTEL vía tipo_habitacion): vinculado SOLO a P1.
     * - $itemP2 (proveedor, TRANSPORTE): vinculado SOLO a P2.
     * - $itemP3 (proveedor, TRANSPORTE): vinculado SOLO a P3.
     * - $itemCompartidoP1P2 (proveedor, HOTEL — "habitación doble"):
     *   vinculado a P1 Y P2 a la vez — nunca debe fragmentarse entre 2 Sales.
     * - $itemSinAsignar (manual, "Ajuste de redondeo"): sin ninguna fila en
     *   reserva_item_pasajero — el caso más común con datos reales de hoy.
     * Todas las tarifas con destino_tributario='nacional' (caso homogéneo
     * por defecto; los tests de mezcla tributaria arman su propia fixture).
     */
    private function crearReservaConPasajerosEItems(): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '90011223', 'full_name' => 'Cliente Test Multipago',
            'type_client' => 1, 'cod_tipo_doc_sunat' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0500-' . uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);

        $cp1 = DB::table('cotizacion_pasajeros')->insertGetId([
            'cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 30,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cp2 = DB::table('cotizacion_pasajeros')->insertGetId([
            'cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 28,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cp3 = DB::table('cotizacion_pasajeros')->insertGetId([
            'cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 35,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $destinoAtractivoId = DB::table('destinos_atractivos')->insertGetId([
            'nombre' => 'Tarapoto', 'tipo' => 'lugar', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $servicioHotelId = DB::table('servicios')->insertGetId([
            'nombre' => 'Hospedaje', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $destinoServicioHotelId = DB::table('destino_servicio')->insertGetId([
            'destino_atractivo_id' => $destinoAtractivoId, 'servicio_id' => $servicioHotelId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $servicioTransporteId = DB::table('servicios')->insertGetId([
            'nombre' => 'Traslado Ida y Vuelta', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $destinoServicioTransporteId = DB::table('destino_servicio')->insertGetId([
            'destino_atractivo_id' => $destinoAtractivoId, 'servicio_id' => $servicioTransporteId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorId = DB::table('proveedores')->insertGetId([
            'razon_social' => 'Operador Test Multipago SAC', 'estado' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorServicioHotelId = DB::table('proveedor_servicios')->insertGetId([
            'proveedor_id' => $proveedorId, 'destino_servicio_id' => $destinoServicioHotelId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorServicioTransporteId = DB::table('proveedor_servicios')->insertGetId([
            'proveedor_id' => $proveedorId, 'destino_servicio_id' => $destinoServicioTransporteId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $tarifaHotelP1Id = DB::table('proveedor_tarifas')->insertGetId([
            'proveedor_servicio_id' => $proveedorServicioHotelId,
            'tipo_tarifa' => 'publica', 'modalidad' => 'privado', 'moneda' => 'PEN',
            'precio_costo' => 80, 'margen_tipo' => 'fijo', 'margen_valor' => 20,
            'precio_venta_adulto' => 100, 'tipo_habitacion' => 'simple',
            'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tarifaTransporteId = DB::table('proveedor_tarifas')->insertGetId([
            'proveedor_servicio_id' => $proveedorServicioTransporteId,
            'tipo_tarifa' => 'publica', 'modalidad' => 'compartido', 'moneda' => 'PEN',
            'precio_costo' => 30, 'margen_tipo' => 'fijo', 'margen_valor' => 10,
            'precio_venta_adulto' => 40,
            'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tarifaHotelDobleId = DB::table('proveedor_tarifas')->insertGetId([
            'proveedor_servicio_id' => $proveedorServicioHotelId,
            'tipo_tarifa' => 'publica', 'modalidad' => 'privado', 'moneda' => 'PEN',
            'precio_costo' => 150, 'margen_tipo' => 'fijo', 'margen_valor' => 30,
            'precio_venta_adulto' => 180, 'tipo_habitacion' => 'doble',
            'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $itemP1 = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor',
            'proveedor_tarifa_id' => $tarifaHotelP1Id, 'dia_referencial' => 1, 'pax_incluidos' => [$cp1],
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 80, 'precio_venta_snapshot' => 100, 'precio_convertido' => 100,
        ]);
        $itemP2 = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor',
            'proveedor_tarifa_id' => $tarifaTransporteId, 'dia_referencial' => 1, 'pax_incluidos' => [$cp2],
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 30, 'precio_venta_snapshot' => 40, 'precio_convertido' => 40,
        ]);
        $itemP3 = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor',
            'proveedor_tarifa_id' => $tarifaTransporteId, 'dia_referencial' => 1, 'pax_incluidos' => [$cp3],
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 30, 'precio_venta_snapshot' => 40, 'precio_convertido' => 40,
        ]);
        $itemCompartido = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor',
            'proveedor_tarifa_id' => $tarifaHotelDobleId, 'dia_referencial' => 1, 'pax_incluidos' => [$cp1, $cp2],
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 150, 'precio_venta_snapshot' => 180, 'precio_convertido' => 180,
        ]);
        $itemSinAsignar = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 1,
            'descripcion_manual' => 'Ajuste de redondeo', 'modo_precio' => 'tarifa_fija', 'cantidad' => 1,
            'moneda_costo' => 'PEN', 'costo_snapshot' => 5, 'precio_venta_snapshot' => 10, 'precio_convertido' => 10,
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        $pasajeros = ReservaPasajero::where('reserva_id', $reserva->id)->orderBy('id')->get();

        return [
            'reserva' => $reserva,
            'p1' => $pasajeros[0],
            'p2' => $pasajeros[1],
            'p3' => $pasajeros[2],
            'itemP1' => ReservaItem::where('alternativa_item_id', $itemP1->id)->first(),
            'itemP2' => ReservaItem::where('alternativa_item_id', $itemP2->id)->first(),
            'itemP3' => ReservaItem::where('alternativa_item_id', $itemP3->id)->first(),
            'itemCompartido' => ReservaItem::where('alternativa_item_id', $itemCompartido->id)->first(),
            'itemSinAsignar' => ReservaItem::where('alternativa_item_id', $itemSinAsignar->id)->first(),
        ];
    }

    public function test_factura_tres_pasajeros_en_tres_sales_distintos_con_texto_personalizado(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->serieAdicional($branch, '03', 'B001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura', 'emitir_boleta']);

        $f = $this->crearReservaConPasajerosEItems();
        $reserva = $f['reserva'];
        $empresaA = Client::factory()->create(['type_client' => 2, 'cod_tipo_doc_sunat' => '6']);
        $empresaB = Client::factory()->create(['type_client' => 2, 'cod_tipo_doc_sunat' => '6']);
        $clientePasajero = Client::factory()->create(['type_client' => 1, 'cod_tipo_doc_sunat' => '1']);

        // P3 → boleta a su propio nombre.
        $r1 = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$f['p3']->id],
            'client_id' => $clientePasajero->id,
            'tipo_comprobante_codigo' => '03',
            'texto_personalizado' => 'Servicio de movilización — comisión de servicio',
        ]), (string) $reserva->id);
        $body1 = $r1->getData(true);
        $this->assertSame(200, $body1['code'], json_encode($body1));

        // P1 → factura a empresa A (su ítem individual — el compartido con
        // P2 queda pendiente, ver §itemCompartido).
        $r2 = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$f['p1']->id],
            'client_id' => $empresaA->id,
            'tipo_comprobante_codigo' => '01',
            'texto_personalizado' => 'Sustento de gastos — Empresa A',
        ]), (string) $reserva->id);
        $body2 = $r2->getData(true);
        $this->assertSame(200, $body2['code'], json_encode($body2));

        // P2 → factura a empresa B (su ítem individual).
        $r3 = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$f['p2']->id],
            'client_id' => $empresaB->id,
            'tipo_comprobante_codigo' => '01',
            'texto_personalizado' => 'Sustento de gastos — Empresa B',
        ]), (string) $reserva->id);
        $body3 = $r3->getData(true);
        $this->assertSame(200, $body3['code'], json_encode($body3));

        $this->assertSame(3, Sale::count());
        $this->assertSame(3, ReservaVenta::where('reserva_id', $reserva->id)->count());

        $ventaP3 = Sale::find($body1['sale_id']);
        $this->assertSame($clientePasajero->id, $ventaP3->client_id);
        $this->assertSame('03', $ventaP3->tipo_comprobante_codigo);
        $this->assertSame(40.0, (float) $ventaP3->total);
        $this->assertStringContainsString('Servicio de movilización', $ventaP3->sale_details()->first()->descripcion_detalle);

        $ventaP1 = Sale::find($body2['sale_id']);
        $this->assertSame($empresaA->id, $ventaP1->client_id);
        $this->assertSame(100.0, (float) $ventaP1->total, 'solo su ítem individual, el compartido queda pendiente');
        $this->assertStringContainsString('Empresa A', $ventaP1->sale_details()->first()->descripcion_detalle);

        $ventaP2 = Sale::find($body3['sale_id']);
        $this->assertSame($empresaB->id, $ventaP2->client_id);
        $this->assertSame(40.0, (float) $ventaP2->total);

        // Ningún reserva_item duplicado entre las 3 ReservaVenta.
        $todosLosItemsFacturados = ReservaVenta::where('reserva_id', $reserva->id)->get()
            ->flatMap(fn (ReservaVenta $rv) => $rv->reserva_item_ids)->all();
        $this->assertCount(3, $todosLosItemsFacturados, 'itemP1 + itemP2 + itemP3, el compartido y el sin-asignar no entraron');
        $this->assertSame(array_unique($todosLosItemsFacturados), $todosLosItemsFacturados, 'sin duplicados');

        $todosLosPasajerosFacturados = ReservaVenta::where('reserva_id', $reserva->id)->get()
            ->flatMap(fn (ReservaVenta $rv) => $rv->reserva_pasajero_ids)->all();
        $this->assertEqualsCanonicalizing([$f['p1']->id, $f['p2']->id, $f['p3']->id], $todosLosPasajerosFacturados);
    }

    public function test_item_compartido_se_factura_junto_cuando_se_seleccionan_ambos_pasajeros(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        $f = $this->crearReservaConPasajerosEItems();
        $cliente = Client::factory()->create();

        $response = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$f['p1']->id, $f['p2']->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $f['reserva']->id);

        $body = $response->getData(true);
        $this->assertSame(200, $body['code'], json_encode($body));
        // itemP1 (100) + itemP2 (40) + itemCompartido (180) = 320, todos
        // hotel/transporte agrupados en 2 líneas (HOTEL, TRANSPORTE).
        $this->assertSame(320.0, (float) Sale::find($body['sale_id'])->total);

        $reservaVenta = ReservaVenta::where('reserva_id', $f['reserva']->id)->first();
        $this->assertEqualsCanonicalizing(
            [$f['itemP1']->id, $f['itemP2']->id, $f['itemCompartido']->id],
            $reservaVenta->reserva_item_ids
        );
    }

    public function test_item_compartido_queda_pendiente_si_falta_uno_de_sus_pasajeros(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        $f = $this->crearReservaConPasajerosEItems();

        $preview = app(ReservaFacturacionController::class)->prepararFactura(new Request([
            'pasajero_ids' => [$f['p1']->id],
        ]), (string) $f['reserva']->id);

        $body = $preview->getData(true);
        $this->assertSame(200, $preview->getStatusCode());
        $this->assertFalse($body['bloqueado_tributario']);
        // Solo itemP1 (100) — el compartido no entra porque falta P2.
        $this->assertSame(100.0, (float) $body['total']);

        $this->assertCount(1, $body['items_pendientes_por_pasajero_faltante']);
        $this->assertSame($f['itemCompartido']->id, $body['items_pendientes_por_pasajero_faltante'][0]['reserva_item_id']);
        $this->assertSame([$f['p2']->id], $body['items_pendientes_por_pasajero_faltante'][0]['pasajeros_faltantes']);
    }

    // Regresión real encontrada probando contra agencia-demo (2026-08-20):
    // seleccionar pasajeros sin NINGÚN ítem auto-incluible (el caso más
    // común hoy — casi ningún dato real tiene reserva_item_pasajero
    // poblado, ver docblock de la clase) hacía que prepararFactura()
    // devolviera 422 apenas se abría el modal, antes de que el vendedor
    // tuviera chance de marcar algo del pool "sin asignar". El preview
    // debe devolver 200 con 0 líneas, no fallar — el 422 de "selección
    // vacía" es exclusivo de store() (ahí sí no tiene sentido crear un
    // Sale sin ninguna línea).
    public function test_preparar_factura_sin_items_auto_incluidos_devuelve_200_no_422(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        // Reserva con 2 pasajeros y 1 ítem, pero SIN pax_incluidos en el
        // alternativa_item — mismo patrón que casi todos los reserva_items
        // reales de agencia-demo (ítem "sin asignar" desde el vamos).
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '55667788', 'full_name' => 'Cliente Test Sin Vinculacion',
            'type_client' => 1, 'cod_tipo_doc_sunat' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0600-' . uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
        DB::table('cotizacion_pasajeros')->insert([
            ['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 30, 'created_at' => now(), 'updated_at' => now()],
            ['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 28, 'created_at' => now(), 'updated_at' => now()],
        ]);
        AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 1,
            'descripcion_manual' => 'Ajuste de redondeo', 'modo_precio' => 'tarifa_fija', 'cantidad' => 1,
            'moneda_costo' => 'PEN', 'costo_snapshot' => 5, 'precio_venta_snapshot' => 10, 'precio_convertido' => 10,
            // pax_incluidos deliberadamente ausente (null).
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());
        $pasajeros = ReservaPasajero::where('reserva_id', $reserva->id)->orderBy('id')->get();

        $preview = app(ReservaFacturacionController::class)->prepararFactura(new Request([
            'pasajero_ids' => $pasajeros->pluck('id')->all(),
        ]), (string) $reserva->id);

        $body = $preview->getData(true);
        $this->assertSame(200, $preview->getStatusCode(), 'debe ser 200 informativo, no 422 — el vendedor recién va a elegir del pool sin asignar');
        $this->assertFalse($body['bloqueado_tributario']);
        $this->assertSame(0.0, (float) $body['total']);
        $this->assertCount(1, $body['items_sin_asignar_disponibles'], 'el único ítem, sin vincular, disponible para elegir a mano');
    }

    public function test_items_sin_asignar_requieren_seleccion_explicita_y_no_se_incluyen_solos(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        $f = $this->crearReservaConPasajerosEItems();
        $cliente = Client::factory()->create();

        // Sin reserva_item_ids_manual: el ítem sin asignar NO se cuela.
        $preview = app(ReservaFacturacionController::class)->prepararFactura(new Request([
            'pasajero_ids' => [$f['p3']->id],
        ]), (string) $f['reserva']->id);
        $body = $preview->getData(true);
        $this->assertSame(40.0, (float) $body['total'], 'solo itemP3, sin el ajuste de redondeo');
        $this->assertCount(1, $body['items_sin_asignar_disponibles']);
        $this->assertSame($f['itemSinAsignar']->id, $body['items_sin_asignar_disponibles'][0]['reserva_item_id']);

        // Con reserva_item_ids_manual: ahora sí se incluye, sumado al total.
        $response = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$f['p3']->id],
            'reserva_item_ids_manual' => [$f['itemSinAsignar']->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $f['reserva']->id);
        $bodyStore = $response->getData(true);
        $this->assertSame(200, $bodyStore['code'], json_encode($bodyStore));
        $this->assertSame(50.0, (float) Sale::find($bodyStore['sale_id'])->total, '40 (itemP3) + 10 (ajuste)');
    }

    public function test_rechaza_reserva_item_ids_manual_que_tiene_pasajero_vinculado(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        $f = $this->crearReservaConPasajerosEItems();
        $cliente = Client::factory()->create();

        // itemP1 SÍ tiene pasajero vinculado — no puede colarse por el
        // canal "manual" (reservado para ítems sin asignar).
        $response = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$f['p3']->id],
            'reserva_item_ids_manual' => [$f['itemP1']->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $f['reserva']->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, Sale::count());
    }

    public function test_rechaza_refacturar_pasajero_ya_facturado(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        $f = $this->crearReservaConPasajerosEItems();
        $cliente = Client::factory()->create();

        app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$f['p3']->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $f['reserva']->id);
        $this->assertSame(1, Sale::count());

        $response = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$f['p3']->id, $f['p2']->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $f['reserva']->id);

        $body = $response->getData(true);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString((string) $f['p3']->id, $body['message']);
        $this->assertSame(1, Sale::count(), 'no debe crear nada a medias');
    }

    public function test_rechaza_pasajero_que_no_pertenece_a_la_reserva(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        $fA = $this->crearReservaConPasajerosEItems();
        $fB = $this->crearReservaConPasajerosEItems();
        $cliente = Client::factory()->create();

        $response = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$fA['p1']->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $fB['reserva']->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, Sale::count());
    }

    public function test_rechaza_sin_client_id(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        $f = $this->crearReservaConPasajerosEItems();

        $response = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$f['p3']->id],
            'tipo_comprobante_codigo' => '01',
        ]), (string) $f['reserva']->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, Sale::count());
    }

    public function test_rechaza_reserva_no_activa(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        $f = $this->crearReservaConPasajerosEItems();
        $f['reserva']->update(['estado' => 'cancelada']);
        $cliente = Client::factory()->create();

        $response = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$f['p3']->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $f['reserva']->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Solo se puede facturar una reserva activa.', $response->getData(true)['message']);
        $this->assertSame(0, Sale::count());
    }

    public function test_rechaza_sin_permiso_de_emision(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, []); // sin ningún permiso de emisión

        $f = $this->crearReservaConPasajerosEItems();
        $cliente = Client::factory()->create();

        try {
            app(ReservaFacturacionController::class)->store(new Request([
                'pasajero_ids' => [$f['p3']->id],
                'client_id' => $cliente->id,
                'tipo_comprobante_codigo' => '01',
            ]), (string) $f['reserva']->id);
            $this->fail('Se esperaba HttpException 403, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, Sale::count());
    }

    public function test_bloquea_facturar_reserva_con_destino_tributario_mezclado(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        [$reserva, $itemAmazonia, $itemNacional, $pasajeroA, $pasajeroB] = $this->crearReservaConMezclaTributaria();
        $cliente = Client::factory()->create();

        // Guardia real: server-side en POST facturar, sin pasar por
        // prepararFactura() — nunca confía en que el frontend ya filtró.
        $response = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$pasajeroA->id, $pasajeroB->id],
            'reserva_item_ids_manual' => [], // ambos ítems tienen pasajero vinculado
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $reserva->id);

        $body = $response->getData(true);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertTrue($body['bloqueado_tributario']);
        $this->assertEqualsCanonicalizing(['amazonia', 'nacional'], $body['destinos_tributarios_detectados']);
        $this->assertSame(0, Sale::count(), 'no debe crear nada a medias');

        // Preview: mismo bloqueo, sin necesidad de intentar el POST.
        $preview = app(ReservaFacturacionController::class)->prepararFactura(new Request([
            'pasajero_ids' => [$pasajeroA->id, $pasajeroB->id],
        ]), (string) $reserva->id);
        $previewBody = $preview->getData(true);
        $this->assertSame(200, $preview->getStatusCode());
        $this->assertTrue($previewBody['bloqueado_tributario']);
        $this->assertArrayNotHasKey('grupos_propuestos', $previewBody);
    }

    public function test_guardia_tributario_es_por_subgrupo_no_por_reserva_completa(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        [$reserva, $itemAmazonia, $itemNacional, $pasajeroA, $pasajeroB] = $this->crearReservaConMezclaTributaria();
        $cliente = Client::factory()->create();

        // Facturar solo a pasajeroA (su ítem 'amazonia' solo) — no hay
        // mezcla dentro de ESTE Sale, debe pasar sin problema aunque la
        // reserva completa sí mezcle tratamientos entre sus pasajeros.
        $response = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$pasajeroA->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $reserva->id);

        $body = $response->getData(true);
        $this->assertSame(200, $body['code'], json_encode($body));
        $this->assertSame(1, Sale::count());
    }

    public function test_preparar_factura_lanza_403_si_tenant_no_tiene_facturacion_habilitada(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);
        $this->setUpTenantFixture(false);

        $f = $this->crearReservaConPasajerosEItems();

        try {
            app(ReservaFacturacionController::class)->prepararFactura(new Request([
                'pasajero_ids' => [$f['p3']->id],
            ]), (string) $f['reserva']->id);
            $this->fail('Se esperaba HttpException 403, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_store_lanza_403_si_tenant_no_tiene_facturacion_habilitada(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);
        $this->setUpTenantFixture(false);

        $f = $this->crearReservaConPasajerosEItems();
        $cliente = Client::factory()->create();

        try {
            app(ReservaFacturacionController::class)->store(new Request([
                'pasajero_ids' => [$f['p3']->id],
                'client_id' => $cliente->id,
                'tipo_comprobante_codigo' => '01',
            ]), (string) $f['reserva']->id);
            $this->fail('Se esperaba HttpException 403, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, Sale::count());
    }

    // NULL (tenant sin decidir todavía, ver backfill) debe tratarse igual
    // de falsy que false — no un tercer estado permisivo.
    public function test_store_lanza_403_si_facturacion_habilitada_es_null(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);
        $this->setUpTenantFixture(null);

        $f = $this->crearReservaConPasajerosEItems();
        $cliente = Client::factory()->create();

        try {
            app(ReservaFacturacionController::class)->store(new Request([
                'pasajero_ids' => [$f['p3']->id],
                'client_id' => $cliente->id,
                'tipo_comprobante_codigo' => '01',
            ]), (string) $f['reserva']->id);
            $this->fail('Se esperaba HttpException 403, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, Sale::count());
    }

    // Guard agregado tras code-review del 2026-08-20: store()/prepararFactura()
    // validaban tenants.facturacion_habilitada pero no reserva.facturacion_externa
    // — una reserva marcada como ya facturada afuera podía facturarse de nuevo
    // adentro con un POST directo, generando un comprobante SUNAT duplicado.
    public function test_store_lanza_403_si_reserva_tiene_facturacion_externa(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);
        $this->setUpTenantFixture(true);

        $f = $this->crearReservaConPasajerosEItems();
        $f['reserva']->update(['facturacion_externa' => true]);
        $cliente = Client::factory()->create();

        try {
            app(ReservaFacturacionController::class)->store(new Request([
                'pasajero_ids' => [$f['p3']->id],
                'client_id' => $cliente->id,
                'tipo_comprobante_codigo' => '01',
            ]), (string) $f['reserva']->id);
            $this->fail('Se esperaba HttpException 403, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, Sale::count());
    }

    public function test_preparar_factura_lanza_403_si_reserva_tiene_facturacion_externa(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);
        $this->setUpTenantFixture(true);

        $f = $this->crearReservaConPasajerosEItems();
        $f['reserva']->update(['facturacion_externa' => true]);

        try {
            app(ReservaFacturacionController::class)->prepararFactura(new Request([
                'pasajero_ids' => [$f['p3']->id],
            ]), (string) $f['reserva']->id);
            $this->fail('Se esperaba HttpException 403, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    private function crearReservaConMezclaTributaria(): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '11223344', 'full_name' => 'Cliente Test Mezcla Tributaria',
            'type_client' => 1, 'cod_tipo_doc_sunat' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0401-' . uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);

        $cpA = DB::table('cotizacion_pasajeros')->insertGetId([
            'cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 30,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cpB = DB::table('cotizacion_pasajeros')->insertGetId([
            'cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 31,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $destinoAtractivoId = DB::table('destinos_atractivos')->insertGetId([
            'nombre' => 'Tarapoto', 'tipo' => 'lugar', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $servicioId = DB::table('servicios')->insertGetId([
            'nombre' => 'Full Day Amazónico', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $destinoServicioId = DB::table('destino_servicio')->insertGetId([
            'destino_atractivo_id' => $destinoAtractivoId, 'servicio_id' => $servicioId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorId = DB::table('proveedores')->insertGetId([
            'razon_social' => 'Operador Amazónico SAC', 'estado' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorServicioId = DB::table('proveedor_servicios')->insertGetId([
            'proveedor_id' => $proveedorId, 'destino_servicio_id' => $destinoServicioId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $tarifaAmazoniaId = DB::table('proveedor_tarifas')->insertGetId([
            'proveedor_servicio_id' => $proveedorServicioId,
            'tipo_tarifa' => 'publica', 'modalidad' => 'privado', 'moneda' => 'PEN',
            'precio_costo' => 50, 'margen_tipo' => 'fijo', 'margen_valor' => 10,
            'precio_venta_adulto' => 60,
            'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '20', 'destino_tributario' => 'amazonia',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tarifaNacionalId = DB::table('proveedor_tarifas')->insertGetId([
            'proveedor_servicio_id' => $proveedorServicioId,
            'tipo_tarifa' => 'publica', 'modalidad' => 'compartido', 'moneda' => 'PEN',
            'precio_costo' => 30, 'margen_tipo' => 'fijo', 'margen_valor' => 10,
            'precio_venta_adulto' => 40,
            'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $itemAmazonia = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor',
            'proveedor_tarifa_id' => $tarifaAmazoniaId, 'dia_referencial' => 1, 'pax_incluidos' => [$cpA],
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 50, 'precio_venta_snapshot' => 60, 'precio_convertido' => 60,
        ]);
        $itemNacional = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor',
            'proveedor_tarifa_id' => $tarifaNacionalId, 'dia_referencial' => 1, 'pax_incluidos' => [$cpB],
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 30, 'precio_venta_snapshot' => 40, 'precio_convertido' => 40,
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        $pasajeros = ReservaPasajero::where('reserva_id', $reserva->id)->orderBy('id')->get();

        return [
            $reserva,
            ReservaItem::where('alternativa_item_id', $itemAmazonia->id)->first(),
            ReservaItem::where('alternativa_item_id', $itemNacional->id)->first(),
            $pasajeros[0],
            $pasajeros[1],
        ];
    }
}
