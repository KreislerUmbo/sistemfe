<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Sesión UX2 (04-sep-2026) — hallazgo del usuario viendo el lienzo real:
// nada impedía repetir "Comparar varias opciones" con el mismo set de
// hoteles, dejando varios bloques "N opciones de hotel" idénticos, y
// borrarlos fila por fila (con AlternativaItemController::destroy(), uno
// por uno) no era intuitivo. eliminarGrupo() borra TODAS las filas de un
// mismo grupo_opcion_id de una sola vez. Mismo patrón de infraestructura
// que el resto de la suite: Postgres real (sistemafe_test_migrations),
// transacción por test revertida.
class SesionUX2EliminarGrupoTest extends TestCase
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

    private function crearAlternativa(string $estado = 'borrador'): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '55667788', 'full_name' => 'Cliente Test UX2',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-UX2-' . random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Panamá', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa UX2', 'estado' => $estado,
            'moneda_cotizacion' => 'USD', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
    }

    private function crearGrupoDeTresHoteles(Alternativa $alternativa): array
    {
        $grupo = (string) Str::uuid();
        $items = [];
        foreach ([['Hotel A', 880], ['Hotel B', 850], ['Hotel C', 890]] as [$nombre, $venta]) {
            $items[] = AlternativaItem::create([
                'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'descripcion_manual' => $nombre,
                'grupo_opcion_id' => $grupo, 'opcion_elegida' => false,
                'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'USD',
                'costo_snapshot' => $venta - 20, 'precio_venta_snapshot' => $venta, 'precio_convertido' => $venta,
            ]);
        }

        return [$grupo, $items];
    }

    public function test_eliminar_grupo_borra_todas_las_filas_y_recalcula_total(): void
    {
        $alternativa = $this->crearAlternativa();
        [$grupo, $items] = $this->crearGrupoDeTresHoteles($alternativa);
        // Ítem suelto ajeno al grupo — no debe verse afectado.
        $itemSuelto = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'descripcion_manual' => 'Traslado',
            'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'USD',
            'costo_snapshot' => 30, 'precio_venta_snapshot' => 50, 'precio_convertido' => 50,
        ]);

        $response = app(AlternativaItemController::class)->eliminarGrupo($grupo);

        $this->assertSame(200, $response->getStatusCode());
        foreach ($items as $item) {
            $this->assertNull(AlternativaItem::find($item->id), 'cada fila del grupo debe quedar borrada');
        }
        $this->assertNotNull(AlternativaItem::find($itemSuelto->id), 'el ítem suelto ajeno al grupo no debe tocarse');
        $this->assertEquals(50.0, (float) $alternativa->fresh()->total, 'el total recalculado debe reflejar solo lo que queda');
    }

    public function test_eliminar_grupo_rechaza_si_alternativa_ya_aceptada(): void
    {
        $alternativa = $this->crearAlternativa();
        [$grupo, $items] = $this->crearGrupoDeTresHoteles($alternativa);
        $alternativa->update(['estado' => 'aceptada']);

        $response = app(AlternativaItemController::class)->eliminarGrupo($grupo);

        $this->assertSame(422, $response->getStatusCode());
        foreach ($items as $item) {
            $this->assertNotNull(AlternativaItem::find($item->id));
        }
    }

    public function test_eliminar_grupo_rechaza_si_alguna_fila_ya_tiene_reserva(): void
    {
        $alternativa = $this->crearAlternativa();
        [$grupo, $items] = $this->crearGrupoDeTresHoteles($alternativa);
        $reserva = Reserva::create(['alternativa_id' => $alternativa->id, 'estado' => 'activa']);
        ReservaItem::create(['reserva_id' => $reserva->id, 'alternativa_item_id' => $items[1]->id]);

        $response = app(AlternativaItemController::class)->eliminarGrupo($grupo);

        $this->assertSame(422, $response->getStatusCode());
        foreach ($items as $item) {
            $this->assertNotNull(AlternativaItem::find($item->id), 'ninguna fila se borra si cualquiera del grupo ya tiene reserva');
        }
    }

    public function test_eliminar_grupo_404_si_no_existe(): void
    {
        $response = app(AlternativaItemController::class)->eliminarGrupo((string) Str::uuid());

        $this->assertSame(404, $response->getStatusCode());
    }
}
