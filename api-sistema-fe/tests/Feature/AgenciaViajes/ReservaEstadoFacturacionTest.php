<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaVenta;
use App\Models\Sale\Sale;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Estado de facturación REAL de una reserva (2026-09-22) — badge/filtro
// nuevo en el listado + comprobantes emitidos en el detalle. A propósito
// distinto de items_facturados_ids (ya existente, usado para no re-ofrecer
// un ítem "ocupado" por CUALQUIER Sale sin importar si se envió a SUNAT):
// acá solo un Sale ACEPTADO (n_operacion no nulo) cuenta como "facturado de
// verdad" — un comprobante en borrador o rechazado no debe verse como
// facturado ante el negocio. Mismo patrón de infraestructura que el resto
// del módulo: Postgres real (sistemafe_test_migrations), transacción por
// test revertida.
class ReservaEstadoFacturacionTest extends TestCase
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

        DB::table('roles')->insert([
            'id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function autenticarSuperAdmin(): void
    {
        $role = Role::firstOrCreate(['guard_name' => 'api', 'name' => 'Super-Admin']);
        $admin = User::factory()->create();
        $admin->assignRole($role);
        Auth::guard('api')->setUser($admin->fresh());
    }

    /** Reserva activa con 2 ítems manuales (sin cadena proveedor, mismo atajo que otros tests del módulo). */
    private function crearReservaConDosItems(string $codigo): Reserva
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '99887766', 'full_name' => 'Cliente Test Estado Facturación',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => $codigo, 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);

        AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 1,
            'descripcion_manual' => 'Ítem 1', 'modo_precio' => 'tarifa_fija', 'cantidad' => 1,
            'moneda_costo' => 'PEN', 'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'precio_convertido' => 15,
        ]);
        AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 1,
            'descripcion_manual' => 'Ítem 2', 'modo_precio' => 'tarifa_fija', 'cantidad' => 1,
            'moneda_costo' => 'PEN', 'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'precio_convertido' => 15,
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        return $reserva->fresh();
    }

    private function crearVenta(Reserva $reserva, array $reservaItemIds, array $saleOverrides = []): ReservaVenta
    {
        $sale = Sale::factory()->create($saleOverrides);

        return ReservaVenta::create([
            'reserva_id' => $reserva->id,
            'sale_id' => $sale->id,
            'reserva_item_ids' => $reservaItemIds,
            'reserva_pasajero_ids' => [],
        ]);
    }

    private function mostrarReserva(Reserva $reserva): array
    {
        $this->autenticarSuperAdmin();
        $response = app(ReservaController::class)->show((string) $reserva->id);

        return $response->getData(true);
    }

    // ── calcularEstadoFacturacion() vía show() ──────────────────────────

    public function test_pendiente_sin_ninguna_venta(): void
    {
        $reserva = $this->crearReservaConDosItems('TEST-2026-2001');

        $body = $this->mostrarReserva($reserva);

        $this->assertSame('pendiente', $body['estado_facturacion']);
        $this->assertSame([], $body['comprobantes']);
    }

    public function test_pendiente_con_sale_en_borrador_nunca_enviado_a_sunat(): void
    {
        $reserva = $this->crearReservaConDosItems('TEST-2026-2002');
        $items = ReservaItem::where('reserva_id', $reserva->id)->pluck('id')->all();

        // n_operacion=null, sunat_error_code=null: Sale creado, pero
        // enviarSunat() todavía no corrió (envío es un paso manual, ver
        // project_sunat_envio_manual en memoria).
        $this->crearVenta($reserva, $items, ['n_operacion' => null, 'sunat_error_code' => null]);

        $body = $this->mostrarReserva($reserva);

        $this->assertSame('pendiente', $body['estado_facturacion'], 'un comprobante en borrador no cuenta como facturado');
        $this->assertCount(1, $body['comprobantes']);
        $this->assertSame('pendiente_envio', $body['comprobantes'][0]['estado_sunat']);
    }

    public function test_pendiente_con_sale_rechazado_por_sunat(): void
    {
        $reserva = $this->crearReservaConDosItems('TEST-2026-2003');
        $items = ReservaItem::where('reserva_id', $reserva->id)->pluck('id')->all();

        $this->crearVenta($reserva, $items, [
            'n_operacion' => null, 'sunat_error_code' => '2324', 'sunat_error_message' => 'RUC inválido',
        ]);

        $body = $this->mostrarReserva($reserva);

        $this->assertSame('pendiente', $body['estado_facturacion'], 'un comprobante rechazado no cuenta como facturado');
        $this->assertSame('rechazado', $body['comprobantes'][0]['estado_sunat']);
        $this->assertSame('RUC inválido', $body['comprobantes'][0]['sunat_error_message']);
    }

    public function test_parcial_cuando_un_sale_aceptado_cubre_solo_un_item(): void
    {
        $reserva = $this->crearReservaConDosItems('TEST-2026-2004');
        $items = ReservaItem::where('reserva_id', $reserva->id)->pluck('id')->all();

        // Sale::factory() por default YA simula una venta aceptada
        // (n_operacion poblado) — ver ReservarCorrelativoTest.
        $this->crearVenta($reserva, [$items[0]]);

        $body = $this->mostrarReserva($reserva);

        $this->assertSame('parcial', $body['estado_facturacion']);
        $this->assertSame('aceptado', $body['comprobantes'][0]['estado_sunat']);
    }

    public function test_total_cuando_un_sale_aceptado_cubre_todos_los_items(): void
    {
        $reserva = $this->crearReservaConDosItems('TEST-2026-2005');
        $items = ReservaItem::where('reserva_id', $reserva->id)->pluck('id')->all();

        $this->crearVenta($reserva, $items);

        $body = $this->mostrarReserva($reserva);

        $this->assertSame('total', $body['estado_facturacion']);
    }

    public function test_total_cuando_dos_sales_aceptados_cubren_los_items_entre_ambos(): void
    {
        $reserva = $this->crearReservaConDosItems('TEST-2026-2006');
        $items = ReservaItem::where('reserva_id', $reserva->id)->pluck('id')->all();

        // Facturación múltiple: 2 Sale reales para la misma reserva, cada
        // uno cubre un subconjunto — confirmado que el proyecto soporta
        // esto (ReservaFacturacionController, "Facturación múltiple").
        $this->crearVenta($reserva, [$items[0]]);
        $this->crearVenta($reserva, [$items[1]]);

        $body = $this->mostrarReserva($reserva);

        $this->assertSame('total', $body['estado_facturacion']);
        $this->assertCount(2, $body['comprobantes']);
    }

    public function test_facturacion_externa_tiene_precedencia_sobre_cualquier_venta(): void
    {
        $reserva = $this->crearReservaConDosItems('TEST-2026-2007');
        $reserva->update(['facturacion_externa' => true]);

        $body = $this->mostrarReserva($reserva->fresh());

        $this->assertSame('facturacion_externa', $body['estado_facturacion']);
    }

    // ── index() — filtro + campo por fila ───────────────────────────────

    public function test_index_incluye_estado_facturacion_en_cada_fila_sin_filtrar(): void
    {
        $pendiente = $this->crearReservaConDosItems('TEST-2026-2008');
        $total = $this->crearReservaConDosItems('TEST-2026-2009');
        $this->crearVenta($total, ReservaItem::where('reserva_id', $total->id)->pluck('id')->all());

        $response = app(ReservaController::class)->index(new Request());
        $body = $response->getData(true);

        $porId = collect($body['reservas'])->keyBy('id');
        $this->assertSame('pendiente', $porId[$pendiente->id]['estado_facturacion']);
        $this->assertSame('total', $porId[$total->id]['estado_facturacion']);
    }

    public function test_index_filtra_por_estado_facturacion(): void
    {
        $pendiente = $this->crearReservaConDosItems('TEST-2026-2010');
        $total = $this->crearReservaConDosItems('TEST-2026-2011');
        $this->crearVenta($total, ReservaItem::where('reserva_id', $total->id)->pluck('id')->all());
        $parcial = $this->crearReservaConDosItems('TEST-2026-2012');
        $this->crearVenta($parcial, [ReservaItem::where('reserva_id', $parcial->id)->pluck('id')->first()]);
        $externa = $this->crearReservaConDosItems('TEST-2026-2013');
        $externa->update(['facturacion_externa' => true]);

        $responsePendiente = app(ReservaController::class)->index(new Request(['estado_facturacion' => 'pendiente']));
        $idsPendiente = collect($responsePendiente->getData(true)['reservas'])->pluck('id');
        $this->assertTrue($idsPendiente->contains($pendiente->id));
        $this->assertFalse($idsPendiente->contains($total->id));
        $this->assertFalse($idsPendiente->contains($parcial->id));
        $this->assertFalse($idsPendiente->contains($externa->id));

        $responseTotal = app(ReservaController::class)->index(new Request(['estado_facturacion' => 'total']));
        $idsTotal = collect($responseTotal->getData(true)['reservas'])->pluck('id');
        $this->assertSame([$total->id], $idsTotal->all());

        $responseParcial = app(ReservaController::class)->index(new Request(['estado_facturacion' => 'parcial']));
        $idsParcial = collect($responseParcial->getData(true)['reservas'])->pluck('id');
        $this->assertSame([$parcial->id], $idsParcial->all());

        $responseExterna = app(ReservaController::class)->index(new Request(['estado_facturacion' => 'facturacion_externa']));
        $idsExterna = collect($responseExterna->getData(true)['reservas'])->pluck('id');
        $this->assertSame([$externa->id], $idsExterna->all());

        // El total paginado refleja el conteo YA filtrado, no el de todas las reservas.
        $this->assertSame(1, $responseTotal->getData(true)['total']);
    }
}
