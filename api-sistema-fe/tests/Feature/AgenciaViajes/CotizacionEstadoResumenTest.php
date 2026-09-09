<?php

namespace Tests\Feature\AgenciaViajes;

use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\Reserva;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Taxonomía de estados del listado de cotizaciones (09-sep-2026, pedido
// del usuario: "vamos analizando los estados de las cotizaciones").
// Cotizacion no tiene columna 'estado' propia (vive en cada Alternativa,
// hasta 5 por cotización, más el estado de la Reserva una vez que alguna
// se acepta) — Cotizacion::estadoResumen() deriva un único valor de
// negocio para el listado, sin duplicar esa fuente de verdad. Mismo
// patrón de infraestructura que el resto de la suite: Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
class CotizacionEstadoResumenTest extends TestCase
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

    private function crearCotizacion(): Cotizacion
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '99887766', 'full_name' => 'Cliente Test Estado Resumen',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Cotizacion::create([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-ER-' . random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto',
        ]);
    }

    private function crearAlternativa(Cotizacion $cotizacion, string $estado, ?\DateTimeInterface $fechaVencimiento = null): Alternativa
    {
        return Alternativa::create([
            'cotizacion_id' => $cotizacion->id, 'nombre' => 'Alternativa Test', 'estado' => $estado,
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
            'fecha_envio' => $estado === 'enviada' ? now() : null,
            'fecha_vencimiento' => $fechaVencimiento,
        ]);
    }

    public function test_sin_alternativas_es_borrador(): void
    {
        $cotizacion = $this->crearCotizacion();

        $this->assertSame('borrador', $cotizacion->estadoResumen());
    }

    public function test_todas_en_borrador_es_borrador(): void
    {
        $cotizacion = $this->crearCotizacion();
        $this->crearAlternativa($cotizacion, 'borrador');
        $this->crearAlternativa($cotizacion, 'borrador');

        $this->assertSame('borrador', $cotizacion->fresh('alternativas.reserva')->estadoResumen());
    }

    public function test_una_enviada_vigente_es_enviada(): void
    {
        $cotizacion = $this->crearCotizacion();
        $this->crearAlternativa($cotizacion, 'enviada', now()->addDays(5));

        $this->assertSame('enviada', $cotizacion->fresh('alternativas.reserva')->estadoResumen());
    }

    public function test_una_enviada_vencida_y_otra_vigente_sigue_enviada(): void
    {
        $cotizacion = $this->crearCotizacion();
        $this->crearAlternativa($cotizacion, 'enviada', now()->subDay());
        $this->crearAlternativa($cotizacion, 'enviada', now()->addDays(5));

        $this->assertSame('enviada', $cotizacion->fresh('alternativas.reserva')->estadoResumen());
    }

    public function test_todas_las_enviadas_vencidas_es_vencida(): void
    {
        $cotizacion = $this->crearCotizacion();
        $this->crearAlternativa($cotizacion, 'enviada', now()->subDays(3));
        $this->crearAlternativa($cotizacion, 'descartada');

        $this->assertSame('vencida', $cotizacion->fresh('alternativas.reserva')->estadoResumen());
    }

    public function test_aceptada_con_reserva_activa_es_reservada(): void
    {
        $cotizacion = $this->crearCotizacion();
        $aceptada = $this->crearAlternativa($cotizacion, 'aceptada');
        $this->crearAlternativa($cotizacion, 'descartada');
        Reserva::create(['alternativa_id' => $aceptada->id, 'estado' => 'activa']);

        $this->assertSame('reservada', $cotizacion->fresh('alternativas.reserva')->estadoResumen());
    }

    public function test_aceptada_con_reserva_cancelada_es_anulada(): void
    {
        $cotizacion = $this->crearCotizacion();
        $aceptada = $this->crearAlternativa($cotizacion, 'aceptada');
        Reserva::create([
            'alternativa_id' => $aceptada->id, 'estado' => 'cancelada',
            'fecha_cancelacion' => now(), 'motivo_cancelacion' => 'voluntaria',
        ]);

        $this->assertSame('anulada', $cotizacion->fresh('alternativas.reserva')->estadoResumen());
    }

    public function test_aceptada_sin_reserva_todavia_es_reservada(): void
    {
        // Caso defensivo: en el flujo real aceptar() SIEMPRE crea la Reserva
        // en el mismo paso (nunca queda "aceptada" sin Reserva) — pero
        // estadoResumen() no debe explotar ni caer a un estado equivocado
        // si algún día existiera ese hueco de datos.
        $cotizacion = $this->crearCotizacion();
        $this->crearAlternativa($cotizacion, 'aceptada');

        $this->assertSame('reservada', $cotizacion->fresh('alternativas.reserva')->estadoResumen());
    }

    public function test_todas_descartadas_es_descartada(): void
    {
        $cotizacion = $this->crearCotizacion();
        $this->crearAlternativa($cotizacion, 'descartada');
        $this->crearAlternativa($cotizacion, 'descartada');

        $this->assertSame('descartada', $cotizacion->fresh('alternativas.reserva')->estadoResumen());
    }
}
