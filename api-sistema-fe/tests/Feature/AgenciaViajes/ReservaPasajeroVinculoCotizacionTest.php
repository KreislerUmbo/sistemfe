<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaItemPasajero;
use App\Models\AgenciaViajes\ReservaPasajero;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// F4 etapa 1 (23-oct-2026): "sincronizar servicios" relacionaba los
// pasajeros de la cotización con los de la reserva por su POSICIÓN (1.º con
// 1.º…). Desde que se puede quitar un pasajero en la reserva, eso asigna los
// servicios a la persona equivocada. Ahora cada pasajero de la reserva guarda
// de qué pasajero de la cotización viene (cotizacion_pasajero_id).
class ReservaPasajeroVinculoCotizacionTest extends TestCase
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

    // Cotización con Ana, Beto y Carla; reserva aceptada con un traslado para todos.
    private function reservaConTresPasajeros(): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '55667788', 'full_name' => 'Cliente Vinculo',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-VIN-'.uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-11-01', 'fecha_viaje_hasta' => '2026-11-03',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ana = CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 30]);
        $beto = CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 31]);
        $carla = CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 32]);

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
        AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 1,
            'descripcion_manual' => 'Traslado', 'modo_precio' => 'tarifa_fija', 'cantidad' => 1,
            'moneda_costo' => 'PEN', 'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'precio_convertido' => 15,
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        return [$reserva, $alternativa, $ana, $beto, $carla];
    }

    public function test_cada_pasajero_de_la_reserva_sabe_de_que_pasajero_de_la_cotizacion_viene(): void
    {
        [$reserva, , $ana, $beto, $carla] = $this->reservaConTresPasajeros();

        $this->assertEqualsCanonicalizing(
            [$ana->id, $beto->id, $carla->id],
            ReservaPasajero::where('reserva_id', $reserva->id)->pluck('cotizacion_pasajero_id')->all()
        );
    }

    public function test_sincronizar_tras_quitar_un_pasajero_asigna_el_servicio_a_la_persona_correcta(): void
    {
        [$reserva, $alternativa, , , $carla] = $this->reservaConTresPasajeros();

        // Se quita a Beto (2.º). Así quedaron reservas reales antes del bloqueo de la etapa 1.
        ReservaPasajero::where('reserva_id', $reserva->id)->orderBy('id')->skip(1)->first()->delete();
        $reservaCarla = ReservaPasajero::where('reserva_id', $reserva->id)->orderBy('id')->get()->last();

        // Después de aceptar, se agrega en la cotización un servicio solo para Carla.
        $soloCarla = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 2,
            'descripcion_manual' => 'Masaje de Carla', 'modo_precio' => 'tarifa_fija', 'cantidad' => 1,
            'moneda_costo' => 'PEN', 'costo_snapshot' => 40, 'precio_venta_snapshot' => 60, 'precio_convertido' => 60,
            'pax_incluidos' => [$carla->id],
        ]);

        $respuesta = app(ReservaController::class)->sincronizarItems((string) $reserva->id);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());

        $item = ReservaItem::where('alternativa_item_id', $soloCarla->id)->firstOrFail();
        $this->assertSame(
            [$reservaCarla->id],
            ReservaItemPasajero::where('reserva_item_id', $item->id)->pluck('reserva_pasajero_id')->all(),
            'El masaje debe quedar asignado a Carla, no a otra persona ni "a todos".'
        );
    }

    public function test_un_servicio_solo_para_el_pasajero_quitado_no_entra_a_la_reserva(): void
    {
        [$reserva, $alternativa, , $beto] = $this->reservaConTresPasajeros();
        ReservaPasajero::where('cotizacion_pasajero_id', $beto->id)->delete();

        $soloBeto = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'dia_referencial' => 2,
            'descripcion_manual' => 'Cena de Beto', 'modo_precio' => 'tarifa_fija', 'cantidad' => 1,
            'moneda_costo' => 'PEN', 'costo_snapshot' => 20, 'precio_venta_snapshot' => 30, 'precio_convertido' => 30,
            'pax_incluidos' => [$beto->id],
        ]);

        $respuesta = app(ReservaController::class)->sincronizarItems((string) $reserva->id);

        // Antes quedaba sin asignar = "para todos", y se cobraba a los demás.
        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertStringContainsString('Cena de Beto', $respuesta->getData(true)['message']);
        $this->assertFalse(ReservaItem::where('alternativa_item_id', $soloBeto->id)->exists());
    }
}
