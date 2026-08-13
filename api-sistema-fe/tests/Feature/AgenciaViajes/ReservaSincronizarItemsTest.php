<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// feature/reservas-destino-filtro-sync-vuelo, punto 3: agregar un servicio a
// la cotización DESPUÉS de que la reserva ya está activa nunca se reflejaba
// solo — Opción C acordada, no bloquear ni sincronizar automático, el staff
// dispara sincronizar-items() a demanda. Mismo patrón de infraestructura que
// ReservaFechaAutoCompletadaTest: Postgres real (sistemafe_test_migrations),
// transacción por test revertida.
class ReservaSincronizarItemsTest extends TestCase
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

    private function crearReservaActivaConUnItem(): Reserva
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '11223344', 'full_name' => 'Cliente Test Sync',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0100', 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-01',
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

        app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'manual',
            'descripcion_manual' => 'Ítem original',
            'moneda_costo' => 'PEN',
            'costo_snapshot' => 10,
            'precio_venta_snapshot' => 20,
            'cantidad' => 1,
        ]), (string) $alternativa->id);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        return $reserva;
    }

    public function test_sincronizar_crea_items_pendientes_y_los_refleja_en_la_reserva(): void
    {
        $reserva = $this->crearReservaActivaConUnItem();
        $this->assertSame(1, ReservaItem::where('reserva_id', $reserva->id)->count());

        // Servicio agregado a la cotización DESPUÉS de que la reserva ya
        // está activa — nunca se refleja solo (Opción C).
        $itemNuevo = AlternativaItem::create([
            'alternativa_id' => $reserva->alternativa_id,
            'origen_tipo' => 'manual',
            'descripcion_manual' => 'Ítem agregado después de aceptar',
            'modo_precio' => 'tarifa_fija',
            'cantidad' => 1,
            'moneda_costo' => 'PEN',
            'costo_snapshot' => 5,
            'precio_venta_snapshot' => 8,
            'precio_convertido' => 8,
        ]);

        $this->assertSame(1, ReservaItem::where('reserva_id', $reserva->id)->count());

        $response = app(ReservaController::class)->sincronizarItems((string) $reserva->id);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getData(true);
        $this->assertStringContainsString('1 ítem(s)', $body['message']);
        $this->assertSame([], $body['items_pendientes_sincronizar']);

        $this->assertSame(2, ReservaItem::where('reserva_id', $reserva->id)->count());
        $this->assertTrue(ReservaItem::where('reserva_id', $reserva->id)->where('alternativa_item_id', $itemNuevo->id)->exists());
    }

    public function test_sincronizar_rechaza_si_no_hay_items_pendientes(): void
    {
        $reserva = $this->crearReservaActivaConUnItem();

        $response = app(ReservaController::class)->sincronizarItems((string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('No hay ítems pendientes de sincronizar.', $response->getData(true)['message']);
    }

    public function test_sincronizar_rechaza_reserva_no_activa(): void
    {
        $reserva = $this->crearReservaActivaConUnItem();
        $reserva->update(['estado' => 'cancelada']);

        AlternativaItem::create([
            'alternativa_id' => $reserva->alternativa_id,
            'origen_tipo' => 'manual',
            'descripcion_manual' => 'Ítem nuevo, reserva ya cancelada',
            'modo_precio' => 'tarifa_fija',
            'cantidad' => 1,
            'moneda_costo' => 'PEN',
            'costo_snapshot' => 5,
            'precio_venta_snapshot' => 8,
            'precio_convertido' => 8,
        ]);

        $response = app(ReservaController::class)->sincronizarItems((string) $reserva->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Solo se puede sincronizar una reserva activa.', $response->getData(true)['message']);
    }

    public function test_respuesta_detalle_incluye_items_pendientes_sincronizar(): void
    {
        $reserva = $this->crearReservaActivaConUnItem();

        AlternativaItem::create([
            'alternativa_id' => $reserva->alternativa_id,
            'origen_tipo' => 'manual',
            'descripcion_manual' => 'Ítem pendiente',
            'modo_precio' => 'tarifa_fija',
            'cantidad' => 1,
            'moneda_costo' => 'PEN',
            'costo_snapshot' => 5,
            'precio_venta_snapshot' => 8,
            'precio_convertido' => 8,
        ]);

        $response = app(ReservaController::class)->show((string) $reserva->id);
        $body = $response->getData(true);

        $this->assertCount(1, $body['items_pendientes_sincronizar']);
        $this->assertSame('Ítem pendiente', $body['items_pendientes_sincronizar'][0]['nombre']);
    }
}
