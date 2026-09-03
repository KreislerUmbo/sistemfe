<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaDestino;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\TourItinerarioItem;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Sesión 12f-3 — brief PEGAR-EN-CLAUDE-CODE-12f3-pdf-por-destino.md.
// itinerarioAlternativa()/incluyePorDestino()/itemsPorDestino() son
// métodos privados de AlternativaController — se invocan vía reflexión,
// mismo patrón que otros tests de lógica extraída de controllers en esta
// suite (ValidarRegimenEspecialTest). No se ejercita pdf() completo (DomPDF)
// porque ningún otro test de la suite lo hace — la lógica de agrupación es
// lo que importa probar, el render de blade se verifica a mano contra
// agencia-demo.
class Sesion12f3PdfPorDestinoTest extends TestCase
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

    private function invocar(string $metodo, Alternativa $alternativa): array
    {
        $controller = app(AlternativaController::class);
        $method = new \ReflectionMethod(AlternativaController::class, $metodo);
        $method->setAccessible(true);

        return $method->invoke($controller, $alternativa->fresh(['destinos.destinoAtractivo', 'items']));
    }

    private function crearAlternativa(): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '11224499', 'full_name' => 'Cliente Test 12f3',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-09' . random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
    }

    private function crearTour(string $nombre, array $diasRelativos): PaquetePlantilla
    {
        $destinoAtractivo = DestinoAtractivo::first() ?? DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);

        $tour = PaquetePlantilla::create([
            'codigo' => 'TOUR-' . random_int(10000, 99999), 'categoria' => 'aventura', 'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE,
            'nombre' => $nombre, 'destino_atractivo_id' => $destinoAtractivo->id, 'duracion_horas' => 4, 'activo' => true,
        ]);

        foreach ($diasRelativos as $orden => $dia) {
            TourItinerarioItem::create([
                'tour_id' => $tour->id, 'dia_relativo' => $dia, 'orden' => $orden, 'descripcion' => "Paso día {$dia} de {$nombre}",
            ]);
        }

        return $tour;
    }

    private function crearItem(Alternativa $alternativa, ?int $alternativaDestinoId, ?int $tourOrigenId, string $descripcion): AlternativaItem
    {
        return AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'alternativa_destino_id' => $alternativaDestinoId, 'tour_origen_id' => $tourOrigenId,
            'origen_tipo' => AlternativaItem::ORIGEN_MANUAL, 'descripcion_manual' => $descripcion, 'modo_precio' => 'tarifa_fija',
            'cantidad' => 1, 'moneda_costo' => 'PEN', 'costo_snapshot' => 10, 'precio_venta_snapshot' => 15, 'precio_convertido' => 15,
        ]);
    }

    public function test_itinerario_con_un_solo_destino_devuelve_un_bloque_sin_encabezado_duplicado(): void
    {
        $alternativa = $this->crearAlternativa();
        $destino = AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Tarapoto', 'orden' => 1]);
        $tour = $this->crearTour('City tour Tarapoto', [1, 2]);
        $this->crearItem($alternativa, $destino->id, $tour->id, 'City tour');

        $bloques = $this->invocar('itinerarioAlternativa', $alternativa);

        $this->assertCount(1, $bloques);
        $this->assertSame($destino->id, $bloques[0]['destino_id']);
        $this->assertCount(2, $bloques[0]['pasos']);
        $this->assertSame(1, $bloques[0]['pasos'][0]['dia']);
        $this->assertSame(2, $bloques[0]['pasos'][1]['dia']);
    }

    public function test_itinerario_agrupa_por_destino_con_offset_de_dia_reiniciado(): void
    {
        $alternativa = $this->crearAlternativa();
        $destino1 = AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Tarapoto', 'orden' => 1]);
        $destino2 = AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'México', 'orden' => 2]);

        $tourTarapoto = $this->crearTour('Tour Tarapoto', [1, 2]);
        $tourMexico = $this->crearTour('Tour México', [1, 2, 3]);

        $this->crearItem($alternativa, $destino1->id, $tourTarapoto->id, 'Tour Tarapoto');
        $this->crearItem($alternativa, $destino2->id, $tourMexico->id, 'Tour México');

        $bloques = $this->invocar('itinerarioAlternativa', $alternativa);

        $this->assertCount(2, $bloques);

        $this->assertSame($destino1->id, $bloques[0]['destino_id']);
        $this->assertSame('Tarapoto', $bloques[0]['destino_nombre']);
        $this->assertSame([1, 2], array_column($bloques[0]['pasos'], 'dia'));

        // El offset del segundo destino arranca en 0, no continúa desde el
        // día 2 del primero — si arrastrara el offset, el primer día de
        // México sería 3, no 1.
        $this->assertSame($destino2->id, $bloques[1]['destino_id']);
        $this->assertSame('México', $bloques[1]['destino_nombre']);
        $this->assertSame([1, 2, 3], array_column($bloques[1]['pasos'], 'dia'));
    }

    public function test_itinerario_trata_alternativa_destino_id_null_como_primer_destino(): void
    {
        $alternativa = $this->crearAlternativa();
        $destino1 = AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Destino 1', 'orden' => 1]);
        AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Destino 2', 'orden' => 2]);

        $tour = $this->crearTour('Tour legacy', [1]);
        // Ítem legacy sin alternativa_destino_id resuelto (caso real de
        // datos creados antes de 12c) — debe caer en el primer destino.
        $this->crearItem($alternativa, null, $tour->id, 'Tour legacy');

        $bloques = $this->invocar('itinerarioAlternativa', $alternativa);

        $this->assertCount(1, $bloques);
        $this->assertSame($destino1->id, $bloques[0]['destino_id']);
    }

    public function test_itinerario_omite_destinos_sin_tour_origen_id(): void
    {
        $alternativa = $this->crearAlternativa();
        $destino1 = AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Destino 1', 'orden' => 1]);
        $destino2 = AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Destino 2', 'orden' => 2]);
        $tour = $this->crearTour('Tour con itinerario', [1]);

        $this->crearItem($alternativa, $destino1->id, $tour->id, 'Con itinerario');
        // Ítem manual suelto, sin tour_origen_id — no aporta pasos de itinerario.
        $this->crearItem($alternativa, $destino2->id, null, 'Traslado suelto');

        $bloques = $this->invocar('itinerarioAlternativa', $alternativa);

        $this->assertCount(1, $bloques);
        $this->assertSame($destino1->id, $bloques[0]['destino_id']);
    }

    public function test_incluye_por_destino_agrupa_nombres_sin_precio(): void
    {
        $alternativa = $this->crearAlternativa();
        $destino1 = AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Tarapoto', 'orden' => 1]);
        $destino2 = AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'México', 'orden' => 2]);

        $this->crearItem($alternativa, $destino1->id, null, 'City tour Tarapoto');
        $this->crearItem($alternativa, $destino2->id, null, 'Excursión Teotihuacán');
        $this->crearItem($alternativa, $destino2->id, null, 'Traslado aeropuerto CDMX');

        $bloques = $this->invocar('incluyePorDestino', $alternativa);

        $this->assertCount(2, $bloques);
        $this->assertSame(['City tour Tarapoto'], $bloques[0]['nombres']->all());
        $this->assertSame(['Excursión Teotihuacán', 'Traslado aeropuerto CDMX'], $bloques[1]['nombres']->all());
        $this->assertArrayNotHasKey('precio', $bloques[0]);
    }

    // Hallazgo real 01-sep-2026 (revisión posterior contra agencia-demo):
    // un combo multi-día genera un ítem por tour del día — si 2+ tours
    // incluyen el mismo servicio genérico ("Transporte / Traslado Ida y
    // Vuelta"), salía repetido varias veces seguidas en "Incluye". Decisión
    // confirmada con el usuario: una sola línea, sin contador.
    public function test_incluye_por_destino_deduplica_nombres_repetidos(): void
    {
        $alternativa = $this->crearAlternativa();
        $destino = AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Tarapoto', 'orden' => 1]);

        $this->crearItem($alternativa, $destino->id, null, 'Transporte / Traslado Ida y Vuelta');
        $this->crearItem($alternativa, $destino->id, null, 'Desayuno');
        $this->crearItem($alternativa, $destino->id, null, 'Transporte / Traslado Ida y Vuelta');
        $this->crearItem($alternativa, $destino->id, null, 'Desayuno');
        $this->crearItem($alternativa, $destino->id, null, 'Transporte / Traslado Ida y Vuelta');

        $bloques = $this->invocar('incluyePorDestino', $alternativa);

        $this->assertCount(1, $bloques);
        $this->assertSame(['Transporte / Traslado Ida y Vuelta', 'Desayuno'], $bloques[0]['nombres']->all());
    }

    public function test_incluye_por_destino_con_un_solo_destino_devuelve_un_bloque(): void
    {
        $alternativa = $this->crearAlternativa();
        AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Tarapoto', 'orden' => 1]);
        $this->crearItem($alternativa, null, null, 'Ítem único');

        $bloques = $this->invocar('incluyePorDestino', $alternativa);

        $this->assertCount(1, $bloques);
        $this->assertSame(['Ítem único'], $bloques[0]['nombres']->all());
    }

    // Feedback del usuario sobre el PDF real ya en producción: faltaba el
    // nombre del tour (título del día) y el nombre del atractivo de cada
    // paso — ambos datos ya existen en tour_itinerario_items
    // (PaquetePlantilla.nombre / TourItinerarioItem.destino_atractivo_id),
    // pero itinerarioAlternativa() nunca los pasaba a la vista. Confirmado
    // contra datos reales de agencia-demo (php artisan tinker) que
    // destino_atractivo_id es un campo aparte de la descripción en texto
    // libre, no redundante con ella.
    public function test_itinerario_incluye_nombre_de_tour_y_de_atractivo_por_paso(): void
    {
        $alternativa = $this->crearAlternativa();
        $destino = AlternativaDestino::create(['alternativa_id' => $alternativa->id, 'destino_texto' => 'Tarapoto', 'orden' => 1]);
        $atractivo = DestinoAtractivo::create(['nombre' => 'Tio Yacu', 'tipo' => 'lugar']);

        $tour = PaquetePlantilla::create([
            'codigo' => 'TOUR-' . random_int(10000, 99999), 'categoria' => 'aventura', 'tipo' => PaquetePlantilla::TIPO_TOUR_SIMPLE,
            'nombre' => 'Full Day Alto Mayo', 'destino_atractivo_id' => $atractivo->id, 'duracion_horas' => 8, 'activo' => true,
        ]);
        TourItinerarioItem::create([
            'tour_id' => $tour->id, 'dia_relativo' => 1, 'orden' => 1,
            'destino_atractivo_id' => $atractivo->id, 'descripcion' => 'La naciente se nutre de corrientes subterráneas',
        ]);
        // Paso sin atractivo asociado (ej. recojo del hotel) — no debe romper.
        TourItinerarioItem::create([
            'tour_id' => $tour->id, 'dia_relativo' => 1, 'orden' => 0, 'descripcion' => 'Recojo del hotel',
        ]);

        $this->crearItem($alternativa, $destino->id, $tour->id, 'Full Day Alto Mayo');

        $bloques = $this->invocar('itinerarioAlternativa', $alternativa);

        $pasos = collect($bloques[0]['pasos']);
        $this->assertTrue($pasos->every(fn (array $p) => $p['tour_nombre'] === 'Full Day Alto Mayo'));
        $this->assertSame('Tio Yacu', $pasos->firstWhere('descripcion', 'La naciente se nutre de corrientes subterráneas')['atractivo_nombre']);
        $this->assertNull($pasos->firstWhere('descripcion', 'Recojo del hotel')['atractivo_nombre']);
    }
}
