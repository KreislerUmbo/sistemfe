<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\VentaDirectaController;
use App\Models\AgenciaViajes\Reserva;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Fase 1 del fix Cotización↔Reserva (2026-08-18) — §3.6 del brief:
// VentaDirectaController::store() no tiene código propio que tocar (reusa
// ReservaController::crearReservaDesdeAlternativa()), pero el brief pide
// confirmarlo con un test, no darlo por sentado. Mismo patrón de
// infraestructura que ReservaFechaAutoCompletadaTest: Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
//
// origen_tipo='proveedor' con una proveedor_tarifa real, NO 'manual': se
// encontró al escribir este test que VentaDirectaController::store() arma
// $itemRequest desde SU PROPIO $validator->validated() (línea ~92), cuyas
// reglas no incluyen 'costo_snapshot' — cualquier origen_tipo='manual'
// pierde ese campo al reenviarlo a AlternativaItemController::crearItemManual()
// (que sí lo exige, 'required'), y falla con 422 sin relación con este fix.
// Gap real, preexistente, AJENO a la Fase 1 (es un problema de reenvío de
// payload entre dos validadores, no de fechas) — fuera de alcance de este
// brief, no se toca acá. origen_tipo='proveedor' CON proveedor_tarifa_id
// real es el único camino que hoy funciona de punta a punta sin tocar ese
// código, así que es el que prueba esta fase.
class VentaDirectaReservaFechaTest extends TestCase
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

    private function crearProveedorTarifa(): int
    {
        $destinoAtractivoId = DB::table('destinos_atractivos')->insertGetId([
            'nombre' => 'Tarapoto', 'tipo' => 'lugar',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $servicioId = DB::table('servicios')->insertGetId([
            'nombre' => 'Traslado aeropuerto', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $destinoServicioId = DB::table('destino_servicio')->insertGetId([
            'destino_atractivo_id' => $destinoAtractivoId, 'servicio_id' => $servicioId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorId = DB::table('proveedores')->insertGetId([
            'razon_social' => 'Transportes Test SAC', 'estado' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorServicioId = DB::table('proveedor_servicios')->insertGetId([
            'proveedor_id' => $proveedorId, 'destino_servicio_id' => $destinoServicioId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('proveedor_tarifas')->insertGetId([
            'proveedor_servicio_id' => $proveedorServicioId,
            'tipo_tarifa' => 'publica', 'modalidad' => 'privado', 'moneda' => 'PEN',
            'precio_costo' => 30, 'margen_tipo' => 'fijo', 'margen_valor' => 20,
            'precio_venta_adulto' => 50,
            'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_venta_directa_hereda_fecha_viaje_desde_hasta_propia_en_la_reserva(): void
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '11991199', 'full_name' => 'Cliente Test Venta Directa',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedorTarifaId = $this->crearProveedorTarifa();

        $response = app(VentaDirectaController::class)->store(new Request([
            'cliente_id' => $clienteId,
            'destino' => 'Tarapoto',
            'fecha_servicio' => '2026-10-05',
            'origen_tipo' => 'proveedor',
            'pax' => [['edad' => 30]],
            'proveedor_tarifa_id' => $proveedorTarifaId,
            'modo_precio' => 'tarifa_fija',
            'cantidad' => 1,
        ]));

        $body = $response->getData(true);
        $this->assertSame(200, $body['code']);

        $reserva = Reserva::findOrFail($body['reserva']['id']);

        $this->assertSame('2026-10-05', $reserva->fecha_viaje_desde->toDateString());
        $this->assertSame('2026-10-05', $reserva->fecha_viaje_hasta->toDateString());
    }
}
