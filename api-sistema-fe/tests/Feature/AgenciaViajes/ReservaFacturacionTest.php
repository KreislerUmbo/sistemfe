<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaAnticipoController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaFacturacionController;
use App\Http\Controllers\Greenter\GreenterService;
use App\Http\Controllers\Sale\NotaElectronicaController;
use App\Models\Advance\Advance;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\AgenciaViajes\ReservaVenta;
use App\Models\AgenciaViajes\SaleDetailItem;
use App\Models\Cash\Branch;
use App\Models\Cash\CashRegister;
use App\Models\Cash\CashSession;
use App\Models\Client\Client;
use App\Models\Company;
use App\Models\Sale\Note;
use App\Models\Sale\Sale;
use App\Models\Sale\SaleDetail;
use App\Models\Sale\SerieComprobante;
use App\Models\TipoCambio\TipoCambioSunat;
use App\Models\User;
use Greenter\Factory\FeFactory;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Response\BaseResult;
use Greenter\Model\Sale\Note as GreenterNote;
use Greenter\See;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    // Mismo criterio que serieAdicional(), con moneda elegible — necesario
    // para los tests de "facturar en moneda distinta a la cotización"
    // (2026-09-24): SerieComprobanteService::resolverParaUsuario() resuelve
    // por (branch_id, tipo_comprobante_codigo, moneda), así que facturar en
    // PEN una cotización USD exige que la sucursal tenga una serie PEN real
    // para ese tipo de comprobante, no solo la serie USD de la cotización.
    private function serieAdicionalMoneda(Branch $branch, string $codigo, string $serieTexto, string $moneda): SerieComprobante
    {
        return SerieComprobante::create([
            'branch_id' => $branch->id,
            'tipo_comprobante_codigo' => $codigo,
            'moneda' => $moneda,
            'serie' => $serieTexto,
            'correlativo_actual' => 0,
            'correlativo_inicial' => 1,
            'fecha_inicio' => now()->format('Y-m-d'),
            'activo' => true,
        ]);
    }

    // Fixture liviana para los tests de "facturar en moneda distinta a la
    // cotización" (2026-09-24) — a diferencia de crearReservaConPasajerosEItems()
    // (pensada para el guard de selección por pasajero), acá solo importa 1
    // pasajero + 1 ítem con un monto redondo, en la moneda que pida el test.
    private function crearReservaConUnPasajeroYUnItemEnMoneda(string $moneda, float $precioConvertido = 100): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '99887766', 'full_name' => 'Cliente Test Moneda',
            'type_client' => 1, 'cod_tipo_doc_sunat' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0700-' . uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => $moneda, 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
        $cp1 = DB::table('cotizacion_pasajeros')->insertGetId([
            'cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 30,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 1,
            'descripcion_manual' => 'Servicio en ' . $moneda, 'modo_precio' => 'tarifa_fija', 'cantidad' => 1,
            'moneda_costo' => $moneda, 'costo_snapshot' => $precioConvertido * 0.8,
            'precio_venta_snapshot' => $precioConvertido, 'precio_convertido' => $precioConvertido,
            'pax_incluidos' => [$cp1],
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());
        $pasajero = ReservaPasajero::where('reserva_id', $reserva->id)->first();

        return ['reserva' => $reserva, 'pasajero' => $pasajero];
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
    private function crearReservaConPasajerosEItems(string $monedaCotizacion = 'PEN'): array
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
            'moneda_cotizacion' => $monedaCotizacion, 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
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
        $cliente = Client::factory()->empresa()->create();

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

    // Pedido del usuario (2026-09-24): "debo poder ver lo que he
    // seleccionado si fue gravado o exonerado" — antes items_por_destino_
    // tributario solo viajaba cuando bloqueado_tributario=true.
    public function test_preparar_factura_incluye_tratamiento_tributario_aunque_no_este_bloqueado(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        $f = $this->crearReservaConPasajerosEItems();

        $preview = app(ReservaFacturacionController::class)->prepararFactura(new Request([
            'pasajero_ids' => [$f['p1']->id, $f['p2']->id],
        ]), (string) $f['reserva']->id);
        $body = $preview->getData(true);

        $this->assertSame(200, $preview->getStatusCode());
        $this->assertFalse($body['bloqueado_tributario']);
        $itemsPorDestino = collect($body['items_por_destino_tributario'])->keyBy('reserva_item_id');
        $this->assertSame('nacional', $itemsPorDestino[$f['itemP1']->id]['destino_tributario']);
        $this->assertSame('10', $itemsPorDestino[$f['itemP1']->id]['tip_afe_igv']);
    }

    // Pedido del usuario (2026-09-24): la agencia factura en PEN y USD con
    // series distintas para mejor control — mismo patrón exacto que
    // SaleController::store() (branch_id + can_switch_branch).
    public function test_store_permite_elegir_sucursal_si_usuario_tiene_can_switch_branch(): void
    {
        [$branchA, ] = $this->branchConSerie('01', 'F001');
        [$branchB, ] = $this->branchConSerie('01', 'F002');
        $this->usuarioConPermisos($branchA->id, ['emitir_factura', 'can_switch_branch']);

        $f = $this->crearReservaConPasajerosEItems();
        $cliente = Client::factory()->empresa()->create();

        $response = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$f['p1']->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
            'branch_id' => $branchB->id,
        ]), (string) $f['reserva']->id);

        $body = $response->getData(true);
        $this->assertSame(200, $body['code'], json_encode($body));
        $this->assertSame('F002', Sale::find($body['sale_id'])->serie, 'debe usar la serie de la sucursal elegida, no la propia');
    }

    public function test_store_ignora_branch_id_sin_permiso_can_switch_branch(): void
    {
        [$branchA, ] = $this->branchConSerie('01', 'F001');
        [$branchB, ] = $this->branchConSerie('01', 'F002');
        $this->usuarioConPermisos($branchA->id, ['emitir_factura']); // sin can_switch_branch

        $f = $this->crearReservaConPasajerosEItems();
        $cliente = Client::factory()->empresa()->create();

        $response = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$f['p1']->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
            'branch_id' => $branchB->id,
        ]), (string) $f['reserva']->id);

        $body = $response->getData(true);
        $this->assertSame(200, $body['code'], json_encode($body));
        $this->assertSame('F001', Sale::find($body['sale_id'])->serie, 'sin el permiso, branch_id se ignora — usa la sucursal propia');
    }

    // Pedido del usuario (2026-09-24): "tengo mi cotización en dólares,
    // pero el cliente pide que le facture en soles" — sin moneda_facturacion
    // en el request, el comportamiento de siempre no cambia: la Sale nace
    // en la moneda de la cotización, sin ningún override persistido.
    public function test_store_sin_moneda_facturacion_mantiene_la_moneda_de_la_cotizacion(): void
    {
        $branch = Branch::create(['name' => 'Sede USD', 'is_active' => true]);
        $this->serieAdicionalMoneda($branch, '01', 'F002', 'USD');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        $f = $this->crearReservaConUnPasajeroYUnItemEnMoneda('USD', 100);
        $cliente = Client::factory()->empresa()->create();

        $response = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$f['pasajero']->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $f['reserva']->id);

        $body = $response->getData(true);
        $this->assertSame(200, $body['code'], json_encode($body));

        $venta = Sale::find($body['sale_id']);
        $this->assertSame('USD', $venta->currency);
        $this->assertSame(100.0, (float) $venta->total);
        $this->assertNull($venta->moneda_original_cotizacion);
        $this->assertNull($venta->tipo_cambio_override_moneda);
        $this->assertNull($venta->motivo_override_moneda);
    }

    // Caso real (2026-09-24): cotización en USD, cliente pide el
    // comprobante en soles — moneda_facturacion+tipo_cambio_conversion+
    // motivo_cambio_moneda convierten los montos y quedan auditados en la
    // Sale (mismo criterio que motivo_override_tributario en reserva_items).
    public function test_store_factura_en_moneda_distinta_a_la_cotizacion_convierte_montos_y_persiste_override(): void
    {
        [$branchPen, ] = $this->branchConSerie('01', 'F001'); // PEN, por branchConSerie()
        $this->serieAdicionalMoneda($branchPen, '01', 'F009', 'USD');
        $this->usuarioConPermisos($branchPen->id, ['emitir_factura']);

        $f = $this->crearReservaConUnPasajeroYUnItemEnMoneda('USD', 100);
        $cliente = Client::factory()->empresa()->create();

        $response = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$f['pasajero']->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
            'moneda_facturacion' => 'PEN',
            'tipo_cambio_conversion' => 3.75,
            'motivo_cambio_moneda' => 'Cliente pidió el comprobante en soles.',
        ]), (string) $f['reserva']->id);

        $body = $response->getData(true);
        $this->assertSame(200, $body['code'], json_encode($body));

        $venta = Sale::find($body['sale_id']);
        $this->assertSame('PEN', $venta->currency, 'debe usar la serie PEN, no la USD de la cotización');
        $this->assertSame('F001', $venta->serie);
        // 100 USD/1.18 = 84.75 subtotal, 15.25 igv -> x3.75 = 317.81 + 57.19 = 375.00
        $this->assertSame(375.0, (float) $venta->total);
        $this->assertSame(317.81, (float) $venta->subtotal);
        $this->assertSame('USD', $venta->moneda_original_cotizacion);
        $this->assertSame(3.75, (float) $venta->tipo_cambio_override_moneda);
        $this->assertSame('Cliente pidió el comprobante en soles.', $venta->motivo_override_moneda);
    }

    public function test_store_rechaza_moneda_facturacion_distinta_sin_tipo_cambio_o_motivo(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->serieAdicionalMoneda($branch, '01', 'F009', 'USD');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        $f = $this->crearReservaConUnPasajeroYUnItemEnMoneda('USD', 100);
        $cliente = Client::factory()->empresa()->create();

        $response = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$f['pasajero']->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
            'moneda_facturacion' => 'PEN',
            // sin tipo_cambio_conversion ni motivo_cambio_moneda
        ]), (string) $f['reserva']->id);

        $body = $response->getData(true);
        $this->assertSame(422, $body['code'], json_encode($body));
        $this->assertSame(0, Sale::count());
    }

    // Bug real de auditoría (2026-09-24): un anticipo pagado en la moneda
    // ORIGINAL de la cotización (USD) no debe intentar aplicarse a una
    // Sale que terminó en otra moneda por el override — antes del fix,
    // el auto-aplicado mezclaba montos de las 2 monedas sin convertir y
    // AdvanceApplicationService::aplicar() abortaba TODA la transacción
    // con un 422 de "moneda distinta", aunque el vendedor nunca pidió usar
    // ese anticipo. Debe simplemente ignorarlo y dejar el total íntegro.
    public function test_store_ignora_anticipo_en_otra_moneda_al_facturar_con_override_de_moneda(): void
    {
        [$branchPen, ] = $this->branchConSerie('01', 'F001');
        $this->serieAdicionalMoneda($branchPen, '01', 'F009', 'USD');
        // El propio comprobante del anticipo (AdvanceController::store())
        // se emite en la moneda de la reserva (USD, boleta por defecto).
        $this->serieAdicionalMoneda($branchPen, '03', 'B009', 'USD');
        $usuario = $this->usuarioConPermisos($branchPen->id, ['emitir_factura', 'register_advance']);

        // AdvanceController::store() (invocado por ReservaAnticipoController)
        // exige una sesión de caja abierta para el usuario/sucursal.
        $cashRegister = CashRegister::create(['branch_id' => $branchPen->id, 'name' => 'Caja Test', 'is_active' => true]);
        CashSession::create([
            'cash_register_id' => $cashRegister->id, 'opened_by' => $usuario->id,
            'opening_amount' => 0, 'opened_at' => now(), 'status' => 'open',
        ]);

        $f = $this->crearReservaConUnPasajeroYUnItemEnMoneda('USD', 100);

        app(ReservaAnticipoController::class)->store(new Request([
            'monto' => 20.00, 'medio_pago' => 'EFECTIVO', 'tip_afe_igv' => '10',
        ]), (string) $f['reserva']->id);
        $advanceUsd = Advance::first();
        $this->assertNotNull($advanceUsd);
        $this->assertSame('USD', $advanceUsd->currency);

        $cliente = Client::factory()->empresa()->create();
        $response = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$f['pasajero']->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
            'moneda_facturacion' => 'PEN',
            'tipo_cambio_conversion' => 3.75,
            'motivo_cambio_moneda' => 'Cliente pidió el comprobante en soles.',
        ]), (string) $f['reserva']->id);

        $body = $response->getData(true);
        $this->assertSame(200, $body['code'], json_encode($body));

        $venta = Sale::find($body['sale_id']);
        $this->assertSame('PEN', $venta->currency);
        $this->assertSame(375.0, (float) $venta->total, 'no se aplicó el anticipo en USD, el total queda íntegro');
        $this->assertSame(0.0, (float) $venta->paid_out);
        $this->assertSame(20.0, (float) $advanceUsd->fresh()->availableBalance(), 'el anticipo en USD queda intacto para una futura sub-factura en su propia moneda');
    }

    // Bug real de auditoría (2026-09-24): convertir el AGREGADO
    // (subtotalTotal/igvTotal) por separado de las líneas individuales
    // podía desalinear el header de la Sale (sales.subtotal/igv) respecto
    // a la suma real de sus sale_details — sumar N líneas redondeadas
    // independientemente no siempre da lo mismo que redondear la suma. El
    // fix recalcula el agregado DESDE las líneas ya convertidas, así que
    // esta invariante debe cumplirse siempre, sin importar el tipo de
    // cambio ni si hay o no rounding artifacts con estos números puntuales.
    public function test_store_con_conversion_de_moneda_el_total_de_la_venta_coincide_con_la_suma_de_sus_lineas(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->serieAdicionalMoneda($branch, '01', 'F009', 'USD');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        // 2 categorías distintas (TRANSPORTE 40, vía itemP2 auto-incluido
        // solo con p2; OTROS 10, vía itemSinAsignar manual) -> 2
        // sale_details separados con montos que SÍ producen divergencia de
        // redondeo real con tipo_cambio_conversion=4.35 (verificado con
        // PriceEngineService::convertirMoneda antes de escribir el test —
        // sin esta combinación puntual de montos/tipo de cambio, el bug no
        // se manifestaba). Solo p2 (no p1) para no arrastrar itemCompartido
        // (vinculado a ambos) y mantener exactamente 2 líneas conocidas.
        $f = $this->crearReservaConPasajerosEItems('USD');
        $cliente = Client::factory()->empresa()->create();

        $response = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$f['p2']->id],
            'reserva_item_ids_manual' => [$f['itemSinAsignar']->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
            'moneda_facturacion' => 'PEN',
            'tipo_cambio_conversion' => 4.35,
            'motivo_cambio_moneda' => 'Cliente pidió el comprobante en soles.',
        ]), (string) $f['reserva']->id);

        $body = $response->getData(true);
        $this->assertSame(200, $body['code'], json_encode($body));

        $venta = Sale::find($body['sale_id']);
        $sumaLineas = SaleDetail::where('sale_id', $venta->id)->get();
        $this->assertGreaterThanOrEqual(2, $sumaLineas->count(), 'precondición: al menos 2 líneas separadas');

        $this->assertSame(round((float) $sumaLineas->sum('subtotal'), 2), (float) $venta->subtotal);
        $this->assertSame(round((float) $sumaLineas->sum('igv'), 2), (float) $venta->igv);
        $this->assertSame(
            round((float) $sumaLineas->sum('subtotal') + (float) $sumaLineas->sum('igv'), 2),
            (float) $venta->total
        );
    }

    // El preview de solo lectura sugiere el tipo de cambio SUNAT del día
    // (o el último hábil anterior) y devuelve los montos ya convertidos con
    // ese valor — sin que el vendedor tenga que escribir nada todavía.
    public function test_preparar_factura_sugiere_tipo_cambio_sunat_y_convierte_el_preview(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        TipoCambioSunat::create([
            'fecha' => now()->toDateString(), 'compra' => 3.70, 'venta' => 3.80,
            'fuente' => 'e-api', 'consultado_en' => now(),
        ]);

        $f = $this->crearReservaConUnPasajeroYUnItemEnMoneda('USD', 100);

        $preview = app(ReservaFacturacionController::class)->prepararFactura(new Request([
            'pasajero_ids' => [$f['pasajero']->id],
            'moneda_facturacion' => 'PEN',
        ]), (string) $f['reserva']->id);

        $body = $preview->getData(true);
        $this->assertSame(200, $preview->getStatusCode());
        $this->assertSame('USD', $body['moneda_cotizacion_original']);
        $this->assertSame('PEN', $body['moneda_facturacion']);
        $this->assertSame(3.8, (float) $body['tipo_cambio_sugerido']);
        $this->assertSame(3.8, (float) $body['tipo_cambio_aplicado']);
        // 100/1.18=84.75 subtotal x3.80 = 322.05, igv 15.25x3.80=57.95 -> total 380.00
        $this->assertSame(380.0, (float) $body['total']);
    }

    // Pedido del usuario (2026-09-24): "debe aparecer de manera visible la
    // serie" — antes el preview no decía qué serie iba a usarse hasta
    // confirmar. Solo se resuelve si el vendedor ya eligió tipo de
    // comprobante (sin eso, null — el preview nunca rompe por esto).
    public function test_preparar_factura_incluye_la_serie_resuelta_si_se_indica_tipo_de_comprobante(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        $f = $this->crearReservaConPasajerosEItems();

        $sinTipo = app(ReservaFacturacionController::class)->prepararFactura(new Request([
            'pasajero_ids' => [$f['p1']->id],
        ]), (string) $f['reserva']->id);
        $this->assertNull($sinTipo->getData(true)['serie_resuelta']);

        $conTipo = app(ReservaFacturacionController::class)->prepararFactura(new Request([
            'pasajero_ids' => [$f['p1']->id],
            'tipo_comprobante_codigo' => '01',
        ]), (string) $f['reserva']->id);
        $this->assertSame('F001', $conTipo->getData(true)['serie_resuelta']);
    }

    public function test_items_sin_asignar_requieren_seleccion_explicita_y_no_se_incluyen_solos(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        $f = $this->crearReservaConPasajerosEItems();
        $cliente = Client::factory()->empresa()->create();

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
        $cliente = Client::factory()->empresa()->create();

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

    // Escenario real reportado por el usuario probando manualmente contra
    // agencia-demo (2026-08-24): facturar una reserva y después anular esa
    // venta (Nota de Crédito total, motivo NC01) dejaba la reserva
    // marcada como facturada para siempre — pasajerosYaFacturadosIds()/
    // itemsYaFacturadosIds() seguían contando la ReservaVenta como
    // vigente, sin ningún camino de recuperación por la API (pasajero_ids
    // exige min:1 y rechaza cualquier pasajero ya facturado). Cerrado
    // conectando NotaElectronicaController::enviarNotaSunat() — al aceptar
    // una NC total, borra la ReservaVenta cuyo sale_id es el de la venta
    // anulada.
    public function test_anular_venta_por_nota_credito_total_libera_la_reserva_para_refacturar(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $usuario = $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        $f = $this->crearReservaConPasajerosEItems();
        $cliente = Client::factory()->empresa()->create();

        $response = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$f['p3']->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $f['reserva']->id);
        $body = $response->getData(true);
        $this->assertSame(200, $body['code'], json_encode($body));
        $ventaId = $body['sale_id'];

        $this->assertNotNull(ReservaVenta::where('sale_id', $ventaId)->first(), 'precondición: la ReservaVenta existe');

        // Refacturar el mismo pasajero ANTES de anular: sigue bloqueado
        // (comportamiento ya cubierto por test_rechaza_refacturar_pasajero_ya_facturado,
        // repetido acá solo como control antes/después).
        $intentoAntes = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$f['p3']->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $f['reserva']->id);
        $this->assertSame(422, $intentoAntes->getStatusCode());

        // ── Anular la venta con una NC total (07, tipo_afectacion='total') ──
        Storage::fake('public');
        Company::create([
            'razon_social' => 'Empresa de Prueba SAC',
            'razon_social_comercial' => 'Empresa de Prueba',
            'n_document' => '20123456789',
        ]);

        $nota = Note::create([
            'sale_id' => $ventaId,
            'tipo_doc' => '07',
            'tipo_doc_afectado' => '01',
            'serie_afectada' => 'F001',
            'correlativo_afectado' => 1,
            'serie' => 'FC01',
            'cod_motivo' => '01',
            'des_motivo' => 'Anulación de la operación',
            'tipo_afectacion' => 'total',
            'client_id' => $cliente->id,
            'cod_tipo_doc_cliente' => $cliente->cod_tipo_doc_sunat,
            'currency' => 'PEN',
            'status' => 'pendiente',
            'reponer_stock' => false,
            'user_id' => $usuario->id,
        ]);

        $this->app->instance(GreenterService::class, $this->greenterServiceQueAceptaNota());

        $responseNota = app(NotaElectronicaController::class)->enviarNotaSunat(new Request(['note_id' => $nota->id]));
        $dataNota = $responseNota->getData(true);
        $this->assertSame('aceptado', $dataNota['note']['status'], json_encode($dataNota));

        // ── La ReservaVenta original desapareció ──
        $this->assertNull(ReservaVenta::where('sale_id', $ventaId)->first(), 'la ReservaVenta debe liberarse tras la NC total aceptada');

        // ── Y ahora sí se puede refacturar el mismo pasajero ──
        $intentoDespues = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$f['p3']->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $f['reserva']->id);
        $bodyDespues = $intentoDespues->getData(true);
        $this->assertSame(200, $bodyDespues['code'], json_encode($bodyDespues));
        $this->assertSame(2, Sale::count(), 'la venta anulada + la nueva');
    }

    // Hallazgo de auditoría (2026-09-23): a diferencia del caso 'total' de
    // arriba, una NC PARCIAL nunca tocaba reserva_item_ids — el servicio
    // acreditado quedaba "facturado" para siempre, sin ningún camino de
    // recuperación (ni refacturarlo, ni editarlo, ni quitarlo de la
    // reserva). Fixture: p1 (dueño de itemP1, categoría HOTEL) +
    // itemSinAsignar (manual, categoría OTROS) facturados JUNTOS en el
    // mismo Sale vía Facturación especial — 2 categorías distintas =>
    // 2 SaleDetail separados dentro del mismo comprobante, así se puede
    // acreditar SOLO uno de los dos.
    public function test_nota_credito_parcial_libera_solo_el_item_acreditado_no_toda_la_venta(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        $f = $this->crearReservaConPasajerosEItems();
        $empresa = Client::factory()->empresa()->create();

        $response = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$f['p1']->id],
            'reserva_item_ids_manual' => [$f['itemSinAsignar']->id],
            'client_id' => $empresa->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $f['reserva']->id);
        $body = $response->getData(true);
        $this->assertSame(200, $body['code'], json_encode($body));
        $ventaId = $body['sale_id'];

        $reservaVenta = ReservaVenta::where('sale_id', $ventaId)->first();
        $this->assertNotNull($reservaVenta);
        $this->assertContains($f['itemP1']->id, $reservaVenta->reserva_item_ids, 'precondición: itemP1 facturado');
        $this->assertContains($f['itemSinAsignar']->id, $reservaVenta->reserva_item_ids, 'precondición: itemSinAsignar facturado');

        // Localiza el SaleDetail que cubre SOLO itemSinAsignar (categoría
        // OTROS, distinta de HOTEL) vía la tabla puente sale_detail_items.
        $saleDetailItemAcreditar = SaleDetailItem::where('reserva_item_id', $f['itemSinAsignar']->id)->first();
        $this->assertNotNull($saleDetailItemAcreditar, 'itemSinAsignar debe tener su propia línea de comprobante');
        $saleDetailOriginal = SaleDetail::find($saleDetailItemAcreditar->sale_detail_id);
        $this->assertNotSame(
            SaleDetailItem::where('reserva_item_id', $f['itemP1']->id)->first()->sale_detail_id,
            $saleDetailOriginal->id,
            'itemP1 e itemSinAsignar deben estar en líneas de comprobante DISTINTAS (categorías distintas)'
        );
        $valorLineaConIgv = round((float) $saleDetailOriginal->subtotal + (float) $saleDetailOriginal->igv, 2);

        // correlativo también hace falta: store() lo copia a
        // notes.correlativo_afectado (NOT NULL) — mismo criterio que
        // AdvanceCorreccionTest::marcarAceptadoPorSunat().
        Sale::where('id', $ventaId)->update([
            'correlativo' => $ventaId,
            'n_operacion' => 'F001-' . str_pad((string) $ventaId, 8, '0', STR_PAD_LEFT),
            'xml' => '<xml>fake</xml>',
            'cdr' => 'fake-cdr-content',
        ]);

        Storage::fake('public');
        Company::create([
            'razon_social' => 'Empresa de Prueba SAC',
            'razon_social_comercial' => 'Empresa de Prueba',
            'n_document' => '20123456789',
        ]);

        // NC09 "Disminución en el valor" — permite_parcial + modo_monto,
        // así se acredita el valor completo de la línea sin tocar cantidad/stock.
        $responseStore = app(NotaElectronicaController::class)->store(new Request([
            'sale_id' => $ventaId,
            'tipo_doc' => '07',
            'tipo_afectacion' => 'parcial',
            'cod_motivo' => '09',
            'des_motivo' => 'Ajuste — servicio anulado',
            'items' => [
                ['sale_detail_id' => $saleDetailOriginal->id, 'monto' => $valorLineaConIgv],
            ],
        ]));
        $bodyNota = $responseStore->getData(true);
        $this->assertSame(200, $responseStore->getStatusCode(), json_encode($bodyNota));
        $notaId = $bodyNota['note']['id'];

        $this->app->instance(GreenterService::class, $this->greenterServiceQueAceptaNota());

        $responseNota = app(NotaElectronicaController::class)->enviarNotaSunat(new Request(['note_id' => $notaId]));
        $dataNota = $responseNota->getData(true);
        $this->assertSame('aceptado', $dataNota['note']['status'], json_encode($dataNota));

        $reservaVentaFresh = $reservaVenta->fresh();
        $this->assertNotNull($reservaVentaFresh, 'la venta sigue cubriendo itemP1 — no debió borrarse completa');
        $this->assertNotContains($f['itemSinAsignar']->id, $reservaVentaFresh->reserva_item_ids, 'el ítem acreditado debe liberarse');
        $this->assertContains($f['itemP1']->id, $reservaVentaFresh->reserva_item_ids, 'el ítem NO acreditado debe seguir facturado');

        // Y ahora sí se puede quitar/re-facturar el ítem liberado — antes
        // del fix, ReservaItemController::destroy() lo rechazaba por
        // yaFacturado para siempre.
        $itemLiberado = ReservaItem::find($f['itemSinAsignar']->id);
        $this->assertFalse(
            ReservaVenta::where('reserva_id', $f['reserva']->id)
                ->get()
                ->flatMap(fn (ReservaVenta $rv) => $rv->reserva_item_ids ?? [])
                ->contains($itemLiberado->id)
        );
    }

    // Bug real de auditoría (2026-09-24) — "ítem zombie": a diferencia del
    // test anterior (que acredita itemSinAsignar, sin pasajero vinculado),
    // acá se acredita un ítem CON pasajero vinculado (itemP1) mientras ese
    // mismo pasajero (p1) sigue cubierto por otro ítem en la misma
    // ReservaVenta (itemSinAsignar) — reserva_pasajero_ids nunca se toca al
    // liberar parcialmente, así que p1 sigue "ya facturado". Antes del fix,
    // itemP1 no calificaba ni para itemsAuto (su pasajero está bloqueado
    // por pasajerosRepetidos) ni para itemsSinAsignar (tiene pasajero
    // vinculado) — quedaba invisible para siempre, sin ningún camino de
    // re-facturación por la API.
    public function test_nota_credito_parcial_de_item_con_pasajero_vinculado_permite_refacturarlo_despues(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        $f = $this->crearReservaConPasajerosEItems();
        $empresa = Client::factory()->empresa()->create();

        $response = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$f['p1']->id],
            'reserva_item_ids_manual' => [$f['itemSinAsignar']->id],
            'client_id' => $empresa->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $f['reserva']->id);
        $body = $response->getData(true);
        $this->assertSame(200, $body['code'], json_encode($body));
        $ventaId = $body['sale_id'];

        $reservaVenta = ReservaVenta::where('sale_id', $ventaId)->first();

        // Localiza el SaleDetail que cubre SOLO itemP1 (categoría HOTEL,
        // distinta de itemSinAsignar/OTROS).
        $saleDetailItemAcreditar = SaleDetailItem::where('reserva_item_id', $f['itemP1']->id)->first();
        $saleDetailOriginal = SaleDetail::find($saleDetailItemAcreditar->sale_detail_id);
        $valorLineaConIgv = round((float) $saleDetailOriginal->subtotal + (float) $saleDetailOriginal->igv, 2);

        Sale::where('id', $ventaId)->update([
            'correlativo' => $ventaId,
            'n_operacion' => 'F001-' . str_pad((string) $ventaId, 8, '0', STR_PAD_LEFT),
            'xml' => '<xml>fake</xml>',
            'cdr' => 'fake-cdr-content',
        ]);

        Storage::fake('public');
        Company::create([
            'razon_social' => 'Empresa de Prueba SAC',
            'razon_social_comercial' => 'Empresa de Prueba',
            'n_document' => '20123456789',
        ]);

        $responseStore = app(NotaElectronicaController::class)->store(new Request([
            'sale_id' => $ventaId,
            'tipo_doc' => '07',
            'tipo_afectacion' => 'parcial',
            'cod_motivo' => '09',
            'des_motivo' => 'Ajuste — servicio de hotel anulado',
            'items' => [
                ['sale_detail_id' => $saleDetailOriginal->id, 'monto' => $valorLineaConIgv],
            ],
        ]));
        $notaId = $responseStore->getData(true)['note']['id'];

        $this->app->instance(GreenterService::class, $this->greenterServiceQueAceptaNota());
        $responseNota = app(NotaElectronicaController::class)->enviarNotaSunat(new Request(['note_id' => $notaId]));
        $this->assertSame('aceptado', $responseNota->getData(true)['note']['status']);

        $reservaVentaFresh = $reservaVenta->fresh();
        $this->assertNotContains($f['itemP1']->id, $reservaVentaFresh->reserva_item_ids, 'itemP1 debe liberarse');
        $this->assertContains($f['itemSinAsignar']->id, $reservaVentaFresh->reserva_item_ids, 'itemSinAsignar sigue cubierto');
        $this->assertContains($f['p1']->id, $reservaVentaFresh->reserva_pasajero_ids, 'p1 sigue "facturado" — la NC parcial nunca lo libera a él');

        // El punto del fix: itemP1 (liberado, con pasajero) debe poder
        // volver a facturarse — reenviando p1 (ya facturado, solo para
        // cumplir min:1) + reserva_item_ids_manual, mismo mecanismo que
        // "Facturar simple" ya usa para arrastrar ítems sueltos.
        $segundaVenta = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$f['p1']->id],
            'reserva_item_ids_manual' => [$f['itemP1']->id],
            'client_id' => $empresa->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $f['reserva']->id);
        $bodySegunda = $segundaVenta->getData(true);
        $this->assertSame(200, $bodySegunda['code'], json_encode($bodySegunda));
        $this->assertSame(100.0, (float) Sale::find($bodySegunda['sale_id'])->total);
    }

    // Mismo criterio que EnviarSunatCdrFailureTest::greenterServiceQueFallaAlProcesar():
    // getSee()/getNote()/procesarRespuestaSunat() completamente controlados
    // — cero red real, cero certificado real. getFactory() además mockeado
    // (a diferencia de esa clase) porque este test SÍ necesita llegar a la
    // rama de éxito (status='aceptado'), que llama
    // $see->getFactory()->getLastXml() para armar el XML final — cualquier
    // XML bien formado alcanza, extraerHashDigest() devuelve null en
    // silencio si no encuentra el nodo DigestValue.
    private function greenterServiceQueAceptaNota(): GreenterService
    {
        return new class extends GreenterService {
            public function getSee(): See
            {
                return new class extends See {
                    public function send(DocumentInterface $document): ?BaseResult
                    {
                        return null;
                    }

                    public function getFactory(): FeFactory
                    {
                        return new class extends FeFactory {
                            public function getLastXml(): ?string
                            {
                                return '<root/>';
                            }
                        };
                    }
                };
            }

            public function getNote(array $datos_nota, $empresa, $nota): GreenterNote
            {
                return new GreenterNote();
            }

            public function procesarRespuestaSunat($resultado): array
            {
                return ['cdrZip' => 'fake-cdr-contenido-de-prueba'];
            }
        };
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

        // Hallazgo de auditoría (2026-09-23): antes solo se devolvían los
        // VALORES distintos, sin decir qué servicio es cuál — el frontend
        // recibía el dato y lo descartaba. Ahora viene el mapeo completo.
        $itemsPorDestino = collect($body['items_por_destino_tributario'])->keyBy('reserva_item_id');
        $this->assertSame('amazonia', $itemsPorDestino[$itemAmazonia->id]['destino_tributario']);
        $this->assertSame('nacional', $itemsPorDestino[$itemNacional->id]['destino_tributario']);

        // Preview: mismo bloqueo, sin necesidad de intentar el POST.
        $preview = app(ReservaFacturacionController::class)->prepararFactura(new Request([
            'pasajero_ids' => [$pasajeroA->id, $pasajeroB->id],
        ]), (string) $reserva->id);
        $previewBody = $preview->getData(true);
        $this->assertSame(200, $preview->getStatusCode());
        $this->assertTrue($previewBody['bloqueado_tributario']);
        $this->assertArrayNotHasKey('grupos_propuestos', $previewBody);
        $this->assertCount(2, $previewBody['items_por_destino_tributario']);
    }

    public function test_guardia_tributario_es_por_subgrupo_no_por_reserva_completa(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        [$reserva, $itemAmazonia, $itemNacional, $pasajeroA, $pasajeroB] = $this->crearReservaConMezclaTributaria();
        $cliente = Client::factory()->empresa()->create();

        // Facturar solo a pasajeroB (su ítem 'nacional' solo) — no hay
        // mezcla ni tratamiento no-nacional dentro de ESTE Sale, debe
        // pasar sin problema aunque la reserva completa mezcle
        // tratamientos entre sus pasajeros.
        $response = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$pasajeroB->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $reserva->id);

        $body = $response->getData(true);
        $this->assertSame(200, $body['code'], json_encode($body));
        $this->assertSame(1, Sale::count());

        // pasajeroA (su ítem 'amazonia' solo) sigue bloqueado — no por
        // mezcla (es homogéneo dentro de este subgrupo), sino por el
        // guard "tratamiento no nacional pausado" (fix tributario
        // 2026-08-24, ver detectarMezclaTributaria()): confirma que el
        // guardia evalúa el SUBGRUPO elegido, no la reserva completa —
        // pasajeroB pasa, pasajeroA de la misma reserva no.
        $responseA = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$pasajeroA->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $reserva->id);
        $bodyA = $responseA->getData(true);
        $this->assertSame(422, $responseA->getStatusCode());
        $this->assertTrue($bodyA['bloqueado_tributario']);
        $this->assertSame(1, Sale::count(), 'el intento bloqueado no debe crear nada');
    }

    // ── overrideTratamientoTributario() — Caso 3 Amazonía (2026-09-24) ──

    public function test_override_confirma_amazonia_y_permite_facturar(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        [$reserva, $itemAmazonia, , $pasajeroA] = $this->crearReservaConMezclaTributaria();
        $cliente = Client::factory()->empresa()->create();

        // Antes de confirmar, sigue bloqueado — mismo criterio que
        // test_guardia_tributario_es_por_subgrupo_no_por_reserva_completa().
        $antes = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$pasajeroA->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $reserva->id);
        $this->assertSame(422, $antes->getStatusCode());
        $this->assertStringContainsString('confirmados', $antes->getData(true)['motivo']);

        $override = app(ReservaController::class)->overrideTratamientoTributario(new Request([
            'reserva_item_ids' => [$itemAmazonia->id],
            'destino_tributario' => 'amazonia',
            'tip_afe_igv' => '20',
            'motivo' => 'Servicio confirmado en zona Amazonía por el operador, exonerado según Ley 27037.',
        ]), (string) $reserva->id);
        $this->assertSame(200, $override->getStatusCode(), json_encode($override->getData(true)));
        $this->assertNotNull($itemAmazonia->fresh()->motivo_override_tributario);
        $this->assertNotNull($itemAmazonia->fresh()->fecha_override_tributario);

        $despues = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$pasajeroA->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $reserva->id);
        $bodyDespues = $despues->getData(true);
        $this->assertSame(200, $bodyDespues['code'], json_encode($bodyDespues));

        $venta = Sale::find($bodyDespues['sale_id']);
        $this->assertGreaterThan(0, (float) $venta->mto_oper_exoneradas, 'debe salir como operación exonerada, no gravada');
        $this->assertSame(0.0, (float) $venta->igv, 'exonerado no debe generar IGV');
    }

    public function test_override_rechaza_reserva_no_activa(): void
    {
        [$reserva, $itemAmazonia] = $this->crearReservaConMezclaTributaria();
        $reserva->update(['estado' => 'cancelada']);

        $response = app(ReservaController::class)->overrideTratamientoTributario(new Request([
            'reserva_item_ids' => [$itemAmazonia->id],
            'destino_tributario' => 'amazonia', 'tip_afe_igv' => '20', 'motivo' => 'x',
        ]), (string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNull($itemAmazonia->fresh()->motivo_override_tributario);
    }

    public function test_override_exige_motivo(): void
    {
        [$reserva, $itemAmazonia] = $this->crearReservaConMezclaTributaria();

        $response = app(ReservaController::class)->overrideTratamientoTributario(new Request([
            'reserva_item_ids' => [$itemAmazonia->id],
            'destino_tributario' => 'amazonia', 'tip_afe_igv' => '20',
        ]), (string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNull($itemAmazonia->fresh()->motivo_override_tributario);
    }

    public function test_override_rechaza_item_ya_facturado(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        [$reserva, , $itemNacional, , $pasajeroB] = $this->crearReservaConMezclaTributaria();
        $cliente = Client::factory()->empresa()->create();

        app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$pasajeroB->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $reserva->id);

        $response = app(ReservaController::class)->overrideTratamientoTributario(new Request([
            'reserva_item_ids' => [$itemNacional->id],
            'destino_tributario' => 'amazonia', 'tip_afe_igv' => '20', 'motivo' => 'x',
        ]), (string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('facturado', $response->getData(true)['message']);
        $this->assertNull($itemNacional->fresh()->motivo_override_tributario);
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

    // Análisis de impuestos (28-ago-2026) — regresión del Caso 2 del
    // análisis: 2 ítems de la MISMA categoría y MISMO destino_tributario
    // ('nacional', pasa el guardia sin problema) pero distinto tip_afe_igv
    // (uno gravado por Apéndice II, no relacionado con Amazonía). Antes de
    // este fix se fusionaban en una sola línea de SaleDetail con 18% fijo
    // para todo — el exonerado terminaba pagando IGV igual, sin que
    // ninguna guardia lo detectara (detectarMezclaTributaria() solo mira
    // destino_tributario, nunca tip_afe_igv).
    public function test_factura_dos_tratamientos_tributarios_distintos_en_la_misma_categoria_y_destino(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermisos($branch->id, ['emitir_factura']);

        [$reserva, $itemGravado, $itemExonerado, $pasajeroA, $pasajeroB] = $this->crearReservaConTratamientoTributarioMixtoMismoDestino();
        $cliente = Client::factory()->empresa()->create();

        $response = app(ReservaFacturacionController::class)->store(new Request([
            'pasajero_ids' => [$pasajeroA->id, $pasajeroB->id],
            'client_id' => $cliente->id,
            'tipo_comprobante_codigo' => '01',
        ]), (string) $reserva->id);

        $body = $response->getData(true);
        $this->assertSame(200, $body['code'], json_encode($body));

        $venta = Sale::find($body['sale_id']);
        // Ambos ítems son 'nacional' — no dispara el guardia de mezcla, y
        // hoy es lo único que puede llegar a facturarse (ver clase).
        $this->assertSame('nacional', $venta->destino);
        $this->assertSame(0, $venta->is_exportacion);

        $detalles = SaleDetail::where('sale_id', $venta->id)->orderBy('id')->get();
        // 2 líneas, no 1 — misma categoría (OTROS) pero tip_afe_igv distinto
        // no se puede fusionar sin perder el tratamiento correcto de cada una.
        $this->assertCount(2, $detalles);

        $gravado = $detalles->firstWhere('tip_afe_igv', '10');
        $exonerado = $detalles->firstWhere('tip_afe_igv', '20');
        $this->assertNotNull($gravado, 'debe existir una línea gravada');
        $this->assertNotNull($exonerado, 'debe existir una línea exonerada');

        $this->assertSame(18.0, (float) $gravado->porcentaje_igv);
        $this->assertSame(40.0, (float) $gravado->price_final);
        $subtotalEsperadoGravado = round(40 / 1.18, 2);
        $this->assertEqualsWithDelta($subtotalEsperadoGravado, (float) $gravado->subtotal, 0.01);
        $this->assertEqualsWithDelta(round(40 - $subtotalEsperadoGravado, 2), (float) $gravado->igv, 0.01);

        $this->assertSame(0.0, (float) $exonerado->porcentaje_igv);
        $this->assertSame(60.0, (float) $exonerado->price_final);
        $this->assertSame(60.0, (float) $exonerado->subtotal);
        $this->assertSame(0.0, (float) $exonerado->igv);

        // Sale-level: ya no todo va a mto_oper_gravadas — se reparte según
        // el tratamiento real de cada línea.
        $this->assertEqualsWithDelta($subtotalEsperadoGravado, (float) $venta->mto_oper_gravadas, 0.01);
        $this->assertSame(60.0, (float) $venta->mto_oper_exoneradas);
        $this->assertSame(0.0, (float) $venta->mto_oper_inafectas);
    }

    private function crearReservaConTratamientoTributarioMixtoMismoDestino(): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '99887766', 'full_name' => 'Cliente Test Mixto Mismo Destino',
            'type_client' => 1, 'cod_tipo_doc_sunat' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0700-' . uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Lima', 'fecha_viaje_desde' => '2026-09-01',
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
            'nombre' => 'Lima', 'tipo' => 'lugar', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Nombre que no matchea 'transporte'/'traslado'/'tour' en
        // clasificarCategoria() — ambos ítems caen en 'OTROS', a propósito
        // (necesito la MISMA categoría para probar la sub-agrupación por
        // tip_afe_igv, no la clasificación en sí).
        $servicioId = DB::table('servicios')->insertGetId([
            'nombre' => 'Entrada a museo', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $destinoServicioId = DB::table('destino_servicio')->insertGetId([
            'destino_atractivo_id' => $destinoAtractivoId, 'servicio_id' => $servicioId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorId = DB::table('proveedores')->insertGetId([
            'razon_social' => 'Operador Test Mixto SAC', 'estado' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorServicioId = DB::table('proveedor_servicios')->insertGetId([
            'proveedor_id' => $proveedorId, 'destino_servicio_id' => $destinoServicioId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Ambas 'nacional' — el guardia de mezcla (destino_tributario) no
        // se dispara. La diferencia está en tip_afe_igv, no en destino.
        $tarifaGravadaId = DB::table('proveedor_tarifas')->insertGetId([
            'proveedor_servicio_id' => $proveedorServicioId,
            'tipo_tarifa' => 'publica', 'modalidad' => 'compartido', 'moneda' => 'PEN',
            'precio_costo' => 20, 'margen_tipo' => 'fijo', 'margen_valor' => 20,
            'precio_venta_adulto' => 40,
            'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tarifaExoneradaId = DB::table('proveedor_tarifas')->insertGetId([
            'proveedor_servicio_id' => $proveedorServicioId,
            'tipo_tarifa' => 'publica', 'modalidad' => 'compartido', 'moneda' => 'PEN',
            'precio_costo' => 40, 'margen_tipo' => 'fijo', 'margen_valor' => 20,
            'precio_venta_adulto' => 60,
            'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '20', 'destino_tributario' => 'nacional',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $itemGravado = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor',
            'proveedor_tarifa_id' => $tarifaGravadaId, 'dia_referencial' => 1, 'pax_incluidos' => [$cpA],
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 20, 'precio_venta_snapshot' => 40, 'precio_convertido' => 40,
            'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ]);
        $itemExonerado = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor',
            'proveedor_tarifa_id' => $tarifaExoneradaId, 'dia_referencial' => 1, 'pax_incluidos' => [$cpB],
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 40, 'precio_venta_snapshot' => 60, 'precio_convertido' => 60,
            'tip_afe_igv' => '20', 'destino_tributario' => 'nacional',
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        $pasajeros = ReservaPasajero::where('reserva_id', $reserva->id)->orderBy('id')->get();

        return [
            $reserva,
            ReservaItem::where('alternativa_item_id', $itemGravado->id)->first(),
            ReservaItem::where('alternativa_item_id', $itemExonerado->id)->first(),
            $pasajeros[0],
            $pasajeros[1],
        ];
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
