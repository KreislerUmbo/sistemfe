<?php

namespace Tests\Feature\AgenciaViajes\Concerns;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\GuiaTarifa;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\PaquetePlantillaItem;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Models\AgenciaViajes\Servicio;
use App\Models\AgenciaViajes\TourItinerarioItem;
use App\Services\HoraPeru;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Fixtures compartidos por los tests de la fase 2 (salidas): Postgres real
// (sistemafe_test_migrations), transacción por test revertida, agencia en
// modo de tours nuevo, un tour cargado el día 1 (15-nov-2026) y su reserva
// aceptada.
trait SalidasFixtures
{
    private DestinoAtractivo $destino;

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

        $this->destino = DestinoAtractivo::create(['nombre' => 'Laguna Azul '.uniqid(), 'tipo' => 'zona']);
        ConfiguracionAgencia::first()->update([
            'modo_tours' => ConfiguracionAgencia::MODO_TOURS_NUEVO,
            'tip_afe_igv_default' => '10',
            'destino_tributario_default' => 'nacional',
        ]);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function crearTour(array $atributos = []): PaquetePlantilla
    {
        $tour = PaquetePlantilla::create($atributos + [
            'categoria' => 'local',
            'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE,
            'nombre' => 'Laguna Azul Full Day',
            'destino_atractivo_id' => $this->destino->id,
            'duracion_horas' => 10,
            'precio_venta_final' => 150,
            'costo_estimado_persona' => 100,
            'modalidad' => 'compartido',
        ]);
        TourItinerarioItem::create([
            'tour_id' => $tour->id, 'dia_relativo' => 1, 'orden' => 1,
            'destino_atractivo_id' => $this->destino->id, 'descripcion' => 'Día 1',
        ]);

        return $tour;
    }

    // Servicio del Incluye, con costo e IGV propios y proveedor sugerido opcional.
    private function incluye(PaquetePlantilla $tour, float $costo, string $igv = '10', string $destino = 'nacional', ?Proveedor $proveedor = null, string $nombre = 'Servicio'): PaquetePlantillaItem
    {
        $servicio = Servicio::create(['nombre' => $nombre.' '.uniqid()]);
        $destinoServicio = DestinoServicio::create(['destino_atractivo_id' => $this->destino->id, 'servicio_id' => $servicio->id]);

        return PaquetePlantillaItem::create([
            'paquete_plantilla_id' => $tour->id, 'destino_servicio_id' => $destinoServicio->id,
            'proveedor_id' => $proveedor?->id,
            'costo_estimado_persona' => $costo, 'tip_afe_igv' => $igv, 'destino_tributario' => $destino,
            'orden' => PaquetePlantillaItem::where('paquete_plantilla_id', $tour->id)->count() + 1,
        ]);
    }

    private function proveedor(string $nombre = 'Proveedor Test'): Proveedor
    {
        return Proveedor::create(['razon_social' => $nombre.' '.uniqid(), 'nombre_comercial' => $nombre, 'estado' => true]);
    }

    private function guiaTarifa(): GuiaTarifa
    {
        $guia = Guia::create(['nombre' => 'Guía Test', 'documento' => (string) random_int(10000000, 99999999), 'telefono' => '999999999', 'activo' => true]);

        return GuiaTarifa::create([
            'guia_id' => $guia->id, 'destino_id' => $this->destino->id, 'modalidad' => 'dia_local',
            'costo_diario' => 100, 'tipo_margen' => 'porcentaje', 'margen_valor' => 25,
            'moneda' => 'PEN', 'vigente_desde' => HoraPeru::hoyTexto(),
        ]);
    }

    /**
     * Cotización que empieza el 15-nov-2026 con el tour cargado el día $dia, y su reserva aceptada.
     *
     * @param  array<int, array{0: string, 1: int, 2?: string}>  $pasajeros  [tipo, edad, residencia]
     */
    private function reservaConTour(PaquetePlantilla $tour, array $pasajeros, int $dia = 1): Reserva
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10000000, 99999999), 'full_name' => 'Cliente Test Salidas',
            'type_client' => 1, 'cod_tipo_doc_sunat' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-F2-'.uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => '2026-11-15', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($pasajeros as $p) {
            CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => $p[0], 'edad' => $p[1], 'residencia' => $p[2] ?? 'nacional']);
        }
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 3.75, 'tipo_cambio_origen' => 'dia',
        ]);
        AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_atractivo_id' => $this->destino->id, 'orden' => 1]);

        $carga = app(AlternativaItemController::class)->desdePlantilla(
            new Request(['paquete_plantilla_id' => $tour->id, 'dia_referencial' => $dia]),
            (string) $alternativa->id
        );
        $this->assertLessThan(300, $carga->getStatusCode(), json_encode($carga->getData(true)));

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        return $reserva;
    }

    private function salidaDe(Reserva $reserva): ?SalidaOperativa
    {
        $id = ReservaItem::where('reserva_id', $reserva->id)->value('salida_operativa_id');

        return $id ? SalidaOperativa::find($id) : null;
    }
}
