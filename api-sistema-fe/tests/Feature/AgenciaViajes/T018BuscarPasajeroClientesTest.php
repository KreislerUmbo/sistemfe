<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaPasajeroController;
use App\Models\AgenciaViajes\PasajeroCatalogo;
use App\Models\AgenciaViajes\PasajeroDocumento;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaPasajero;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// T-018: "Buscar pasajero" también busca en clientes persona (sin ficha), y al
// guardar con el cliente elegido se resuelve su ficha vinculada.
class T018BuscarPasajeroClientesTest extends TestCase
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

    private function crearCliente(string $tipo, string $numero, string $nombre): int
    {
        return DB::table('clients')->insertGetId([
            'type_document' => $tipo,
            'n_document' => $numero,
            'full_name' => $nombre,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function buscar(string $texto): array
    {
        $r = app(ReservaPasajeroController::class)->buscarCatalogo(new Request(['search' => $texto]));

        return json_decode($r->getContent(), true)['pasajeros_catalogo'];
    }

    // Reserva real (cotización de un cliente RUC → pasajeros en blanco); devuelve el primer pasajero.
    private function pasajeroDeReserva(): ReservaPasajero
    {
        $rucId = $this->crearCliente('RUC', '2'.random_int(1000000000, 9999999999), 'Empresa SAC');
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST',
            'codigo' => 'TEST-T018-'.uniqid(),
            'cliente_id' => $rucId,
            'destino' => 'Tarapoto',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('cotizacion_pasajeros')->insert([
            'cotizacion_id' => $cotizacionId,
            'tipo_pax' => 'adulto',
            'edad' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $alternativaId = DB::table('alternativas')->insertGetId([
            'cotizacion_id' => $cotizacionId,
            'nombre' => 'Alt '.uniqid(),
            'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN',
            'tipo_cambio_aplicado' => 1,
            'tipo_cambio_origen' => 'dia',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $r = app(ReservaController::class)->aceptar(new Request(['pasajero_catalogo_ids' => []]), (string) $alternativaId);
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());

        $reserva = Reserva::where('alternativa_id', $alternativaId)->firstOrFail();

        return ReservaPasajero::where('reserva_id', $reserva->id)->orderBy('id')->firstOrFail();
    }

    private function guardar(ReservaPasajero $pax, array $datos)
    {
        $r = app(ReservaPasajeroController::class)->update(new Request($datos), (string) $pax->id);
        $this->assertSame(200, $r->getStatusCode(), $r->getContent());

        return $pax->fresh();
    }

    public function test_ca1_cliente_sin_ficha_sale_con_origen_cliente(): void
    {
        $clienteId = $this->crearCliente('DNI', '71234501', 'Lucía Paredes');

        $res = $this->buscar('71234501');

        $this->assertCount(1, $res);
        $this->assertSame('cliente', $res[0]['origen']);
        $this->assertNull($res[0]['id']);
        $this->assertSame($clienteId, $res[0]['cliente_id']);
        $this->assertSame('71234501', $res[0]['documentos'][0]['numero_documento']);
        $this->assertNull(PasajeroCatalogo::where('cliente_id', $clienteId)->first(), 'Buscar no crea fichas');
    }

    public function test_ca2_guardar_con_el_cliente_elegido_crea_la_ficha_y_la_asigna(): void
    {
        $clienteId = $this->crearCliente('DNI', '71234502', 'Lucía Paredes');
        $pax = $this->pasajeroDeReserva();

        $pax = $this->guardar($pax, ['nombre' => 'Lucía Paredes', 'documento' => '71234502', 'cliente_id' => $clienteId]);

        $ficha = PasajeroCatalogo::where('cliente_id', $clienteId)->firstOrFail();
        $this->assertSame($ficha->id, $pax->pasajero_catalogo_id);
        $this->assertSame(1, PasajeroCatalogo::where('cliente_id', $clienteId)->count());
    }

    public function test_ca3_buscar_de_nuevo_sale_una_sola_vez_como_pasajero(): void
    {
        $clienteId = $this->crearCliente('DNI', '71234503', 'Lucía Paredes');
        $this->guardar($this->pasajeroDeReserva(), ['nombre' => 'Lucía Paredes', 'documento' => '71234503', 'cliente_id' => $clienteId]);

        $res = $this->buscar('71234503');

        $this->assertCount(1, $res);
        $this->assertSame('pasajero', $res[0]['origen']);
        $this->assertNotNull($res[0]['id']);
    }

    public function test_ca4_cliente_ruc_o_documento_en_ceros_no_sale(): void
    {
        $this->crearCliente('RUC', '20999888771', 'Zeta Quillabamba SAC');
        $this->crearCliente('DNI', '00000000', 'Zeta Sin Documento');

        $this->assertSame([], $this->buscar('Zeta'));
    }

    public function test_ca5_nombre_parcial_sin_distinguir_mayusculas_en_las_dos_tablas(): void
    {
        $ficha = PasajeroCatalogo::create(['nombre' => 'Marcelo Quispe']);
        PasajeroDocumento::create([
            'pasajero_catalogo_id' => $ficha->id, 'tipo_documento' => 'DNI',
            'numero_documento' => '71234505', 'fecha_registro' => '2026-11-04',
        ]);
        $this->crearCliente('DNI', '71234506', 'Marcela Quispe');

        $res = $this->buscar('QUISPE');

        $this->assertSame(['pasajero', 'cliente'], array_column($res, 'origen'));
    }

    public function test_ca7_cliente_cuyo_dni_ya_esta_en_una_ficha_sin_cliente_id_sale_una_vez_como_pasajero(): void
    {
        $ficha = PasajeroCatalogo::create(['nombre' => 'Rosa Mendoza']);
        PasajeroDocumento::create([
            'pasajero_catalogo_id' => $ficha->id, 'tipo_documento' => 'DNI',
            'numero_documento' => '71234507', 'fecha_registro' => '2026-11-04',
        ]);
        $this->crearCliente('DNI', '71234507', 'Rosa Mendoza');

        $res = $this->buscar('71234507');

        $this->assertCount(1, $res);
        $this->assertSame('pasajero', $res[0]['origen']);
    }

    public function test_ca8_cliente_id_con_otro_documento_no_se_vincula(): void
    {
        $clienteId = $this->crearCliente('DNI', '71234508', 'Lucía Paredes');
        $pax = $this->pasajeroDeReserva();

        $pax = $this->guardar($pax, ['nombre' => 'Otra Persona', 'documento' => '71234599', 'cliente_id' => $clienteId]);

        $this->assertNull(PasajeroCatalogo::where('cliente_id', $clienteId)->first());
        $ficha = PasajeroDocumento::where('numero_documento', '71234599')->firstOrFail()->pasajeroCatalogo;
        $this->assertNull($ficha->cliente_id);
        $this->assertSame($ficha->id, $pax->pasajero_catalogo_id);
    }

    public function test_ca9_guardar_dos_veces_con_el_mismo_cliente_id_deja_una_sola_ficha(): void
    {
        $clienteId = $this->crearCliente('DNI', '71234509', 'Lucía Paredes');
        $pax = $this->pasajeroDeReserva();
        $datos = ['nombre' => 'Lucía Paredes', 'documento' => '71234509', 'cliente_id' => $clienteId];

        $this->guardar($pax, $datos);
        $pax = $this->guardar($pax->fresh(), $datos);

        $this->assertSame(1, PasajeroCatalogo::where('cliente_id', $clienteId)->count());
        $this->assertSame(1, PasajeroDocumento::where('numero_documento', '71234509')->count());
        $this->assertSame(PasajeroCatalogo::where('cliente_id', $clienteId)->value('id'), $pax->pasajero_catalogo_id);
    }
}
