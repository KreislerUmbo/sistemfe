<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaFacturacionController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
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
// real. Mismo patrón de fixture de serie/permiso que
// SaleControllerSerieComprobanteTest, combinado con el patrón de reserva ya
// usado en ReservaReprogramarTest. Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
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
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    private function usuarioConPermiso(int $branchId, ?string $permiso): User
    {
        $user = User::factory()->create(['branch_id' => $branchId]);

        $role = Role::create(['name' => 'rol-test-' . uniqid(), 'guard_name' => 'api']);
        if ($permiso) {
            $permission = Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'api']);
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

    /**
     * Reserva con 3 ítems: uno HOTEL (tipo_habitacion presente), uno
     * TRANSPORTE (nombre de servicio "Traslado..."), uno sin ninguna señal
     * clara → cae en OTROS. fecha_viaje_desde = 2026-09-01.
     */
    private function crearReservaConItemsDeVariasCategorias(): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '77889900', 'full_name' => 'Cliente Test Facturación',
            'type_client' => 1, 'cod_tipo_doc_sunat' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0400-' . uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);

        // ── Fixture de HOTEL (tipo_habitacion presente en proveedor_tarifa) ──
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
        $proveedorHotelId = DB::table('proveedores')->insertGetId([
            'razon_social' => 'Hotel Test SAC', 'estado' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorServicioHotelId = DB::table('proveedor_servicios')->insertGetId([
            'proveedor_id' => $proveedorHotelId, 'destino_servicio_id' => $destinoServicioHotelId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tarifaHotelId = DB::table('proveedor_tarifas')->insertGetId([
            'proveedor_servicio_id' => $proveedorServicioHotelId,
            'tipo_tarifa' => 'publica', 'modalidad' => 'privado', 'moneda' => 'PEN',
            'precio_costo' => 80, 'margen_tipo' => 'fijo', 'margen_valor' => 20,
            'precio_venta_adulto' => 100, 'tipo_habitacion' => 'doble',
            'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // ── Fixture de TRANSPORTE (nombre de servicio "Traslado...") ──
        $servicioTransporteId = DB::table('servicios')->insertGetId([
            'nombre' => 'Traslado Ida y Vuelta', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $destinoServicioTransporteId = DB::table('destino_servicio')->insertGetId([
            'destino_atractivo_id' => $destinoAtractivoId, 'servicio_id' => $servicioTransporteId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorServicioTransporteId = DB::table('proveedor_servicios')->insertGetId([
            'proveedor_id' => $proveedorHotelId, 'destino_servicio_id' => $destinoServicioTransporteId,
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

        $itemHotel = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor',
            'proveedor_tarifa_id' => $tarifaHotelId, 'dia_referencial' => 1,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 80, 'precio_venta_snapshot' => 100, 'precio_convertido' => 100,
        ]);
        $itemTransporte = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor',
            'proveedor_tarifa_id' => $tarifaTransporteId, 'dia_referencial' => 1,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 30, 'precio_venta_snapshot' => 40, 'precio_convertido' => 40,
        ]);
        $itemOtros = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 1,
            'descripcion_manual' => 'Entrada a museo', 'modo_precio' => 'tarifa_fija', 'cantidad' => 1,
            'moneda_costo' => 'PEN', 'costo_snapshot' => 10, 'precio_venta_snapshot' => 20, 'precio_convertido' => 20,
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        return [
            $reserva,
            ReservaItem::where('alternativa_item_id', $itemHotel->id)->first(),
            ReservaItem::where('alternativa_item_id', $itemTransporte->id)->first(),
            ReservaItem::where('alternativa_item_id', $itemOtros->id)->first(),
        ];
    }

    /**
     * Reserva con 2 ítems de proveedor con destino_tributario distinto
     * ('amazonia' vs 'nacional') — el caso real que el guardia tributario
     * (2026-08-20) debe bloquear: un tour local exonerado Amazonía +
     * un traslado nacional gravado no pueden ir en el mismo Sale.
     */
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
            'proveedor_tarifa_id' => $tarifaAmazoniaId, 'dia_referencial' => 1,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 50, 'precio_venta_snapshot' => 60, 'precio_convertido' => 60,
        ]);
        $itemNacional = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'proveedor',
            'proveedor_tarifa_id' => $tarifaNacionalId, 'dia_referencial' => 1,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 30, 'precio_venta_snapshot' => 40, 'precio_convertido' => 40,
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        return [
            $reserva,
            ReservaItem::where('alternativa_item_id', $itemAmazonia->id)->first(),
            ReservaItem::where('alternativa_item_id', $itemNacional->id)->first(),
        ];
    }

    public function test_bloquea_facturar_reserva_con_destino_tributario_mezclado(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermiso($branch->id, 'emitir_factura');

        [$reserva, $itemAmazonia, $itemNacional] = $this->crearReservaConMezclaTributaria();

        // Guardia real: server-side en POST facturar, sin pasar por
        // prepararFactura() — nunca confía en que el frontend ya filtró.
        $response = app(ReservaFacturacionController::class)->store(new Request([
            'reserva_item_ids' => [$itemAmazonia->id, $itemNacional->id],
            'tipo_comprobante_codigo' => '01',
        ]), (string) $reserva->id);

        $body = $response->getData(true);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertTrue($body['bloqueado_tributario']);
        $this->assertEqualsCanonicalizing(['amazonia', 'nacional'], $body['destinos_tributarios_detectados']);
        $this->assertStringContainsString('tratamiento tributario', $body['motivo']);
        $this->assertSame(0, Sale::count(), 'no debe crear nada a medias');

        // Preview: mismo bloqueo, sin necesidad de intentar el POST.
        $preview = app(ReservaFacturacionController::class)->prepararFactura(new Request([
            'reserva_item_ids' => [$itemAmazonia->id, $itemNacional->id],
        ]), (string) $reserva->id);
        $previewBody = $preview->getData(true);
        $this->assertSame(200, $preview->getStatusCode());
        $this->assertTrue($previewBody['bloqueado_tributario']);
        $this->assertArrayNotHasKey('grupos_propuestos', $previewBody);
    }

    public function test_preparar_factura_sin_mezcla_devuelve_desglose(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermiso($branch->id, 'emitir_factura');

        [$reserva, $itemHotel, $itemTransporte, $itemOtros] = $this->crearReservaConItemsDeVariasCategorias();

        $preview = app(ReservaFacturacionController::class)->prepararFactura(new Request([
            'reserva_item_ids' => [$itemHotel->id, $itemTransporte->id, $itemOtros->id],
        ]), (string) $reserva->id);

        $body = $preview->getData(true);
        $this->assertSame(200, $preview->getStatusCode());
        $this->assertFalse($body['bloqueado_tributario']);
        $this->assertSame(3, count($body['grupos_propuestos']));
        $this->assertSame(160.0, (float) $body['total'], '100 + 40 + 20, igual que el store real');
        $this->assertSame(0, Sale::count(), 'preview no persiste nada');
    }

    public function test_factura_reserva_agrupando_por_categoria(): void
    {
        [$branch, $serie] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermiso($branch->id, 'emitir_factura');

        [$reserva, $itemHotel, $itemTransporte, $itemOtros] = $this->crearReservaConItemsDeVariasCategorias();

        $response = app(ReservaFacturacionController::class)->store(new Request([
            'reserva_item_ids' => [$itemHotel->id, $itemTransporte->id, $itemOtros->id],
            'tipo_comprobante_codigo' => '01',
        ]), (string) $reserva->id);

        $body = $response->getData(true);
        $this->assertSame(200, $body['code']);
        $this->assertSame(3, $body['lineas'], '3 categorías distintas -> 3 líneas');

        $venta = Sale::find($body['sale_id']);
        $this->assertNotNull($venta);
        $this->assertSame('sale', $venta->type);
        $this->assertSame('01', $venta->tipo_comprobante_codigo);
        $this->assertSame($serie->id, $venta->serie_comprobante_id);
        $this->assertSame('F001', $venta->serie);
        $this->assertNull($venta->correlativo, 'fiscal: el correlativo se reserva recién al enviar a SUNAT');
        $this->assertSame(1, $venta->state_payment, 'pendiente de cobro');
        $this->assertSame(0.0, (float) $venta->paid_out);
        $this->assertSame(160.0, (float) $venta->total, '100 + 40 + 20');
        $this->assertSame((float) $venta->total, (float) $venta->debt);
        $this->assertSame((float) $venta->total, (float) $venta->saldo_pendiente);
        $this->assertSame('PEN', $venta->currency);

        $this->assertSame(3, $venta->sale_details()->count());

        $detalleHotel = $venta->sale_details()->whereHas('product', fn ($q) => $q->where('sku', 'SERVICIO-HOTEL'))->first();
        $this->assertNotNull($detalleHotel);
        $this->assertSame(100.0, (float) $detalleHotel->price_final);
        $this->assertStringContainsString('Hotel Test SAC', $detalleHotel->descripcion_detalle);

        // sale_detail_items: trazabilidad 1 fila por reserva_item facturado.
        $this->assertSame(1, SaleDetailItem::where('sale_detail_id', $detalleHotel->id)->where('reserva_item_id', $itemHotel->id)->count());

        $reservaVenta = ReservaVenta::where('reserva_id', $reserva->id)->first();
        $this->assertNotNull($reservaVenta);
        $this->assertSame($venta->id, $reservaVenta->sale_id);
        $this->assertEqualsCanonicalizing(
            [$itemHotel->id, $itemTransporte->id, $itemOtros->id],
            $reservaVenta->reserva_item_ids
        );
    }

    public function test_rechaza_reserva_no_activa(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermiso($branch->id, 'emitir_factura');

        [$reserva, $itemHotel] = $this->crearReservaConItemsDeVariasCategorias();
        $reserva->update(['estado' => 'cancelada']);

        $response = app(ReservaFacturacionController::class)->store(new Request([
            'reserva_item_ids' => [$itemHotel->id],
            'tipo_comprobante_codigo' => '01',
        ]), (string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Solo se puede facturar una reserva activa.', $response->getData(true)['message']);
        $this->assertSame(0, Sale::count());
    }

    public function test_rechaza_item_que_no_pertenece_a_la_reserva(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermiso($branch->id, 'emitir_factura');

        [$reservaA, $itemDeA] = $this->crearReservaConItemsDeVariasCategorias();
        [$reservaB, ] = $this->crearReservaConItemsDeVariasCategorias();

        $response = app(ReservaFacturacionController::class)->store(new Request([
            'reserva_item_ids' => [$itemDeA->id],
            'tipo_comprobante_codigo' => '01',
        ]), (string) $reservaB->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, Sale::count());
    }

    public function test_rechaza_doble_facturacion_del_mismo_item(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermiso($branch->id, 'emitir_factura');

        [$reserva, $itemHotel, $itemTransporte] = $this->crearReservaConItemsDeVariasCategorias();

        app(ReservaFacturacionController::class)->store(new Request([
            'reserva_item_ids' => [$itemHotel->id],
            'tipo_comprobante_codigo' => '01',
        ]), (string) $reserva->id);

        $this->assertSame(1, Sale::count());

        // Intenta re-facturar el mismo ítem, mezclado con uno nuevo.
        $response = app(ReservaFacturacionController::class)->store(new Request([
            'reserva_item_ids' => [$itemHotel->id, $itemTransporte->id],
            'tipo_comprobante_codigo' => '01',
        ]), (string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString((string) $itemHotel->id, $response->getData(true)['message']);
        // No se creó una segunda venta a medias.
        $this->assertSame(1, Sale::count());
    }

    public function test_rechaza_sin_permiso_de_emision(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermiso($branch->id, null); // sin ningún permiso de emisión

        [$reserva, $itemHotel] = $this->crearReservaConItemsDeVariasCategorias();

        try {
            app(ReservaFacturacionController::class)->store(new Request([
                'reserva_item_ids' => [$itemHotel->id],
                'tipo_comprobante_codigo' => '01',
            ]), (string) $reserva->id);
            $this->fail('Se esperaba HttpException 403, no se lanzó ninguna.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, Sale::count());
    }

    public function test_permite_elegir_un_cliente_distinto_al_de_la_cotizacion(): void
    {
        [$branch, ] = $this->branchConSerie('01', 'F001');
        $this->usuarioConPermiso($branch->id, 'emitir_factura');

        [$reserva, $itemHotel] = $this->crearReservaConItemsDeVariasCategorias();
        $coordinador = Client::factory()->create();

        $response = app(ReservaFacturacionController::class)->store(new Request([
            'reserva_item_ids' => [$itemHotel->id],
            'tipo_comprobante_codigo' => '01',
            'client_id' => $coordinador->id,
        ]), (string) $reserva->id);

        $body = $response->getData(true);
        $this->assertSame(200, $body['code']);
        $this->assertSame($coordinador->id, Sale::find($body['sale_id'])->client_id);
    }
}
