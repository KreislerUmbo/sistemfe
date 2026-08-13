<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Models\AgenciaViajes\Servicio;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// feature/salida-operativa — "salida" (departure) agrupa reserva_items de
// DISTINTAS reservas que comparten tour_origen_id + fecha, para asignar el
// guía una sola vez por salida en vez de una vez por reserva. Mismo patrón
// que PaqueteComboTest/ReservaFechaAutoCompletadaTest: Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
//
// Gap real encontrado leyendo el schema antes de escribir esto:
// guia_tarifas.modalidad es 'dia_local'/'grupo_multidia' (eje de duración
// de contrato), NO 'compartido'/'privado' como proveedor_tarifas.modalidad
// — no hay ninguna señal confiable de si un ítem de guía puntual es
// compartible entre reservas. Confirmado con el usuario: los ítems
// origen_tipo=guia NUNCA se auto-enganchan en esta fase (solo
// origen_tipo=proveedor con modalidad=compartido) — quedan disponibles
// para engancharse a mano desde el tablero (attachReservaItem()). Por eso
// el caso "dos reservas terminan en la misma salida" se prueba acá con
// ítems origen_tipo=proveedor, no con ítems de guía.
class SalidaOperativaTest extends TestCase
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

    // ═══════════════════════════════════════════════════════════════
    // Fixtures — mismo patrón que PaqueteComboTest: arma la cadena real de
    // FK (destino → servicio → destino_servicio → proveedor →
    // proveedor_servicio → proveedor_tarifa) en vez de mockear nada.
    // ═══════════════════════════════════════════════════════════════

    private function crearProveedorTarifa(string $modalidad = 'compartido'): ProveedorTarifa
    {
        $destino = DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);
        $servicio = Servicio::create(['nombre' => 'Traslado ida y vuelta']);
        $destinoServicio = DestinoServicio::create([
            'destino_atractivo_id' => $destino->id,
            'servicio_id' => $servicio->id,
        ]);
        $proveedor = Proveedor::create(['razon_social' => 'Transportes Test SAC', 'estado' => true]);
        $proveedorServicio = ProveedorServicio::create([
            'proveedor_id' => $proveedor->id,
            'destino_servicio_id' => $destinoServicio->id,
        ]);

        return ProveedorTarifa::create([
            'proveedor_servicio_id' => $proveedorServicio->id,
            'tipo_tarifa' => 'publica',
            'modalidad' => $modalidad,
            'moneda' => 'PEN',
            'precio_costo' => 60,
            'margen_tipo' => 'porcentaje',
            'margen_valor' => 20,
            'precio_venta_adulto' => 120,
            'vigente_desde' => now()->toDateString(),
            'tip_afe_igv' => '10',
            'destino_tributario' => 'nacional',
        ]);
    }

    private function crearTour(string $nombre = 'Alto Mayo Full Day'): PaquetePlantilla
    {
        $destino = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);

        return PaquetePlantilla::create([
            'categoria' => 'local',
            'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE,
            'nombre' => $nombre,
            'destino_atractivo_id' => $destino->id,
            'duracion_horas' => 8,
        ]);
    }

    // Arma cliente → cotización → alternativa → alternativa_item
    // (origen_tipo=proveedor) y crea la reserva real vía
    // ReservaController::crearReservaDesdeAlternativa() — mismo camino que
    // dispara engancharSalidaOperativa() en producción (aceptar una
    // alternativa). $tourOrigenId=null simula un ítem agregado 100% suelto
    // (nunca pasó por "cargar desde plantilla").
    private function crearReservaConItemProveedor(
        ?int $tourOrigenId,
        string $fechaViajeDesde,
        int $diaReferencial,
        string $modalidad,
        string $codigoCotizacion
    ): array {
        $tarifa = $this->crearProveedorTarifa($modalidad);

        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '87654321', 'full_name' => 'Cliente Test Salida',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => $codigoCotizacion, 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'fecha_viaje_desde' => $fechaViajeDesde,
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

        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id,
            'origen_tipo' => AlternativaItem::ORIGEN_PROVEEDOR,
            'proveedor_tarifa_id' => $tarifa->id,
            'tour_origen_id' => $tourOrigenId,
            'dia_referencial' => $diaReferencial,
            'modo_precio' => 'tarifa_fija',
            'cantidad' => 1,
            'moneda_costo' => 'PEN',
            'costo_snapshot' => 60,
            'precio_venta_snapshot' => 120,
            'precio_convertido' => 120,
        ]);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        $reservaItem = ReservaItem::where('alternativa_item_id', $item->id)->first();

        return [$reserva, $reservaItem];
    }

    public function test_dos_reservas_mismo_tour_y_fecha_modalidad_compartido_terminan_en_la_misma_salida(): void
    {
        $tour = $this->crearTour();

        [$reservaA, $itemA] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-1001');
        [$reservaB, $itemB] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-1002');

        $this->assertNotNull($itemA->salida_operativa_id);
        $this->assertNotNull($itemB->salida_operativa_id);
        $this->assertSame($itemA->salida_operativa_id, $itemB->salida_operativa_id);
        $this->assertSame(1, SalidaOperativa::count());

        $salida = SalidaOperativa::first();
        $this->assertSame($tour->id, $salida->tour_origen_id);
        $this->assertSame('2026-09-01', $salida->fecha->toDateString());
        $this->assertSame('activa', $salida->estado);
        $this->assertNotSame($reservaA->id, $reservaB->id);
    }

    public function test_item_modalidad_privado_no_se_engancha_a_ninguna_salida(): void
    {
        $tour = $this->crearTour();

        [, $item] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'privado', 'TEST-2026-1003');

        $this->assertNull($item->salida_operativa_id);
        $this->assertSame(0, SalidaOperativa::count());
    }

    public function test_item_sin_tour_origen_id_no_se_engancha_a_ninguna_salida(): void
    {
        [, $item] = $this->crearReservaConItemProveedor(null, '2026-09-01', 1, 'compartido', 'TEST-2026-1004');

        $this->assertNull($item->salida_operativa_id);
        $this->assertSame(0, SalidaOperativa::count());
    }

    public function test_actualizar_guia_de_la_salida_se_refleja_para_ambas_reservas_al_recargar(): void
    {
        $tour = $this->crearTour();

        [, $itemA] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-1005');
        [, $itemB] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-1006');

        $guia = Guia::create(['nombre' => 'Juan Pérez', 'documento' => '12345678', 'telefono' => '999999999']);

        $salida = SalidaOperativa::find($itemA->salida_operativa_id);
        $salida->update(['guia_id' => $guia->id]);

        $guiaVistaDesdeA = $itemA->fresh()->salidaOperativa->guia;
        $guiaVistaDesdeB = $itemB->fresh()->salidaOperativa->guia;

        $this->assertSame($guia->id, $guiaVistaDesdeA->id);
        $this->assertSame($guia->id, $guiaVistaDesdeB->id);
    }

    // Condición de carrera simulada: dos llamadas seguidas sobre la misma
    // clave (tour_origen_id/fecha) deben terminar en la MISMA salida, sin
    // duplicar la fila ni fallar — el índice único parcial + el catch de
    // QueryException en engancharSalidaOperativa() son el backstop real
    // para el caso concurrente (dos requests HTTP distintas), esto prueba
    // que el camino feliz (firstOrCreate ya idempotente) no duplica nada
    // en la ejecución secuencial normal.
    public function test_dos_enganches_seguidos_sobre_la_misma_clave_no_duplican_la_salida(): void
    {
        $tour = $this->crearTour();

        [, $itemA] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-1007');
        [, $itemB] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-1008');
        [, $itemC] = $this->crearReservaConItemProveedor($tour->id, '2026-09-01', 1, 'compartido', 'TEST-2026-1009');

        $this->assertSame(1, SalidaOperativa::count());
        $this->assertSame($itemA->salida_operativa_id, $itemB->salida_operativa_id);
        $this->assertSame($itemA->salida_operativa_id, $itemC->salida_operativa_id);
    }
}
