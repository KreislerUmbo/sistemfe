<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReporteOperativoController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\SalidaOperativa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Hallazgo real del usuario: un ítem origen_tipo='guia' puede estar enganchado a una
// Salida Operativa (tablero de despacho, SalidaOperativaController::attachReservaItem())
// — ahí el guía real/compartido es el de la salida, NUNCA el guia_id propio del ítem
// (reservas/detalle.vue ya distingue esto). El reporte operativo no lo consideraba en
// absoluto: mostraba/permitía editar el guia_id propio del ítem sin importar si estaba
// enganchado, lo que podía mostrar "sin asignar" con un guía real ya puesto en la
// salida, o crear un segundo guía desincronizado si se "corregía" desde el reporte.
// Mismo patrón de infraestructura que SalidaOperativaTest/ReporteOperativoTest:
// Postgres real (sistemafe_test_migrations), transacción por test revertida.
class ReporteOperativoSalidaOperativaTest extends TestCase
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
     * Reserva con UN ítem origen_tipo='guia'. Los ítems de guía NUNCA se auto-enganchan
     * a una salida (confirmado en SalidaOperativaTest — solo origen_tipo=proveedor con
     * modalidad=compartido) — el enganche manual (attachReservaItem()) se simula acá
     * seteando salida_operativa_id directo, que es el efecto real de ese endpoint.
     */
    private function crearReservaConItemGuia(): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '11223344', 'full_name' => 'Cliente Test Salida Guia',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-06' . uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-20', 'fecha_viaje_hasta' => '2026-09-20',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId,
            'nombre' => 'Alternativa salida-guia',
            'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN',
            'tipo_cambio_aplicado' => 1,
            'tipo_cambio_origen' => 'dia',
        ]);

        CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 30]);

        $tour = PaquetePlantilla::create([
            'categoria' => 'local', 'nombre' => 'Tour Salida Guia', 'codigo' => 'TSG-' . uniqid(),
            'destino_atractivo_id' => DB::table('destinos_atractivos')->insertGetId([
                'nombre' => 'Zona Test Salida', 'tipo' => 'zona', 'created_at' => now(), 'updated_at' => now(),
            ]),
            'duracion_horas' => 4,
        ]);

        $itemGuia = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => AlternativaItem::ORIGEN_GUIA,
            'tour_origen_id' => $tour->id, 'dia_referencial' => 1,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 20, 'precio_venta_snapshot' => 30, 'precio_convertido' => 30,
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());
        $reservaItem = ReservaItem::where('alternativa_item_id', $itemGuia->id)->firstOrFail();

        return ['reserva' => $reserva, 'item' => $reservaItem];
    }

    public function test_item_guia_sin_salida_muestra_su_propio_guia_id(): void
    {
        $f = $this->crearReservaConItemGuia();
        $guiaPropio = Guia::create(['nombre' => 'Guía Propio', 'documento' => '55555555', 'telefono' => '900000001']);
        $f['item']->update(['guia_id' => $guiaPropio->id]);

        $body = app(ReporteOperativoController::class)->index(new Request([
            'fecha_desde' => '2026-09-20', 'fecha_hasta' => '2026-09-20',
        ]))->getData(true);

        $fila = collect($body['filas'])->firstWhere('reserva_item_id', $f['item']->id);
        $this->assertNull($fila['salida_operativa_id']);
        $this->assertSame('Guía Propio', $fila['guia']['nombre']);
    }

    public function test_item_guia_enganchado_a_salida_muestra_el_guia_de_la_salida_no_el_propio(): void
    {
        $f = $this->crearReservaConItemGuia();

        // Guía propio del ítem (NUNCA debe verse) vs. guía real de la salida
        // (compartido con otras reservas, DEBE verse).
        $guiaPropio = Guia::create(['nombre' => 'Guía Huérfano Del Item', 'documento' => '66666666', 'telefono' => '900000002']);
        $guiaSalida = Guia::create(['nombre' => 'Guía Real De La Salida', 'documento' => '77777777', 'telefono' => '900000003']);
        $f['item']->update(['guia_id' => $guiaPropio->id]);

        $salida = SalidaOperativa::create([
            'tour_origen_id' => $f['item']->tour_origen_id, 'fecha' => '2026-09-20',
            'guia_id' => $guiaSalida->id, 'estado' => 'activa',
            'vehiculo_descripcion' => 'Camioneta ABC-123',
        ]);
        $f['item']->update(['salida_operativa_id' => $salida->id]);

        $body = app(ReporteOperativoController::class)->index(new Request([
            'fecha_desde' => '2026-09-20', 'fecha_hasta' => '2026-09-20',
        ]))->getData(true);

        $fila = collect($body['filas'])->firstWhere('reserva_item_id', $f['item']->id);
        $this->assertSame($salida->id, $fila['salida_operativa_id']);
        $this->assertSame('Guía Real De La Salida', $fila['guia']['nombre']);
        $this->assertNotSame('Guía Huérfano Del Item', $fila['guia']['nombre']);
        $this->assertSame('Camioneta ABC-123', $fila['salida_vehiculo']);
        $this->assertFalse($fila['sin_guia'], 'la salida ya tiene guía real (no referencial) asignado');
    }

    public function test_item_guia_enganchado_a_salida_sin_guia_propio_de_la_salida_queda_sin_asignar(): void
    {
        $f = $this->crearReservaConItemGuia();

        $salida = SalidaOperativa::create([
            'tour_origen_id' => $f['item']->tour_origen_id, 'fecha' => '2026-09-20', 'estado' => 'activa',
        ]);
        $f['item']->update(['salida_operativa_id' => $salida->id]);

        $body = app(ReporteOperativoController::class)->index(new Request([
            'fecha_desde' => '2026-09-20', 'fecha_hasta' => '2026-09-20',
        ]))->getData(true);

        $fila = collect($body['filas'])->firstWhere('reserva_item_id', $f['item']->id);
        $this->assertNull($fila['guia']);
        $this->assertTrue($fila['sin_guia']);
    }
}
