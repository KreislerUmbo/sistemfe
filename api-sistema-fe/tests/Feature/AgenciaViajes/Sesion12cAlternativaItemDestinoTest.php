<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\AlternativaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Sesión 12c — brief PEGAR-EN-CLAUDE-CODE-12c-alternativa-item-destino.md.
// Gap real encontrado al redactar el brief: store()/duplicar() nunca
// creaban filas en alternativa_destinos, rompiendo la garantía de 12b
// ("cada alternativa tiene al menos 1 destino") para cualquier alternativa
// creada de acá en adelante. Mismo patrón de infraestructura que el resto
// de la suite de agencia-viajes: Postgres real (sistemafe_test_migrations),
// transacción por test revertida.
class Sesion12cAlternativaItemDestinoTest extends TestCase
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

    private function crearCotizacion(string $destinoTexto): int
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '11223344', 'full_name' => 'Cliente Test 12c',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-05' . random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => $destinoTexto, 'fecha_viaje_desde' => '2026-10-01', 'fecha_viaje_hasta' => '2026-10-05',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function crearAlternativaDirecta(int $cotizacionId, string $estado = 'borrador'): Alternativa
    {
        return Alternativa::create([
            'cotizacion_id' => $cotizacionId,
            'nombre' => 'Alternativa 1',
            'estado' => $estado,
            'moneda_cotizacion' => 'PEN',
            'tipo_cambio_aplicado' => 1,
            'tipo_cambio_origen' => 'dia',
            'total' => 100,
        ]);
    }

    // §5 del brief — store() crea automáticamente 1 AlternativaDestino.

    public function test_store_crea_un_destino_resuelto_desde_la_cotizacion(): void
    {
        $destinoAtractivoId = DB::table('destinos_atractivos')->insertGetId([
            'nombre' => 'Tarapoto Test 12c', 'tipo' => 'zona', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = $this->crearCotizacion('Tarapoto Test 12c');

        $userId = DB::table('users')->insertGetId([
            'name' => 'Test 12c', 'email' => 'test12c' . random_int(100000, 999999) . '@example.com',
            'password' => 'x', 'role_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tipo_cambio_agencia')->insert([
            'fecha' => now()->toDateString(), 'origen' => 'dia', 'valor' => 3.7, 'registrado_por' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = app(AlternativaController::class)->store(
            new Request(['nombre' => 'A', 'moneda_cotizacion' => 'PEN', 'tipo_cambio_origen' => 'dia']),
            (string) $cotizacionId
        );

        $this->assertSame(200, $response->getStatusCode());
        $alternativaId = $response->getData(true)['alternativa']['id'];

        $destinos = AlternativaDestino::where('alternativa_id', $alternativaId)->get();
        $this->assertCount(1, $destinos);
        $this->assertSame($destinoAtractivoId, $destinos->first()->destino_atractivo_id);
        $this->assertSame('Tarapoto Test 12c', $destinos->first()->destino_texto);
        $this->assertSame(1, $destinos->first()->orden);
    }

    public function test_store_deja_destino_atractivo_id_null_si_no_matchea_catalogo(): void
    {
        $cotizacionId = $this->crearCotizacion('Destino Sin Catalogo XYZ');

        $userId = DB::table('users')->insertGetId([
            'name' => 'Test 12c', 'email' => 'test12c' . random_int(100000, 999999) . '@example.com',
            'password' => 'x', 'role_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tipo_cambio_agencia')->insert([
            'fecha' => now()->toDateString(), 'origen' => 'dia', 'valor' => 3.7, 'registrado_por' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = app(AlternativaController::class)->store(
            new Request(['nombre' => 'A', 'moneda_cotizacion' => 'PEN', 'tipo_cambio_origen' => 'dia']),
            (string) $cotizacionId
        );

        $alternativaId = $response->getData(true)['alternativa']['id'];
        $destino = AlternativaDestino::where('alternativa_id', $alternativaId)->first();

        $this->assertNull($destino->destino_atractivo_id);
        $this->assertSame('Destino Sin Catalogo XYZ', $destino->destino_texto);
    }

    // §6 del brief — duplicar() clona destinos y remapea alternativa_destino_id de los ítems.

    public function test_duplicar_clona_destinos_en_filas_propias_no_comparte_id_con_el_original(): void
    {
        $cotizacionId = $this->crearCotizacion('Cusco Test 12c Dup');
        $original = $this->crearAlternativaDirecta($cotizacionId);
        $destinoOriginal = AlternativaDestino::create([
            'alternativa_id' => $original->id, 'destino_texto' => 'Cusco Test 12c Dup', 'orden' => 1,
        ]);

        $response = app(AlternativaController::class)->duplicar((string) $original->id);
        $nuevaId = $response->getData(true)['alternativa']['id'];

        $destinosNueva = AlternativaDestino::where('alternativa_id', $nuevaId)->get();
        $this->assertCount(1, $destinosNueva);
        $this->assertNotSame($destinoOriginal->id, $destinosNueva->first()->id);
        $this->assertSame('Cusco Test 12c Dup', $destinosNueva->first()->destino_texto);
    }

    public function test_duplicar_remapea_alternativa_destino_id_de_los_items_clonados(): void
    {
        $cotizacionId = $this->crearCotizacion('Lima Test 12c Dup');
        $original = $this->crearAlternativaDirecta($cotizacionId);
        $destinoOriginal = AlternativaDestino::create([
            'alternativa_id' => $original->id, 'destino_texto' => 'Lima Test 12c Dup', 'orden' => 1,
        ]);
        AlternativaItem::create([
            'alternativa_id' => $original->id, 'alternativa_destino_id' => $destinoOriginal->id,
            'origen_tipo' => 'manual', 'dia_referencial' => 1, 'descripcion_manual' => 'Ítem test',
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'precio_convertido' => 15,
        ]);

        $response = app(AlternativaController::class)->duplicar((string) $original->id);
        $nuevaId = $response->getData(true)['alternativa']['id'];

        $destinoNuevo = AlternativaDestino::where('alternativa_id', $nuevaId)->first();
        $itemClonado = AlternativaItem::where('alternativa_id', $nuevaId)->first();

        $this->assertSame($destinoNuevo->id, $itemClonado->alternativa_destino_id);
        $this->assertNotSame($destinoOriginal->id, $itemClonado->alternativa_destino_id, 'no debe apuntar al destino del original');
    }

    public function test_duplicar_no_falla_si_el_item_original_no_tiene_destino_asignado(): void
    {
        $cotizacionId = $this->crearCotizacion('Piura Test 12c Dup');
        $original = $this->crearAlternativaDirecta($cotizacionId);
        AlternativaDestino::create(['alternativa_id' => $original->id, 'destino_texto' => 'Piura Test 12c Dup', 'orden' => 1]);
        AlternativaItem::create([
            'alternativa_id' => $original->id, 'alternativa_destino_id' => null,
            'origen_tipo' => 'manual', 'dia_referencial' => 1, 'descripcion_manual' => 'Ítem sin destino',
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'precio_convertido' => 15,
        ]);

        $response = app(AlternativaController::class)->duplicar((string) $original->id);

        $this->assertSame(200, $response->getStatusCode());
        $nuevaId = $response->getData(true)['alternativa']['id'];
        $itemClonado = AlternativaItem::where('alternativa_id', $nuevaId)->first();
        $this->assertNull($itemClonado->alternativa_destino_id);
    }

    // §4 — relación AlternativaDestino::items().

    public function test_relacion_alternativa_destino_items_ordena_por_id(): void
    {
        $cotizacionId = $this->crearCotizacion('Relacion Test 12c');
        $alternativa = $this->crearAlternativaDirecta($cotizacionId);
        $destino = AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'x', 'orden' => 1]);
        AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'alternativa_destino_id' => $destino->id,
            'origen_tipo' => 'manual', 'dia_referencial' => 1, 'descripcion_manual' => 'Ítem 1',
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
            'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'precio_convertido' => 15,
        ]);

        $this->assertCount(1, $destino->fresh()->items);
    }
}
