<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\VentaDirectaController;
use App\Models\AgenciaViajes\ReservaPasajero;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// T-046 — Venta directa: residencia por pasajero, nombres opcionales y el cliente como
// primer pasajero. Postgres real, transacción por test revertida.
class VentaDirectaPasajerosTest extends TestCase
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

    private function crearCliente(string $tipo = 'DNI', ?string $numero = null, string $nombre = 'Ana Torres Ríos'): int
    {
        return DB::table('clients')->insertGetId([
            'type_document' => $tipo, 'n_document' => $numero ?? (string) random_int(10000000, 99999999),
            'full_name' => $nombre, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function linea(): array
    {
        return [
            'origen_tipo' => 'manual', 'dia' => 1, 'descripcion_manual' => 'Cena show',
            'precio_venta_snapshot' => 80, 'costo_snapshot' => 0, 'cantidad' => 1,
        ];
    }

    private function crear(array $pax, array $extra = [], ?int $clienteId = null): array
    {
        return app(VentaDirectaController::class)->store(new Request($extra + [
            'cliente_id' => $clienteId ?? $this->crearCliente(),
            'destino' => 'Moyobamba',
            'fecha_servicio' => '2026-11-04',
            'pax' => $pax,
            'lineas' => [$this->linea()],
        ]))->getData(true);
    }

    private function pasajerosDe(array $respuesta)
    {
        return ReservaPasajero::where('reserva_id', $respuesta['reserva']['id'])->orderBy('id')->get();
    }

    public function test_ca1_la_residencia_llega_a_los_pasajeros_de_la_reserva(): void
    {
        $r = $this->crear([
            ['edad' => 30, 'residencia' => 'nacional'],
            ['edad' => 30, 'residencia' => 'nacional'],
            ['edad' => 30, 'residencia' => 'extranjero'],
        ], ['cliente_viaja' => false]);

        $this->assertSame(200, $r['code']);
        $this->assertSame(['nacional', 'nacional', 'extranjero'], $this->pasajerosDe($r)->pluck('residencia')->all());
    }

    public function test_ca2_sin_residencia_es_nacional_y_una_invalida_se_rechaza(): void
    {
        $r = $this->crear([['edad' => 30], ['edad' => 8]]);
        $this->assertSame(['nacional', 'nacional'], $this->pasajerosDe($r)->pluck('residencia')->all());

        $mala = $this->crear([['edad' => 30, 'residencia' => 'marciano']]);
        $this->assertSame(422, $mala['code']);
    }

    public function test_ca3b_el_cliente_persona_natural_es_el_primer_adulto_con_su_ficha(): void
    {
        $clienteId = $this->crearCliente('DNI', '45678912', 'Ana Torres Ríos');
        $r = $this->crear([['edad' => 8], ['edad' => 30], ['edad' => 30]], [], $clienteId);

        $p = $this->pasajerosDe($r);
        $this->assertNull($p[0]->nombre); // el niño
        $this->assertSame('Ana Torres Ríos', $p[1]->nombre);
        $this->assertSame('45678912', $p[1]->documento);
        $this->assertNull($p[2]->nombre);
        $this->assertNotNull($p[1]->pasajero_catalogo_id);
        $this->assertSame($clienteId, (int) DB::table('pasajeros_catalogo')->where('id', $p[1]->pasajero_catalogo_id)->value('cliente_id'));
    }

    public function test_ca3b_con_cliente_viaja_false_nadie_lleva_nombre(): void
    {
        $antes = DB::table('pasajeros_catalogo')->count();
        $r = $this->crear([['edad' => 30], ['edad' => 30]], ['cliente_viaja' => false]);

        $this->assertSame([null, null], $this->pasajerosDe($r)->pluck('nombre')->all());
        $this->assertSame($antes, DB::table('pasajeros_catalogo')->count());
    }

    public function test_ca3b_un_cliente_con_ruc_no_completa_a_nadie(): void
    {
        $antes = DB::table('pasajeros_catalogo')->count();
        $r = $this->crear([['edad' => 30]], [], $this->crearCliente('RUC', '20123456789', 'Empresa SAC'));

        $this->assertNull($this->pasajerosDe($r)[0]->nombre);
        $this->assertSame($antes, DB::table('pasajeros_catalogo')->count());
    }

    public function test_si_el_vendedor_escribio_otra_persona_en_el_primero_manda_lo_escrito_y_no_se_crea_la_ficha_del_cliente(): void
    {
        $clienteId = $this->crearCliente('DNI', '45678912');
        $r = $this->crear([
            ['edad' => 30, 'nombre' => 'Luis Paz', 'documento' => '70112233'],
            ['edad' => 30],
        ], [], $clienteId);

        $p = $this->pasajerosDe($r);
        $this->assertSame('Luis Paz', $p[0]->nombre);
        $this->assertNull($p[1]->nombre); // el cliente NO pasa al segundo adulto
        $this->assertSame(0, DB::table('pasajeros_catalogo')->where('cliente_id', $clienteId)->count());
    }

    public function test_ca4_los_nombres_escritos_llegan_a_la_reserva_y_al_catalogo(): void
    {
        $r = $this->crear([
            ['edad' => 30, 'nombre' => 'Rosa Vela', 'documento' => '41112223'],
            ['edad' => 8, 'nombre' => 'Hijo Vela'], // sin documento: se guarda, sin ficha
            ['edad' => 30],
        ], ['cliente_viaja' => false]);

        $p = $this->pasajerosDe($r);
        $this->assertSame('Rosa Vela', $p[0]->nombre);
        $this->assertSame('41112223', $p[0]->documento);
        $this->assertNotNull($p[0]->pasajero_catalogo_id);
        $this->assertSame('41112223', DB::table('pasajero_documentos')->where('pasajero_catalogo_id', $p[0]->pasajero_catalogo_id)->value('numero_documento'));
        $this->assertSame('Hijo Vela', $p[1]->nombre);
        $this->assertNull($p[1]->pasajero_catalogo_id);
        $this->assertNull($p[2]->nombre);
    }

    public function test_nombre_y_documento_tienen_largo_maximo(): void
    {
        $r = $this->crear([['edad' => 30, 'nombre' => str_repeat('a', 251)]]);
        $this->assertSame(422, $r['code']);

        $r = $this->crear([['edad' => 30, 'documento' => str_repeat('1', 51)]]);
        $this->assertSame(422, $r['code']);
    }

    public function test_la_vista_previa_devuelve_los_umbrales_y_los_extranjeros_en_el_desglose(): void
    {
        $tarifa = DB::table('proveedor_tarifas')->insertGetId($this->tarifaBase());
        $r = app(VentaDirectaController::class)->vistaPrevia(new Request([
            'fecha_servicio' => '2026-11-04',
            'pax' => [
                ['edad' => 30, 'residencia' => 'nacional'],
                ['edad' => 30, 'residencia' => 'extranjero'],
                ['edad' => 8, 'residencia' => 'extranjero'],
            ],
            'lineas' => [['origen_tipo' => 'proveedor', 'dia' => 1, 'proveedor_tarifa_id' => $tarifa, 'modo_precio' => 'por_persona', 'cantidad' => 1]],
        ]))->getData(true);

        $this->assertSame(200, $r['code']);
        $this->assertArrayHasKey('infante_max', $r['umbrales']);
        $this->assertArrayHasKey('nino_max', $r['umbrales']);
        $d = $r['lineas'][0]['por_persona']['desglose'];
        $this->assertSame([2, 1], [$d['adulto']['cantidad'], $d['adulto']['extranjeros']]);
        $this->assertSame([1, 1], [$d['nino']['cantidad'], $d['nino']['extranjeros']]);
        $this->assertSame(0, $d['infante']['extranjeros']);
    }

    public function test_los_umbrales_de_edad_salen_de_la_configuracion_de_la_agencia_con_el_permiso_de_venta_directa(): void
    {
        DB::table('configuracion_agencia')->update(['edad_max_infante' => 3, 'edad_max_nino' => 11]);
        if (DB::table('configuracion_agencia')->count() === 0) {
            DB::table('configuracion_agencia')->insert(['edad_max_infante' => 3, 'edad_max_nino' => 11, 'created_at' => now(), 'updated_at' => now()]);
        }

        $r = app(VentaDirectaController::class)->umbrales()->getData(true);
        $this->assertSame(['infante_max' => 3, 'nino_max' => 11], $r['umbrales']);

        $ruta = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->first(fn ($r) => str_ends_with($r->uri(), 'venta-directa/umbrales-edad') && in_array('GET', $r->methods(), true));
        $this->assertNotNull($ruta);
        $this->assertContains('permission:reservas.crear', $ruta->gatherMiddleware());
    }

    private function tarifaBase(): array
    {
        $destino = DB::table('destinos_atractivos')->insertGetId(['nombre' => 'Lamas '.uniqid(), 'tipo' => 'lugar', 'created_at' => now(), 'updated_at' => now()]);
        $servicio = DB::table('servicios')->insertGetId(['nombre' => 'Tour', 'created_at' => now(), 'updated_at' => now()]);
        $ds = DB::table('destino_servicio')->insertGetId(['destino_atractivo_id' => $destino, 'servicio_id' => $servicio, 'created_at' => now(), 'updated_at' => now()]);
        $prov = DB::table('proveedores')->insertGetId(['razon_social' => 'Prov', 'estado' => true, 'created_at' => now(), 'updated_at' => now()]);
        $ps = DB::table('proveedor_servicios')->insertGetId(['proveedor_id' => $prov, 'destino_servicio_id' => $ds, 'created_at' => now(), 'updated_at' => now()]);

        return [
            'proveedor_servicio_id' => $ps, 'tipo_tarifa' => 'publica', 'modalidad' => 'compartido', 'moneda' => 'PEN',
            'precio_costo' => 20, 'margen_tipo' => 'fijo', 'margen_valor' => 10,
            'precio_venta_adulto' => 50, 'precio_venta_nino' => 25,
            'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
            'created_at' => now(), 'updated_at' => now(),
        ];
    }
}
