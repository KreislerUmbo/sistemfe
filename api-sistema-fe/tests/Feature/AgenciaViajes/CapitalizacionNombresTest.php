<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaController;
use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\DestinoAtractivoController;
use App\Http\Controllers\AgenciaViajes\GuiaController;
use App\Http\Controllers\AgenciaViajes\PaquetePlantillaController;
use App\Http\Controllers\AgenciaViajes\ProveedorController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\ProveedorTipo;
use App\Models\AgenciaViajes\ProveedorTipoConfig;
use App\Models\AgenciaViajes\TipoCambioAgencia;
use App\Services\AgenciaViajes\FotoUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// 29-ago-2026 — hallazgo del usuario: nombres/títulos de destinos,
// servicios, proveedores (nombre_comercial), guías, tours/paquetes,
// alternativas y aerolíneas se escriben sin ningún criterio de
// capitalización. Se agrega TextoFormatoService::capitalizarNombrePropio()
// (cubierto aparte en Tests\Unit\TextoFormatoServiceTest) y se conecta en
// los 7 puntos de escritura reales — este archivo prueba el CABLEADO en
// cada controller, no la lógica de capitalización en sí. Alcance
// confirmado con el usuario: solo hacia adelante (no reescribe lo ya
// guardado), nunca razon_social ni ningún campo de clientes.
class CapitalizacionNombresTest extends TestCase
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

        Storage::fake('public');

        // users.role_id tiene default(1) a nivel de Postgres — User::factory()
        // revienta con FK violation sin esto (mismo fixture que
        // ReservaQuitarItemsPasajerosTest/SaleControllerSerieComprobanteTest).
        DB::table('roles')->insert([
            'id' => 1, 'name' => 'test-role-default', 'guard_name' => 'api',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function crearAlternativaConPasajeros(): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '12345678', 'full_name' => 'Cliente Test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-CAP-0001', 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'created_at' => now(), 'updated_at' => now(),
        ]);
        CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 30]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'moneda_cotizacion' => 'PEN',
            'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
    }

    public function test_destino_atractivo_capitaliza_nombre_al_crear(): void
    {
        $controller = new DestinoAtractivoController(new FotoUploadService());

        $respuesta = $controller->store(new Request(['nombre' => 'ALTO MAYO', 'tipo' => 'zona']));

        $this->assertSame('Alto Mayo', $respuesta->getData(true)['destino_atractivo']['nombre']);
    }

    public function test_guia_capitaliza_nombre_al_crear(): void
    {
        $respuesta = app(GuiaController::class)->store(new Request([
            'nombre' => 'juan perez', 'documento' => '10000001', 'telefono' => '999000001',
        ]));

        $this->assertSame('Juan Perez', $respuesta->getData(true)['guia']['nombre']);
    }

    public function test_proveedor_capitaliza_solo_nombre_comercial_no_razon_social(): void
    {
        // proveedor_tipos es CENTRAL (solo lectura acá, nunca se escribe
        // en central desde un test) — mismo criterio que PrecioPorPasajeroTest.
        // Cualquier tipo real alcanza, no importa cuál.
        $tipo = ProveedorTipo::first();
        if (! $tipo) {
            $this->markTestSkipped('Catálogo central sin ningún proveedor_tipo en este entorno.');
        }
        ProveedorTipoConfig::create(['proveedor_tipo_id' => $tipo->id, 'habilitado' => true]);

        $controller = new ProveedorController(new FotoUploadService());
        $respuesta = $controller->store(new Request([
            'razon_social' => 'transportes selva verde sac', 'nombre_comercial' => 'transportes selva verde',
            'tipo_id' => $tipo->id,
        ]));

        $payload = $respuesta->getData(true);
        $this->assertSame('transportes selva verde sac', $payload['proveedor']['razon_social'], 'razon_social NUNCA se toca — es dato fiscal.');
        $this->assertSame('Transportes Selva Verde', $payload['proveedor']['nombre_comercial']);
    }

    public function test_paquete_plantilla_capitaliza_nombre_al_crear(): void
    {
        $destino = DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);

        $respuesta = app(PaquetePlantillaController::class)->store(new Request([
            'categoria' => 'local', 'nombre' => 'CIRCUITO TURISTICO RIOJA',
            'destino_atractivo_id' => $destino->id, 'duracion_horas' => 4,
        ]));

        $this->assertSame('Circuito Turistico Rioja', $respuesta->getData(true)['paquete_plantilla']['nombre']);
    }

    public function test_alternativa_capitaliza_nombre_tipeado_al_crear(): void
    {
        $usuario = \App\Models\User::factory()->create();
        TipoCambioAgencia::create(['fecha' => now()->toDateString(), 'origen' => 'dia', 'valor' => 3.75, 'registrado_por' => $usuario->id]);
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '87654321', 'full_name' => 'Cliente Test 2',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-CAP-0002', 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $respuesta = app(AlternativaController::class)->store(new Request([
            'nombre' => 'plan economico', 'moneda_cotizacion' => 'PEN', 'tipo_cambio_origen' => 'dia',
        ]), (string) $cotizacionId);

        $this->assertSame('Plan Economico', $respuesta->getData(true)['alternativa']['nombre']);
    }

    // Nota: el fallback autogenerado ("Alternativa {letra}") del ternario
    // en store() es en la práctica inalcanzable por la API pública —
    // 'nombre' es 'required', así que Laravel rechaza un '' con 422 antes
    // de llegar al controller. No es parte de este cambio (el ternario ya
    // estaba así antes), así que no se prueba acá.

    public function test_alternativa_capitaliza_nombre_al_actualizar(): void
    {
        $alternativa = $this->crearAlternativaConPasajeros();

        $respuesta = app(AlternativaController::class)->update(new Request(['nombre' => 'plan premium']), (string) $alternativa->id);

        $this->assertSame('Plan Premium', $respuesta->getData(true)['alternativa']['nombre']);
    }

    public function test_pasaje_aereo_capitaliza_aerolinea_al_crear(): void
    {
        $alternativa = $this->crearAlternativaConPasajeros();

        $respuesta = app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'pasaje_aereo',
            'aerolinea' => 'latam peru', 'moneda' => 'PEN', 'tarifa_base_adulto' => 350,
        ]), (string) $alternativa->id);

        $item = $respuesta->getData(true)['alternativa_item'];
        $this->assertSame('Latam Peru', $item['cotizacion_pasaje_aereo']['aerolinea'] ?? null);
    }
}
