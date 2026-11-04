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

// T-017: al aceptar la cotización, el cliente (persona con documento) nace como
// primer pasajero adulto, vinculado a su ficha del catálogo de pasajeros.
class T017ClientePrimerPasajeroTest extends TestCase
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

    private function crearCliente(string $tipoDocumento = 'DNI', ?string $numero = null, string $nombre = 'Ana Torres Ríos'): int
    {
        return DB::table('clients')->insertGetId([
            'type_document' => $tipoDocumento,
            'n_document' => $numero ?? (string) random_int(10000000, 99999999),
            'full_name' => $nombre,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Cotización con sus pasajeros ($tipos: 'adulto'|'nino'|'infante') y una alternativa.
    private function crearAlternativa(int $clienteId, array $tipos = ['adulto', 'adulto'], ?int $cotizacionId = null): int
    {
        if ($cotizacionId === null) {
            $cotizacionId = DB::table('cotizaciones')->insertGetId([
                'codigo_prefijo' => 'TEST',
                'codigo' => 'TEST-T017-'.uniqid(),
                'cliente_id' => $clienteId,
                'destino' => 'Tarapoto',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($tipos as $tipo) {
                DB::table('cotizacion_pasajeros')->insert([
                    'cotizacion_id' => $cotizacionId,
                    'tipo_pax' => $tipo,
                    'edad' => $tipo === 'adulto' ? 30 : 5,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return DB::table('alternativas')->insertGetId([
            'cotizacion_id' => $cotizacionId,
            'nombre' => 'Alternativa '.uniqid(),
            'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN',
            'tipo_cambio_aplicado' => 1,
            'tipo_cambio_origen' => 'dia',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Acepta y devuelve los pasajeros de la reserva creada, en orden de id.
    private function aceptar(int $alternativaId, array $catalogoIds = [])
    {
        $respuesta = app(ReservaController::class)->aceptar(
            new Request(['pasajero_catalogo_ids' => $catalogoIds]),
            (string) $alternativaId
        );
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());

        $reserva = Reserva::where('alternativa_id', $alternativaId)->firstOrFail();

        return ReservaPasajero::where('reserva_id', $reserva->id)->orderBy('id')->get();
    }

    private function fichaDe(string $documento): ?PasajeroCatalogo
    {
        return PasajeroDocumento::where('numero_documento', $documento)->first()?->pasajeroCatalogo;
    }

    public function test_ca1_cliente_dni_con_dos_adultos_carga_al_primero(): void
    {
        $clienteId = $this->crearCliente('DNI', '45678901');
        $pasajeros = $this->aceptar($this->crearAlternativa($clienteId));

        $this->assertSame('Ana Torres Ríos', $pasajeros[0]->nombre);
        $this->assertSame('45678901', $pasajeros[0]->documento);
        $ficha = PasajeroCatalogo::where('cliente_id', $clienteId)->firstOrFail();
        $this->assertSame($ficha->id, $pasajeros[0]->pasajero_catalogo_id);
        $this->assertSame('DNI', $ficha->documentos()->value('tipo_documento'));
        $this->assertNull($pasajeros[1]->nombre);
        $this->assertNull($pasajeros[1]->documento);
        $this->assertNull($pasajeros[1]->pasajero_catalogo_id);
    }

    public function test_ca2_cliente_ruc_no_carga_nada(): void
    {
        $clienteId = $this->crearCliente('RUC', '20123456789', 'Empresa SAC');
        $pasajeros = $this->aceptar($this->crearAlternativa($clienteId));

        $this->assertNull($pasajeros[0]->nombre);
        $this->assertNull($pasajeros[0]->pasajero_catalogo_id);
        $this->assertSame(0, PasajeroCatalogo::where('cliente_id', $clienteId)->count());
    }

    public function test_ca3_documento_00000000_no_carga_nada(): void
    {
        $clienteId = $this->crearCliente('DNI', '00000000');
        $pasajeros = $this->aceptar($this->crearAlternativa($clienteId));

        $this->assertNull($pasajeros[0]->nombre);
        $this->assertNull($pasajeros[0]->pasajero_catalogo_id);
    }

    public function test_ca4_lo_elegido_por_el_vendedor_para_el_primer_lugar_se_respeta(): void
    {
        $elegido = PasajeroCatalogo::create(['nombre' => 'Elegido Por Vendedor']);
        $clienteId = $this->crearCliente('DNI', '45678902');

        // Un solo adulto, ya elegido: no queda lugar libre para el cliente.
        $pasajeros = $this->aceptar($this->crearAlternativa($clienteId, ['adulto']), [$elegido->id]);

        $this->assertSame($elegido->id, $pasajeros[0]->pasajero_catalogo_id);
        $this->assertNull($pasajeros[0]->nombre);
        $this->assertSame(0, PasajeroCatalogo::where('cliente_id', $clienteId)->count());
    }

    public function test_ca4b_elegido_el_primer_lugar_el_cliente_va_al_siguiente_adulto_libre(): void
    {
        $elegido = PasajeroCatalogo::create(['nombre' => 'Elegido Por Vendedor']);
        $clienteId = $this->crearCliente('DNI', '45678903');

        $pasajeros = $this->aceptar($this->crearAlternativa($clienteId), [$elegido->id]);

        $this->assertSame($elegido->id, $pasajeros[0]->pasajero_catalogo_id);
        $this->assertNull($pasajeros[0]->nombre);
        $this->assertSame('45678903', $pasajeros[1]->documento);
    }

    public function test_ca5_solo_ninos_e_infantes_no_carga_nada(): void
    {
        $clienteId = $this->crearCliente('DNI', '45678904');
        $pasajeros = $this->aceptar($this->crearAlternativa($clienteId, ['nino', 'infante']));

        foreach ($pasajeros as $pasajero) {
            $this->assertNull($pasajero->nombre);
            $this->assertNull($pasajero->pasajero_catalogo_id);
        }
        $this->assertSame(0, PasajeroCatalogo::where('cliente_id', $clienteId)->count());
    }

    public function test_ca6_ficha_existente_por_documento_se_reusa_y_se_completa_el_cliente(): void
    {
        $clienteId = $this->crearCliente('DNI', '45678905');
        $existente = PasajeroCatalogo::create(['nombre' => 'Ana T.']);
        PasajeroDocumento::create([
            'pasajero_catalogo_id' => $existente->id,
            'tipo_documento' => 'DNI',
            'numero_documento' => '45678905',
            'fecha_registro' => '2026-01-01',
        ]);

        $pasajeros = $this->aceptar($this->crearAlternativa($clienteId));

        $this->assertSame($existente->id, $pasajeros[0]->pasajero_catalogo_id);
        $this->assertSame($clienteId, $existente->fresh()->cliente_id);
        $this->assertSame(1, PasajeroDocumento::where('numero_documento', '45678905')->count());
    }

    public function test_ficha_con_otro_cliente_se_usa_sin_cambiar_su_cliente_id(): void
    {
        $otroCliente = $this->crearCliente('DNI', '11111111', 'Otro Cliente');
        $clienteId = $this->crearCliente('DNI', '45678906');
        $existente = PasajeroCatalogo::create(['cliente_id' => $otroCliente, 'nombre' => 'Otro Cliente']);
        PasajeroDocumento::create([
            'pasajero_catalogo_id' => $existente->id,
            'tipo_documento' => 'DNI',
            'numero_documento' => '45678906',
            'fecha_registro' => '2026-01-01',
        ]);

        $pasajeros = $this->aceptar($this->crearAlternativa($clienteId));

        $this->assertSame($existente->id, $pasajeros[0]->pasajero_catalogo_id);
        $this->assertSame($otroCliente, $existente->fresh()->cliente_id);
    }

    public function test_ca8_snd_y_tm_no_cargan_nada_y_ce_y_pas_si(): void
    {
        foreach (['SND', 'TM'] as $tipo) {
            $clienteId = $this->crearCliente($tipo, '12345678');
            $pasajeros = $this->aceptar($this->crearAlternativa($clienteId));
            $this->assertNull($pasajeros[0]->nombre, "El tipo $tipo no debe cargarse.");
        }

        foreach (['CE' => '001234567', 'PAS' => 'AB123456'] as $tipo => $numero) {
            $clienteId = $this->crearCliente($tipo, $numero);
            $pasajeros = $this->aceptar($this->crearAlternativa($clienteId));

            $this->assertSame($numero, $pasajeros[0]->documento);
            $this->assertSame($tipo, $this->fichaDe($numero)->documentos()->value('tipo_documento'));
        }
    }

    public function test_ca9_si_el_vendedor_eligio_al_cliente_para_otro_lugar_no_se_repite(): void
    {
        $clienteId = $this->crearCliente('DNI', '45678907');
        $ficha = PasajeroCatalogo::create(['cliente_id' => $clienteId, 'nombre' => 'Ana Torres Ríos']);
        PasajeroDocumento::create([
            'pasajero_catalogo_id' => $ficha->id,
            'tipo_documento' => 'DNI',
            'numero_documento' => '45678907',
            'fecha_registro' => '2026-01-01',
        ]);

        $pasajeros = $this->aceptar($this->crearAlternativa($clienteId), [null, $ficha->id]);

        $this->assertNull($pasajeros[0]->nombre);
        $this->assertNull($pasajeros[0]->pasajero_catalogo_id);
        $this->assertSame($ficha->id, $pasajeros[1]->pasajero_catalogo_id);
    }

    public function test_ca10_otro_documento_no_pisa_la_ficha_del_cliente(): void
    {
        $clienteId = $this->crearCliente('DNI', '45678908');
        $pasajeros = $this->aceptar($this->crearAlternativa($clienteId));
        $primero = $pasajeros[0];
        $fichaCliente = PasajeroCatalogo::where('cliente_id', $clienteId)->firstOrFail();

        // El vendedor escribe a otra persona; el frontend conserva el pasajero_catalogo_id.
        $this->actualizar($primero->id, 'Luis Gómez', '70000001', $fichaCliente->id);

        $fichaCliente->refresh();
        $this->assertSame('Ana Torres Ríos', $fichaCliente->nombre);
        $this->assertSame($clienteId, $fichaCliente->cliente_id);
        $this->assertSame('45678908', $fichaCliente->documentos()->value('numero_documento'));

        $nueva = $primero->fresh()->pasajero_catalogo_id;
        $this->assertNotSame($fichaCliente->id, $nueva);
        $this->assertNull(PasajeroCatalogo::find($nueva)->cliente_id);
        $this->assertSame('70000001', PasajeroCatalogo::find($nueva)->documentos()->value('numero_documento'));

        // Mismo documento y nombre corregido: se actualiza la misma ficha.
        $segundo = $pasajeros[1];
        $this->actualizar($segundo->id, 'Luis Gómez Pérez', '70000001', $nueva);
        $this->assertSame($nueva, $segundo->fresh()->pasajero_catalogo_id);
        $this->assertSame('Luis Gómez Pérez', PasajeroCatalogo::find($nueva)->nombre);
    }

    public function test_ca10b_mismo_documento_y_nombre_corregido_actualiza_la_ficha_del_cliente(): void
    {
        $clienteId = $this->crearCliente('DNI', '45678909');
        $primero = $this->aceptar($this->crearAlternativa($clienteId))[0];
        $ficha = PasajeroCatalogo::where('cliente_id', $clienteId)->firstOrFail();

        $this->actualizar($primero->id, 'Ana Torres Ríos de López', '45678909', $ficha->id);

        $this->assertSame($ficha->id, $primero->fresh()->pasajero_catalogo_id);
        $this->assertSame('Ana Torres Ríos de López', $ficha->fresh()->nombre);
        $this->assertSame($clienteId, $ficha->fresh()->cliente_id);
    }

    public function test_ca11_dos_cotizaciones_del_mismo_cliente_comparten_una_ficha(): void
    {
        $clienteId = $this->crearCliente('DNI', '45678910');
        $primera = $this->aceptar($this->crearAlternativa($clienteId));
        $segunda = $this->aceptar($this->crearAlternativa($clienteId));

        $this->assertSame(1, PasajeroCatalogo::where('cliente_id', $clienteId)->count());
        $this->assertSame($primera[0]->pasajero_catalogo_id, $segunda[0]->pasajero_catalogo_id);
        $this->assertSame(1, PasajeroDocumento::where('numero_documento', '45678910')->count());
    }

    public function test_venta_directa_no_recibe_pasajero_por_defecto(): void
    {
        $clienteId = $this->crearCliente('DNI', '45678911');
        $alternativa = \App\Models\AgenciaViajes\Alternativa::findOrFail($this->crearAlternativa($clienteId));
        $alternativa->update(['estado' => 'aceptada']);

        [$reserva] = app(ReservaController::class)->crearReservaDesdeAlternativa($alternativa->fresh());

        $this->assertNull(ReservaPasajero::where('reserva_id', $reserva->id)->orderBy('id')->first()->nombre);
        $this->assertSame(0, PasajeroCatalogo::where('cliente_id', $clienteId)->count());
    }

    private function actualizar(int $reservaPasajeroId, string $nombre, string $documento, int $catalogoId): void
    {
        $respuesta = app(ReservaPasajeroController::class)->update(
            new Request(['nombre' => $nombre, 'documento' => $documento, 'pasajero_catalogo_id' => $catalogoId]),
            (string) $reservaPasajeroId
        );
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
    }
}
