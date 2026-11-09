<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaDestinoController;
use App\Http\Controllers\AgenciaViajes\CrmMotivoResultadoController;
use App\Http\Controllers\AgenciaViajes\ReservaPasajeroController;
use App\Http\Controllers\AgenciaViajes\SalidaServicioController;
use App\Http\Controllers\AgenciaViajes\SalidaVehiculoController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\CrmMotivoResultado;
use App\Models\AgenciaViajes\PasajeroCatalogo;
use App\Services\BusquedaLike;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\AgenciaViajes\Concerns\SalidasFixtures;
use Tests\TestCase;

// T-092 — depuración de seguridad de Agencia: endpoint huérfano retirado,
// rutas create/edit fuera de los Route::resource, orden de filas hijas con
// lock y comodines de ilike escapados. Postgres real, transacción revertida
// (SalidasFixtures). CA6 de `coincidencias` está en ConsultaOrigenContactoTest.
class T092DepuracionSeguridadAgenciaTest extends TestCase
{
    use SalidasFixtures;

    // ── CA3: POST reservas/{id}/pasajeros retirado ─────────────────────

    public function test_ya_no_existe_el_alta_directa_de_pasajero_en_una_reserva(): void
    {
        $this->assertFalse($this->rutaRegistrada('POST', 'api/reservas/{id}/pasajeros'));
        $this->assertFalse(method_exists(ReservaPasajeroController::class, 'store'));
        // El resto del controller sigue registrado.
        $this->assertTrue($this->rutaRegistrada('PUT', 'api/reserva-pasajeros/{id}'));
        $this->assertTrue($this->rutaRegistrada('GET', 'api/pasajeros-catalogo'));
    }

    // ── CA4: sin create/edit en los cuatro Route::resource ────────────

    public function test_los_resource_de_agencia_no_registran_create_ni_edit(): void
    {
        foreach (['proveedores' => 'proveedore', 'servicios' => 'servicio', 'temporadas' => 'temporada', 'guias' => 'guia'] as $recurso => $parametro) {
            $this->assertFalse($this->rutaRegistrada('GET', "api/{$recurso}/create"), "{$recurso}/create sigue registrada");
            $this->assertFalse($this->rutaRegistrada('GET', "api/{$recurso}/{{$parametro}}/edit"), "{$recurso}/edit sigue registrada");
            // Las cinco de API siguen.
            $this->assertTrue($this->rutaRegistrada('GET', "api/{$recurso}"));
            $this->assertTrue($this->rutaRegistrada('POST', "api/{$recurso}"));
            $this->assertTrue($this->rutaRegistrada('GET', "api/{$recurso}/{{$parametro}}"));
            $this->assertTrue($this->rutaRegistrada('PUT', "api/{$recurso}/{{$parametro}}"));
            $this->assertTrue($this->rutaRegistrada('DELETE', "api/{$recurso}/{{$parametro}}"));
        }
    }

    // ── CA5: orden con lock sobre el padre ────────────────────────────

    public function test_alta_de_destino_bloquea_la_alternativa_antes_de_calcular_el_orden(): void
    {
        $alternativa = $this->crearAlternativa();
        $controller = app(AlternativaDestinoController::class);

        $consultas = $this->consultasDe(fn () => $this->assertSame(1, $controller->store(new Request(['destino_texto' => 'Tarapoto']), (string) $alternativa->id)->getData(true)['alternativa_destino']['orden']));
        $this->assertLockAntesDeInsertar($consultas, 'alternativas', 'alternativa_destinos');

        $this->assertSame(2, $controller->store(new Request(['destino_texto' => 'Lamas']), (string) $alternativa->id)->getData(true)['alternativa_destino']['orden']);
    }

    public function test_alta_de_servicio_y_vehiculo_bloquea_la_salida(): void
    {
        $salida = $this->salidaDe($this->reservaConTour($this->crearTour(), [['adulto', 40]]));

        $consultas = $this->consultasDe(fn () => app(SalidaServicioController::class)->store(new Request(['nombre' => 'Botiquín']), (string) $salida->id));
        $this->assertLockAntesDeInsertar($consultas, 'salidas_operativas', 'salida_servicios');

        $consultas = $this->consultasDe(fn () => app(SalidaVehiculoController::class)->store(new Request(['chofer_nombre' => 'Pedro']), (string) $salida->id));
        $this->assertLockAntesDeInsertar($consultas, 'salidas_operativas', 'salida_vehiculos');
    }

    public function test_alta_de_motivo_crm_bloquea_los_motivos_del_tipo(): void
    {
        $consultas = $this->consultasDe(fn () => $this->assertSame(200, app(CrmMotivoResultadoController::class)
            ->store(new Request(['tipo' => CrmMotivoResultado::TIPO_PERDIDA, 'nombre' => 'Motivo T092 '.uniqid()]))->getStatusCode()));
        // Con «Otro» presente el orden sale de su fila (sin max()): basta el lock antes del INSERT.
        $this->assertLockAntesDeInsertar($consultas, 'crm_motivos_resultado', 'crm_motivos_resultado', conMax: false);
    }

    // ── CA6: comodines de ilike ───────────────────────────────────────

    public function test_busqueda_like_escapa_los_comodines(): void
    {
        $this->assertSame('%50\\%%', BusquedaLike::contiene('50%'));
        $this->assertSame('a\\_b', BusquedaLike::escapar('a_b'));
        $this->assertSame('c\\\\d', BusquedaLike::escapar('c\\d'));
        $this->assertSame('%Pérez%', BusquedaLike::contiene('Pérez'));
    }

    public function test_buscar_catalogo_de_pasajeros_toma_el_porcentaje_literal(): void
    {
        $marca = 'T092'.random_int(1000, 9999);
        PasajeroCatalogo::create(['nombre' => "{$marca} Grupo 50% dscto"]);
        PasajeroCatalogo::create(['nombre' => "{$marca} Grupo 500"]);
        PasajeroCatalogo::create(['nombre' => "{$marca} Ana_Luz"]);
        PasajeroCatalogo::create(['nombre' => "{$marca} AnaXLuz"]);

        $this->assertSame(["{$marca} Grupo 50% dscto"], $this->nombresCatalogo("{$marca} Grupo 50%"));
        $this->assertSame(["{$marca} Ana_Luz"], $this->nombresCatalogo("{$marca} Ana_"));
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function rutaRegistrada(string $metodo, string $uri): bool
    {
        foreach (Route::getRoutes() as $ruta) {
            if ($ruta->uri() === $uri && in_array($metodo, $ruta->methods(), true)) {
                return true;
            }
        }

        return false;
    }

    private function crearAlternativa(): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10_000_000, 99_999_999), 'full_name' => 'Cliente T092',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-T092-'.random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa 1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'PEN', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);
    }

    /** @return array<int, string> SQL ejecutado por $accion, en orden. */
    private function consultasDe(callable $accion): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $accion();
        DB::disableQueryLog();

        return array_map(fn ($q) => strtolower($q['query']), DB::getQueryLog());
    }

    // El SELECT … FOR UPDATE sobre el padre va antes del max(orden) y del INSERT:
    // una segunda alta simultánea espera ahí y luego ve el orden ya tomado.
    private function assertLockAntesDeInsertar(array $consultas, string $padre, string $hija, bool $conMax = true): void
    {
        $lock = $this->indice($consultas, fn ($sql) => str_contains($sql, "from \"{$padre}\"") && str_contains($sql, 'for update'));
        $insert = $this->indice($consultas, fn ($sql) => str_starts_with($sql, "insert into \"{$hija}\""));

        $this->assertNotNull($lock, "sin FOR UPDATE sobre {$padre}:\n".implode("\n", $consultas));
        $this->assertNotNull($insert);
        $this->assertLessThan($insert, $lock);

        if ($conMax) {
            $max = $this->indice($consultas, fn ($sql) => str_contains($sql, 'max("orden")') && str_contains($sql, "from \"{$hija}\""));
            $this->assertNotNull($max);
            $this->assertLessThan($max, $lock);
            $this->assertLessThan($insert, $max);
        }
    }

    private function indice(array $consultas, callable $cumple): ?int
    {
        foreach ($consultas as $i => $sql) {
            if ($cumple($sql)) {
                return $i;
            }
        }

        return null;
    }

    private function nombresCatalogo(string $buscar): array
    {
        $respuesta = app(ReservaPasajeroController::class)->buscarCatalogo(new Request(['search' => $buscar]));

        return collect($respuesta->getData(true)['pasajeros_catalogo'])
            ->where('origen', 'pasajero')->pluck('nombre')->values()->all();
    }
}
