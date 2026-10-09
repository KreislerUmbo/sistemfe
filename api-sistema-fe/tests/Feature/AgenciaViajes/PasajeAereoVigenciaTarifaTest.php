<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\CotizacionPasajeAereo;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Services\HoraPeru;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Vigencia de la tarifa aérea (09-oct-2026, pedido del usuario): la
// aerolínea sostiene la tarifa hasta una fecha-hora límite — el vendedor la
// escribe en hora de Perú y se guarda como instante UTC
// (cotizacion_pasaje_aereo.tarifa_valida_hasta). Mismo patrón de
// infraestructura que AlternativaItemPasajeAereoTest: Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
class PasajeAereoVigenciaTarifaTest extends TestCase
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

    private function crearAlternativa(): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '55667799', 'full_name' => 'Cliente Test Vigencia Aerea',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-1009-' . uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Lima', 'fecha_viaje_desde' => '2026-10-20',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 40]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'USD', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'origen_tipo' => 'pasaje_aereo',
            'aerolinea' => 'Star Peru',
            'itinerario' => 'TARAPOTO - LIMA - TARAPOTO',
            'moneda' => 'USD',
            'tarifa_base_adulto' => 200,
            'fee_agencia_monto' => 26.49,
            'dia_referencial' => 1,
        ], $extra);
    }

    private function crear(Alternativa $alternativa, array $extra = []): array
    {
        $response = app(AlternativaItemController::class)->store(new Request($this->payload($extra)), (string) $alternativa->id);

        return [$response->getStatusCode(), $response->getData(true)];
    }

    public function test_hora_de_peru_se_guarda_como_instante_utc(): void
    {
        [$status, $body] = $this->crear($this->crearAlternativa(), ['tarifa_valida_hasta' => '2026-10-10 18:00']);

        $this->assertSame(200, $status);
        $pasaje = CotizacionPasajeAereo::where('alternativa_item_id', $body['alternativa_item']['id'])->first();
        // 18:00 de Perú (UTC−5) = 23:00 UTC del mismo día.
        $this->assertSame('2026-10-10 23:00:00', $pasaje->getRawOriginal('tarifa_valida_hasta'));
        $this->assertSame('10/10/2026 18:00', HoraPeru::deUtc($pasaje->tarifa_valida_hasta)->format('d/m/Y H:i'));
    }

    // De 19:00 a 24:00 de Perú ya es el día siguiente en UTC — el instante
    // guardado cambia de día, pero al mostrarlo vuelve a la fecha correcta.
    public function test_hora_de_noche_cruza_el_dia_en_utc_sin_correr_la_fecha_mostrada(): void
    {
        [, $body] = $this->crear($this->crearAlternativa(), ['tarifa_valida_hasta' => '2026-10-10 23:59']);

        $pasaje = CotizacionPasajeAereo::where('alternativa_item_id', $body['alternativa_item']['id'])->first();
        $this->assertSame('2026-10-11 04:59:00', $pasaje->getRawOriginal('tarifa_valida_hasta'));
        $this->assertSame('10/10/2026 23:59', HoraPeru::deUtc($pasaje->tarifa_valida_hasta)->format('d/m/Y H:i'));
    }

    public function test_sin_vigencia_queda_null(): void
    {
        [$status, $body] = $this->crear($this->crearAlternativa());

        $this->assertSame(200, $status);
        $this->assertNull(CotizacionPasajeAereo::where('alternativa_item_id', $body['alternativa_item']['id'])->value('tarifa_valida_hasta'));
    }

    public function test_formato_invalido_se_rechaza(): void
    {
        [$status] = $this->crear($this->crearAlternativa(), ['tarifa_valida_hasta' => '10/10/2026']);

        $this->assertSame(422, $status);
    }

    public function test_editar_cambia_y_quitar_la_vigencia_la_deja_en_null(): void
    {
        $alternativa = $this->crearAlternativa();
        [, $body] = $this->crear($alternativa, ['tarifa_valida_hasta' => '2026-10-10 18:00']);
        $itemId = (string) $body['alternativa_item']['id'];
        $controller = app(AlternativaItemController::class);

        $response = $controller->actualizarPasajeAereo(new Request($this->payload(['tarifa_valida_hasta' => '2026-10-12 09:30'])), $itemId);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('2026-10-12 14:30:00', CotizacionPasajeAereo::where('alternativa_item_id', $itemId)->first()->getRawOriginal('tarifa_valida_hasta'));

        $controller->actualizarPasajeAereo(new Request($this->payload(['tarifa_valida_hasta' => null])), $itemId);
        $this->assertNull(CotizacionPasajeAereo::where('alternativa_item_id', $itemId)->value('tarifa_valida_hasta'));
    }

    public function test_duplicar_la_alternativa_conserva_la_vigencia(): void
    {
        $alternativa = $this->crearAlternativa();
        $this->crear($alternativa, ['tarifa_valida_hasta' => '2026-10-10 18:00']);

        $response = app(\App\Http\Controllers\AgenciaViajes\AlternativaController::class)->duplicar((string) $alternativa->id);
        $this->assertSame(200, $response->getStatusCode());

        $copia = Alternativa::where('cotizacion_id', $alternativa->cotizacion_id)->where('id', '!=', $alternativa->id)->firstOrFail();
        $itemCopia = AlternativaItem::where('alternativa_id', $copia->id)->where('origen_tipo', 'pasaje_aereo')->firstOrFail();
        $this->assertSame('2026-10-10 23:00:00', CotizacionPasajeAereo::where('alternativa_item_id', $itemCopia->id)->first()->getRawOriginal('tarifa_valida_hasta'));
    }
}
