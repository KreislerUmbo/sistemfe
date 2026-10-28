<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaController;
use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Services\AgenciaViajes\SepararGruposDuplicadosService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\AgenciaViajes\Concerns\ConAccesoATodasLasReservas;
use Tests\TestCase;

// T-024 (H1): duplicar una alternativa copiaba grupo_opcion_id tal cual, y
// eliminarGrupo() buscaba solo por ese id. "Eliminar todo este bloque" en la
// copia borraba también la comparación de hoteles de la original.
class T024GrupoHotelesIndependienteTest extends TestCase
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

    private function alternativaConGrupo(): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10_000_000, 99_999_999), 'full_name' => 'Cliente T024',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-T024-'.uniqid(), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa T024', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);

        $grupo = (string) Str::uuid();
        foreach ([['Hotel A', 100, true], ['Hotel B', 150, false]] as [$nombre, $venta, $elegida]) {
            AlternativaItem::create([
                'alternativa_id' => $alternativa->id, 'origen_tipo' => 'manual', 'descripcion_manual' => $nombre,
                'grupo_opcion_id' => $grupo, 'opcion_elegida' => $elegida,
                'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => 'PEN',
                'costo_snapshot' => $venta - 20, 'precio_venta_snapshot' => $venta, 'precio_convertido' => $venta,
            ]);
        }

        return [$alternativa, $grupo];
    }

    private function duplicar(Alternativa $alternativa): Alternativa
    {
        $respuesta = app(AlternativaController::class)->duplicar((string) $alternativa->id);
        $this->assertSame(200, $respuesta->getStatusCode());

        return Alternativa::findOrFail($respuesta->getData(true)['alternativa']['id']);
    }

    public function test_ca1_la_copia_tiene_su_propio_grupo_con_la_misma_elegida(): void
    {
        [$original, $grupo] = $this->alternativaConGrupo();
        $copia = $this->duplicar($original);

        $itemsCopia = AlternativaItem::where('alternativa_id', $copia->id)->orderBy('id')->get();
        $grupoCopia = $itemsCopia->first()->grupo_opcion_id;

        $this->assertNotNull($grupoCopia);
        $this->assertNotSame($grupo, $grupoCopia, 'la copia no comparte el grupo con la original');
        $this->assertCount(2, $itemsCopia->where('grupo_opcion_id', $grupoCopia), 'el agrupamiento sigue intacto');
        $this->assertSame('Hotel A', $itemsCopia->firstWhere('opcion_elegida', true)->descripcion_manual);
    }

    public function test_ca2_eliminar_el_grupo_en_la_copia_no_toca_la_original(): void
    {
        [$original, $grupo] = $this->alternativaConGrupo();
        $original->update(['total' => AlternativaItem::calcularTotalEfectivo($original->items()->get())['total']]);
        $totalOriginal = (float) $original->fresh()->total;
        $copia = $this->duplicar($original);
        $grupoCopia = AlternativaItem::where('alternativa_id', $copia->id)->value('grupo_opcion_id');

        $respuesta = app(AlternativaItemController::class)->eliminarGrupo((string) $copia->id, $grupoCopia);

        $this->assertSame(200, $respuesta->getStatusCode(), json_encode($respuesta->getData(true)));
        $this->assertSame(0, AlternativaItem::where('alternativa_id', $copia->id)->count());
        $this->assertSame(2, AlternativaItem::where('alternativa_id', $original->id)->where('grupo_opcion_id', $grupo)->count());
        $this->assertSame($totalOriginal, (float) $original->fresh()->total);
    }

    public function test_ca3_original_aceptada_no_impide_eliminar_el_grupo_de_la_copia(): void
    {
        [$original, $grupo] = $this->alternativaConGrupo();
        // Dato viejo: la copia comparte el grupo con la original (antes del fix).
        $copia = $this->duplicar($original);
        AlternativaItem::where('alternativa_id', $copia->id)->update(['grupo_opcion_id' => $grupo]);
        $original->update(['estado' => 'aceptada']);

        $respuesta = app(AlternativaItemController::class)->eliminarGrupo((string) $copia->id, $grupo);

        $this->assertSame(200, $respuesta->getStatusCode(), json_encode($respuesta->getData(true)));
        $this->assertSame(0, AlternativaItem::where('alternativa_id', $copia->id)->count());
        $this->assertSame(2, AlternativaItem::where('alternativa_id', $original->id)->count());
    }

    public function test_ca4_el_comando_separa_grupos_compartidos_y_es_idempotente(): void
    {
        [$original, $grupo] = $this->alternativaConGrupo();
        $copia = $this->duplicar($original);
        AlternativaItem::where('alternativa_id', $copia->id)->update(['grupo_opcion_id' => $grupo]);
        $servicio = app(SepararGruposDuplicadosService::class);
        $deEsteGrupo = fn (array $cambios) => array_values(array_filter($cambios, fn ($c) => $c['grupo'] === $grupo));

        $simulado = $deEsteGrupo($servicio->separar(simular: true));
        $this->assertCount(1, $simulado);
        $this->assertSame($copia->id, $simulado[0]['alternativa_id']);
        $this->assertSame(2, AlternativaItem::where('alternativa_id', $copia->id)->where('grupo_opcion_id', $grupo)->count(), '--simular no escribe');

        $real = $deEsteGrupo($servicio->separar(simular: false));
        $this->assertCount(1, $real);
        $grupoCopia = AlternativaItem::where('alternativa_id', $copia->id)->distinct()->pluck('grupo_opcion_id');
        $this->assertCount(1, $grupoCopia, 'la copia conserva el agrupamiento');
        $this->assertSame($real[0]['grupo_nuevo'], $grupoCopia->first());
        $this->assertSame(2, AlternativaItem::where('alternativa_id', $original->id)->where('grupo_opcion_id', $grupo)->count(), 'la original (primera por id) conserva su grupo');
        $this->assertSame(2, DB::table('ajustes_grupo_opcion')->where('grupo_anterior', $grupo)->where('grupo_nuevo', $grupoCopia->first())->count());

        $this->assertSame([], $deEsteGrupo($servicio->separar(simular: false)), 'correrlo de nuevo no cambia nada');
    }

    public function test_eliminar_grupo_404_si_el_grupo_no_es_de_esa_alternativa(): void
    {
        [$original, $grupo] = $this->alternativaConGrupo();
        [$otra] = $this->alternativaConGrupo();

        $respuesta = app(AlternativaItemController::class)->eliminarGrupo((string) $otra->id, $grupo);

        $this->assertSame(404, $respuesta->getStatusCode());
        $this->assertSame(2, AlternativaItem::where('alternativa_id', $original->id)->count());
    }
}
