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

    // T-074 (CA3) — el listado dice qué alternativa se reservó y con qué código, sin consultas
    // extra por fila: las relaciones de RELACIONES_ESTADO_RESUMEN ya vienen precargadas.
    public function test_alternativa_reservada_trae_nombre_y_codigo_sin_consultas_extra(): void
    {
        $cotizacion = $this->crearCotizacion();
        $aceptada = $this->crearAlternativa($cotizacion, 'aceptada');
        $aceptada->update(['nombre' => 'Opción Selva']);
        $this->crearAlternativa($cotizacion, 'descartada');
        $reserva = Reserva::create(['alternativa_id' => $aceptada->id, 'estado' => 'activa']);

        $cargada = Cotizacion::with(Cotizacion::RELACIONES_ESTADO_RESUMEN)->findOrFail($cotizacion->id);
        DB::enableQueryLog();
        $datos = $cargada->alternativaReservada();
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $consultas);
        $this->assertSame('Opción Selva', $datos['nombre']);
        $this->assertSame($reserva->id, $datos['reserva_id']);
        $this->assertSame($reserva->codigo, $datos['reserva_codigo']);
    }

    public function test_alternativa_reservada_es_null_sin_reserva(): void
    {
        $cotizacion = $this->crearCotizacion();
        $this->crearAlternativa($cotizacion, 'enviada', now()->addDay());

        $cargada = Cotizacion::with(Cotizacion::RELACIONES_ESTADO_RESUMEN)->findOrFail($cotizacion->id);

        $this->assertNull($cargada->alternativaReservada());
    }

    // Reporte del usuario (31-oct-2026): al agregar un hotel a la alternativa 2 se recalcula su
    // total y la pestaña pasaba a ser la 1. Las alternativas salen siempre en orden de alta.
    public function test_las_alternativas_mantienen_su_orden_aunque_se_actualice_una(): void
    {
        $cotizacion = $this->crearCotizacion();
        $primera = $this->crearAlternativa($cotizacion, 'borrador');
        $segunda = $this->crearAlternativa($cotizacion, 'borrador');

        // El UPDATE deja la fila nueva al final de la tabla: sin ORDER BY, la primera salía segunda.
        $primera->update(['total' => 300]);

        $this->assertSame([$primera->id, $segunda->id], $cotizacion->fresh('alternativas')->alternativas->pluck('id')->all());
    }
}
