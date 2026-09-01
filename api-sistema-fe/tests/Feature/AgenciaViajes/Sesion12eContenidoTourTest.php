<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ContenidoTourController;
use App\Http\Controllers\AgenciaViajes\OpcionMayoristaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\ContenidoTour;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorTipo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Sesión 12e — brief PEGAR-EN-CLAUDE-CODE-12e-contenido-tour.md. Mismo
// patrón de infraestructura que el resto de la suite de agencia-viajes:
// Postgres real (sistemafe_test_migrations), transacción por test
// revertida.
class Sesion12eContenidoTourTest extends TestCase
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

    // §5 del brief — ContenidoTourController.

    public function test_store_rechaza_nombre_duplicado_case_insensitive_en_la_misma_categoria(): void
    {
        ContenidoTour::create(['nombre' => 'Excursión San Blas', 'categoria' => 'opcional', 'activo' => true]);

        $response = app(ContenidoTourController::class)->store(
            new Request(['nombre' => 'excursión san blas', 'categoria' => 'opcional'])
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Ya existe', $response->getData(true)['message']);
    }

    public function test_store_permite_mismo_nombre_en_categoria_distinta(): void
    {
        ContenidoTour::create(['nombre' => 'City Tour', 'categoria' => 'incluido', 'activo' => true]);

        $response = app(ContenidoTourController::class)->store(
            new Request(['nombre' => 'City Tour', 'categoria' => 'excursion'])
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_index_filtra_por_categoria_y_texto(): void
    {
        ContenidoTour::create(['nombre' => 'Isla Taboga Full-Day', 'categoria' => 'opcional', 'activo' => true]);
        ContenidoTour::create(['nombre' => 'Zona Libre de Colón', 'categoria' => 'opcional', 'activo' => true]);
        ContenidoTour::create(['nombre' => 'Isla Taboga (incluido)', 'categoria' => 'incluido', 'activo' => true]);

        $response = app(ContenidoTourController::class)->index(new Request(['categoria' => 'opcional', 'q' => 'taboga']));

        $resultados = $response->getData(true)['contenido_tour'];
        $this->assertCount(1, $resultados);
        $this->assertSame('Isla Taboga Full-Day', $resultados[0]['nombre']);
    }

    public function test_index_no_devuelve_contenido_inactivo(): void
    {
        ContenidoTour::create(['nombre' => 'Tour Descontinuado', 'categoria' => 'opcional', 'activo' => false]);

        $response = app(ContenidoTourController::class)->index(new Request(['q' => 'descontinuado']));

        $this->assertCount(0, $response->getData(true)['contenido_tour']);
    }

    // §6/§3 del brief — snapshot al vincular desde OpcionMayoristaController.

    private function crearAlternativaConDestino(): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '33221100', 'full_name' => 'Cliente Test 12e',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-07' . random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Destino Test 12e', 'fecha_viaje_desde' => '2026-10-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia', 'total' => 100,
        ]);
        AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Destino Test 12e', 'orden' => 1]);

        return $alternativa;
    }

    private function crearProveedorMayorista(): Proveedor
    {
        $tipoMayorista = ProveedorTipo::where('slug', ProveedorTipo::SLUG_MAYORISTA)->first();
        if (! $tipoMayorista) {
            $this->markTestSkipped('Catálogo central proveedor_tipos sin el slug agencia-mayorista en este entorno.');
        }

        return Proveedor::create(['razon_social' => 'Mayorista Test 12e SAC', 'estado' => true, 'tipo_id' => $tipoMayorista->id]);
    }

    public function test_store_de_opcion_mayorista_copia_snapshot_del_contenido_tour(): void
    {
        $alternativa = $this->crearAlternativaConDestino();
        $proveedor = $this->crearProveedorMayorista();
        $contenido = ContenidoTour::create([
            'nombre' => 'City Tour Panamá', 'categoria' => 'incluido',
            'descripcion' => 'Recorrido por el casco antiguo.', 'fotos' => ['foto1.jpg', 'foto2.jpg'],
            'activo' => true,
        ]);

        $response = app(OpcionMayoristaController::class)->store(
            new Request(['proveedor_id' => $proveedor->id, 'moneda' => 'PEN', 'contenido_tour_id' => $contenido->id]),
            (string) $alternativa->id
        );

        $this->assertSame(200, $response->getStatusCode());
        $opcion = OpcionMayorista::find($response->getData(true)['opcion_mayorista']['id']);
        $this->assertSame($contenido->id, $opcion->contenido_tour_id);
        $this->assertSame('Recorrido por el casco antiguo.', $opcion->contenido_tour_descripcion_snapshot);
        $this->assertSame(['foto1.jpg', 'foto2.jpg'], $opcion->contenido_tour_fotos_snapshot);
    }

    public function test_editar_contenido_tour_despues_de_vincular_no_cambia_el_snapshot_ya_guardado(): void
    {
        $alternativa = $this->crearAlternativaConDestino();
        $proveedor = $this->crearProveedorMayorista();
        $contenido = ContenidoTour::create([
            'nombre' => 'Excursión San Blas', 'categoria' => 'excursion',
            'descripcion' => 'Descripción original.', 'activo' => true,
        ]);

        $response = app(OpcionMayoristaController::class)->store(
            new Request(['proveedor_id' => $proveedor->id, 'moneda' => 'PEN', 'contenido_tour_id' => $contenido->id]),
            (string) $alternativa->id
        );
        $opcionId = $response->getData(true)['opcion_mayorista']['id'];

        // Congelamiento (§2/§23.1.8 de la auditoría): editar el contenido
        // maestro DESPUÉS de vincular no debe reescribir en silencio el
        // snapshot de una OpcionMayorista ya creada.
        $contenido->update(['descripcion' => 'Descripción editada después.']);

        $opcion = OpcionMayorista::find($opcionId);
        $this->assertSame('Descripción original.', $opcion->contenido_tour_descripcion_snapshot);
    }

    public function test_store_sin_contenido_tour_id_deja_snapshot_null(): void
    {
        $alternativa = $this->crearAlternativaConDestino();
        $proveedor = $this->crearProveedorMayorista();

        $response = app(OpcionMayoristaController::class)->store(
            new Request(['proveedor_id' => $proveedor->id, 'moneda' => 'PEN']),
            (string) $alternativa->id
        );

        $opcion = OpcionMayorista::find($response->getData(true)['opcion_mayorista']['id']);
        $this->assertNull($opcion->contenido_tour_id);
        $this->assertNull($opcion->contenido_tour_descripcion_snapshot);
    }

    public function test_opcionales_copia_snapshot_del_contenido_tour(): void
    {
        $alternativa = $this->crearAlternativaConDestino();
        $proveedor = $this->crearProveedorMayorista();
        $opcion = OpcionMayorista::create([
            'alternativa_id' => $alternativa->id, 'proveedor_id' => $proveedor->id, 'moneda' => 'PEN', 'estado' => 'candidata',
        ]);
        $contenido = ContenidoTour::create([
            'nombre' => 'Isla Taboga Full-Day', 'categoria' => 'opcional', 'descripcion' => 'Día completo en la isla.', 'activo' => true,
        ]);

        $request = Request::create('/opciones-mayorista/' . $opcion->id . '/opcionales', 'POST', [
            'nombre' => 'Isla Taboga Full-Day', 'precio_por_persona' => 96, 'moneda' => 'USD', 'contenido_tour_id' => $contenido->id,
        ]);
        $response = app(OpcionMayoristaController::class)->opcionales($request, (string) $opcion->id);

        $this->assertSame(200, $response->getStatusCode());
        $opcional = \App\Models\AgenciaViajes\OpcionMayoristaOpcional::find($response->getData(true)['opcion_mayorista_opcional']['id']);
        $this->assertSame($contenido->id, $opcional->contenido_tour_id);
        $this->assertSame('Día completo en la isla.', $opcional->contenido_tour_descripcion_snapshot);
    }
}
