<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\OpcionMayoristaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\OpcionMayoristaTour;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\TourItinerarioItem;
use App\Services\AgenciaViajes\AlternativaPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Guardrail (18-sep-2026) — bug real reportado por el usuario: "+ Agregar
// tour nuevo" del itinerario de mayorista creaba SIEMPRE un PaquetePlantilla
// nuevo en el catálogo, incluso para días de pura logística ("Arribo a
// Cusco", "Retorno") que nunca se revenden como tour — polución creciente
// del catálogo de Paquetes/Tours con una entrada nueva por cada destino
// cotizado. OpcionMayoristaTour.paquete_plantilla_id ahora es nullable:
// una fila ad-hoc (nombre/descripcion propios, sin PaquetePlantilla detrás)
// resuelve el mismo caso sin tocar el catálogo para nada.
class TourAdhocMayoristaPdfTest extends TestCase
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

    private function invocarItinerario(Alternativa $alternativa): array
    {
        $service = app(AlternativaPdfService::class);
        $method = new \ReflectionMethod(AlternativaPdfService::class, 'itinerarioAlternativa');
        $method->setAccessible(true);

        return $method->invoke($service, $alternativa->fresh(['destinos.destinoAtractivo', 'items']));
    }

    /**
     * Alternativa con 1 destino, 1 OpcionMayorista, y 3 "días" de
     * itinerario en este orden: ad-hoc (Arribo), real de catálogo (Tour
     * Valle Sagrado, 1 paso), ad-hoc (Retorno) — el caso real reportado
     * (intercalados, no agrupados por tipo).
     */
    private function crearAlternativaConToursIntercalados(): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '99001122', 'full_name' => 'Cliente Test Adhoc',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-ADHOC-' . random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Cusco', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa Cusco', 'estado' => 'borrador',
            'moneda_cotizacion' => 'USD', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
        $destino = AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Cusco', 'orden' => 1]);

        $proveedor = Proveedor::create(['razon_social' => 'Mayorista Test Adhoc SAC', 'estado' => true]);
        $opcion = OpcionMayorista::create([
            'alternativa_id' => $alternativa->id, 'alternativa_destino_id' => $destino->id,
            'proveedor_id' => $proveedor->id, 'moneda' => 'USD', 'estado' => 'elegida',
        ]);

        AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'alternativa_destino_id' => $destino->id,
            'origen_tipo' => AlternativaItem::ORIGEN_MAYORISTA, 'opcion_mayorista_id' => $opcion->id,
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'USD',
            'costo_snapshot' => 500, 'precio_venta_snapshot' => 700, 'precio_convertido' => 700,
        ]);

        $destinoAtractivo = DestinoAtractivo::create(['nombre' => 'Valle Sagrado', 'tipo' => 'zona']);
        $tourReal = PaquetePlantilla::create([
            'codigo' => 'TOUR-ADHOC-' . random_int(10000, 99999), 'categoria' => 'nacional', 'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE,
            'nombre' => 'Tour Valle Sagrado', 'destino_atractivo_id' => $destinoAtractivo->id, 'duracion_horas' => 8, 'activo' => true,
        ]);
        TourItinerarioItem::create([
            'tour_id' => $tourReal->id, 'dia_relativo' => 1, 'orden' => 0, 'descripcion' => 'Recorrido por el Valle Sagrado.',
            'destino_atractivo_id' => $destinoAtractivo->id,
        ]);

        // orden 1: ad-hoc (Arribo) — sin paquete_plantilla_id.
        // Request::create() (no "new Request([...])") — el constructor
        // directo mete el array en la bolsa de query (GET), así que
        // $request->isMethod('get') seguía dando true y tours() tomaba la
        // rama de LISTAR en vez de crear (encontrado corriendo este test:
        // los 3 tours nunca se creaban, sin ningún error visible).
        app(OpcionMayoristaController::class)->tours(Request::create('/', 'POST', [
            'nombre' => 'Arribo a Cusco', 'descripcion' => 'Recepción en el aeropuerto y traslado al hotel.', 'orden' => 1,
        ]), (string) $opcion->id);

        // orden 2: real de catálogo.
        app(OpcionMayoristaController::class)->tours(Request::create('/', 'POST', [
            'paquete_plantilla_id' => $tourReal->id, 'orden' => 2,
        ]), (string) $opcion->id);

        // orden 3: ad-hoc (Retorno).
        app(OpcionMayoristaController::class)->tours(Request::create('/', 'POST', [
            'nombre' => 'Retorno', 'descripcion' => 'Traslado al aeropuerto para el vuelo de salida.', 'orden' => 3,
        ]), (string) $opcion->id);

        return compact('alternativa', 'opcion', 'tourReal');
    }

    public function test_tour_adhoc_se_crea_sin_paquete_plantilla(): void
    {
        ['opcion' => $opcion] = $this->crearAlternativaConToursIntercalados();

        $adhoc = OpcionMayoristaTour::where('opcion_mayorista_id', $opcion->id)->where('orden', 1)->first();
        $this->assertSame('Arribo a Cusco', $adhoc->nombre);
        $this->assertNull($adhoc->paquete_plantilla_id);

        // No se creó NINGÚN PaquetePlantilla nuevo para el ad-hoc — solo
        // existe el real (Tour Valle Sagrado) que se creó a mano en el
        // fixture.
        $this->assertSame(1, PaquetePlantilla::count());
    }

    public function test_itinerario_intercala_adhoc_y_reales_en_el_orden_correcto(): void
    {
        ['alternativa' => $alternativa] = $this->crearAlternativaConToursIntercalados();

        $bloques = $this->invocarItinerario($alternativa);

        $this->assertCount(1, $bloques);
        $pasos = $bloques[0]['pasos'];
        $this->assertCount(3, $pasos);

        $this->assertSame(1, $pasos[0]['dia']);
        $this->assertSame('Arribo a Cusco', $pasos[0]['tour_nombre']);
        $this->assertStringContainsString('Recepción en el aeropuerto', $pasos[0]['descripcion']);
        $this->assertSame([], $pasos[0]['tour_fotos']);
        $this->assertNull($pasos[0]['atractivo_nombre']);

        $this->assertSame(2, $pasos[1]['dia']);
        $this->assertSame('Tour Valle Sagrado', $pasos[1]['tour_nombre']);
        $this->assertStringContainsString('Recorrido por el Valle Sagrado', $pasos[1]['descripcion']);
        $this->assertSame('Valle Sagrado', $pasos[1]['atractivo_nombre']);

        $this->assertSame(3, $pasos[2]['dia']);
        $this->assertSame('Retorno', $pasos[2]['tour_nombre']);
        $this->assertStringContainsString('Traslado al aeropuerto', $pasos[2]['descripcion']);
    }

    private function crearOpcionMayoristaMinima(string $razonSocial): OpcionMayorista
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10000000, 99999999), 'full_name' => 'Cliente Test Mínimo',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-MIN-' . random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Test', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa Mínima', 'estado' => 'borrador',
            'moneda_cotizacion' => 'USD', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
        $proveedor = Proveedor::create(['razon_social' => $razonSocial, 'estado' => true]);

        return OpcionMayorista::create([
            'alternativa_id' => $alternativa->id, 'proveedor_id' => $proveedor->id, 'moneda' => 'USD', 'estado' => 'candidata',
        ]);
    }

    public function test_rechaza_cuando_no_llega_ningun_tour(): void
    {
        $opcion = $this->crearOpcionMayoristaMinima('Mayorista Test Vacio SAC');

        $response = app(OpcionMayoristaController::class)->tours(Request::create('/', 'POST', ['orden' => 1]), (string) $opcion->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('exactamente un tour', $response->getData(true)['message']);
    }

    public function test_rechaza_cuando_llegan_ambos_a_la_vez(): void
    {
        $opcion = $this->crearOpcionMayoristaMinima('Mayorista Test Ambos SAC');
        $destinoAtractivo = DestinoAtractivo::create(['nombre' => 'Test Ambos', 'tipo' => 'zona']);
        $tour = PaquetePlantilla::create([
            'codigo' => 'TOUR-AMBOS-' . random_int(10000, 99999), 'categoria' => 'nacional', 'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE,
            'nombre' => 'Tour Test Ambos', 'destino_atractivo_id' => $destinoAtractivo->id, 'duracion_horas' => 4, 'activo' => true,
        ]);

        $response = app(OpcionMayoristaController::class)->tours(Request::create('/', 'POST', [
            'paquete_plantilla_id' => $tour->id, 'nombre' => 'Ad-hoc también', 'orden' => 1,
        ]), (string) $opcion->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('exactamente un tour', $response->getData(true)['message']);
    }

    public function test_actualizar_orden_tour_permite_editar_contenido_ad_hoc(): void
    {
        ['opcion' => $opcion] = $this->crearAlternativaConToursIntercalados();
        $adhoc = OpcionMayoristaTour::where('opcion_mayorista_id', $opcion->id)->where('orden', 1)->first();

        $response = app(OpcionMayoristaController::class)->actualizarOrdenTour(new Request([
            'orden' => 1, 'nombre' => 'Arribo a Cusco (editado)', 'descripcion' => 'Texto corregido.',
        ]), (string) $adhoc->id);

        $this->assertSame(200, $response->getStatusCode());
        $fresh = $adhoc->fresh();
        $this->assertSame('Arribo a Cusco (editado)', $fresh->nombre);
        $this->assertSame('Texto corregido.', $fresh->descripcion);
    }

    public function test_actualizar_orden_tour_rechaza_nombre_en_un_tour_de_catalogo(): void
    {
        ['opcion' => $opcion, 'tourReal' => $tourReal] = $this->crearAlternativaConToursIntercalados();
        $real = OpcionMayoristaTour::where('opcion_mayorista_id', $opcion->id)->where('paquete_plantilla_id', $tourReal->id)->first();

        $response = app(OpcionMayoristaController::class)->actualizarOrdenTour(new Request([
            'orden' => 2, 'nombre' => 'No debería aplicar',
        ]), (string) $real->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('catálogo', $response->getData(true)['message']);
    }
}
