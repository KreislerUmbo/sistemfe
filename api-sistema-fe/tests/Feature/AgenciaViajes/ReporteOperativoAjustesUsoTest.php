<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReporteOperativoController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaItemPasajeroController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaItemCheckin;
use App\Models\AgenciaViajes\ReservaPasajero;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// T-058 — ajustes de uso del reporte operativo: búsqueda q, rango validado (422) y
// "Check-in a todos". Mismo patrón de infraestructura que ReporteOperativoCheckinTest.
class ReporteOperativoAjustesUsoTest extends TestCase
{
    use ConAccesoATodasLasReservas;

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

        $this->verTodasLasReservas();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    /** Reserva con un servicio "para todos" el 10-sep-2026 y un pasajero por nombre dado. */
    private function crearReserva(array $nombres): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10_000_000, 99_999_999), 'full_name' => 'Cliente Ajustes',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-' . uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-09-10', 'fecha_viaje_hasta' => '2026-09-10',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa ajustes', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
        foreach ($nombres as $_) {
            CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 30]);
        }
        $itemManual = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 1,
            'descripcion_manual' => 'Tour city ajustes',
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 20, 'precio_venta_snapshot' => 30, 'precio_convertido' => 30,
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());
        $item = ReservaItem::where('alternativa_item_id', $itemManual->id)->firstOrFail();

        $pasajeros = ReservaPasajero::where('reserva_id', $reserva->id)->orderBy('id')->get();
        foreach ($pasajeros as $i => $p) {
            $p->update(['nombre' => $nombres[$i], 'documento' => '7000000' . $i]);
        }

        return ['reserva' => $reserva, 'item' => $item, 'pasajeros' => $pasajeros->fresh()];
    }

    private function reporte(array $query): array
    {
        return app(ReporteOperativoController::class)->index(new Request($query + [
            'fecha_desde' => '2026-09-10', 'fecha_hasta' => '2026-09-10',
        ]))->getData(true);
    }

    private function nombres(array $body, int $itemId): array
    {
        return collect($body['filas'])->where('reserva_item_id', $itemId)->pluck('pasajero.nombre')->sort()->values()->all();
    }

    // ── CA1: búsqueda ────────────────────────────────────────────────────────

    public function test_ca1_q_por_nombre_documento_y_codigo_de_reserva(): void
    {
        $a = $this->crearReserva(['María Quispe', 'Juan Pérez']);
        $b = $this->crearReserva(['Carlos Ruiz']);

        // Nombre parcial, sin tildes ni mayúsculas.
        $this->assertSame(['María Quispe'], $this->nombres($this->reporte(['q' => 'MARIA']), $a['item']->id));
        $this->assertSame([], $this->nombres($this->reporte(['q' => 'maria']), $b['item']->id));

        // Documento.
        $this->assertSame(['Juan Pérez'], $this->nombres($this->reporte(['q' => '70000001']), $a['item']->id));

        // Código de reserva: trae a todos sus pasajeros y a ninguno de la otra.
        $filaB = collect($this->reporte([])['filas'])->firstWhere('reserva_item_id', $b['item']->id);
        $this->assertNotEmpty($filaB['codigo_reserva']);
        $body = $this->reporte(['q' => $filaB['codigo_reserva']]);
        $this->assertSame(['Carlos Ruiz'], $this->nombres($body, $b['item']->id));
        $this->assertSame([], $this->nombres($body, $a['item']->id));

        // Sin coincidencias: vacío.
        $this->assertSame(0, $this->reporte(['q' => 'zzzz-no-existe'])['total_items']);
    }

    public function test_q_de_mas_de_100_caracteres_da_422(): void
    {
        $this->expectException(ValidationException::class);
        $this->reporte(['q' => str_repeat('a', 101)]);
    }

    // ── CA2: rango validado ──────────────────────────────────────────────────

    public function test_ca2_rango_de_40_dias_fecha_invalida_o_hasta_antes_de_desde_dan_422_en_todos_los_endpoints(): void
    {
        $casos = [
            'rango largo' => ['fecha_desde' => '2026-09-01', 'fecha_hasta' => '2026-10-10'],
            'fecha inválida' => ['fecha_desde' => 'no-es-fecha', 'fecha_hasta' => '2026-10-10'],
            'hasta antes de desde' => ['fecha_desde' => '2026-09-10', 'fecha_hasta' => '2026-09-01'],
        ];
        $c = app(ReporteOperativoController::class);

        foreach ($casos as $nombre => $query) {
            foreach (['index', 'pdfSignedUrl', 'export', 'filtrosDisponibles'] as $accion) {
                try {
                    $c->{$accion}(new Request($query));
                    $this->fail("$accion con $nombre no dio 422");
                } catch (ValidationException $e) {
                    $this->assertNotEmpty($e->validator->errors()->first(), "$accion / $nombre sin mensaje");
                }
            }
        }
    }

    public function test_rango_de_31_dias_es_valido_y_32_no(): void
    {
        $this->assertSame(200, app(ReporteOperativoController::class)
            ->index(new Request(['fecha_desde' => '2026-09-01', 'fecha_hasta' => '2026-10-01']))->getData(true)['code']);

        $this->expectException(ValidationException::class);
        app(ReporteOperativoController::class)
            ->index(new Request(['fecha_desde' => '2026-09-01', 'fecha_hasta' => '2026-10-02']));
    }

    public function test_filtros_disponibles_devuelve_las_opciones_del_rango(): void
    {
        $this->crearReserva(['Ana']);

        $body = app(ReporteOperativoController::class)
            ->filtrosDisponibles(new Request(['fecha_desde' => '2026-09-10', 'fecha_hasta' => '2026-09-10']))
            ->getData(true);

        $this->assertSame(200, $body['code']);
        foreach (['destinos', 'servicios', 'tours', 'hoteles'] as $clave) {
            $this->assertIsArray($body[$clave]);
        }
    }

    // ── CA3: Check-in a todos ────────────────────────────────────────────────

    private function masivo(array $pares): array
    {
        return app(ReservaItemPasajeroController::class)->checkinMasivo(new Request(['checkins' => $pares]))->getData(true);
    }

    public function test_ca3_checkin_a_todos_marca_los_4_pasajeros(): void
    {
        $f = $this->crearReserva(['Uno', 'Dos', 'Tres', 'Cuatro']);
        // Uno ya tenía check-in: se conserva, no se duplica.
        ReservaItemCheckin::create([
            'reserva_item_id' => $f['item']->id, 'reserva_pasajero_id' => $f['pasajeros'][0]->id, 'checkin_hora' => now(),
        ]);

        $body = $this->masivo($f['pasajeros']->map(fn ($p) => [
            'reserva_item_id' => $f['item']->id, 'reserva_pasajero_id' => $p->id,
        ])->all());

        $this->assertSame(200, $body['code']);
        $this->assertSame(4, $body['marcados']);
        $this->assertSame(0, $body['omitidos']);
        $this->assertSame(4, ReservaItemCheckin::where('reserva_item_id', $f['item']->id)->count());
    }

    public function test_checkin_masivo_omite_pasajero_ajeno_a_la_reserva(): void
    {
        $a = $this->crearReserva(['Uno']);
        $b = $this->crearReserva(['Otro']);

        $body = $this->masivo([
            ['reserva_item_id' => $a['item']->id, 'reserva_pasajero_id' => $a['pasajeros'][0]->id],
            ['reserva_item_id' => $a['item']->id, 'reserva_pasajero_id' => $b['pasajeros'][0]->id],
        ]);

        $this->assertSame(1, $body['marcados']);
        $this->assertSame(1, $body['omitidos']);
    }

    public function test_checkin_masivo_sin_permiso_sobre_una_reserva_no_marca_nada(): void
    {
        $a = $this->crearReserva(['Uno']);
        $b = $this->crearReserva(['Dos']);

        // Usuario sin reservas.ver_todas ni dueño de ninguna cotización.
        $sinAcceso = User::factory()->create([
            'role_id' => Role::create(['name' => 'rol-sin-acceso-' . uniqid(), 'guard_name' => 'api'])->id,
        ]);
        Auth::guard('api')->setUser($sinAcceso);

        try {
            $this->masivo([
                ['reserva_item_id' => $a['item']->id, 'reserva_pasajero_id' => $a['pasajeros'][0]->id],
                ['reserva_item_id' => $b['item']->id, 'reserva_pasajero_id' => $b['pasajeros'][0]->id],
            ]);
            $this->fail('Debió negar el acceso.');
        } catch (AuthorizationException $e) {
            $this->assertSame(0, ReservaItemCheckin::count());
        }
    }

    public function test_checkin_masivo_pide_la_lista(): void
    {
        $r = app(ReservaItemPasajeroController::class)->checkinMasivo(new Request([]));

        $this->assertSame(422, $r->getStatusCode());
    }
}
