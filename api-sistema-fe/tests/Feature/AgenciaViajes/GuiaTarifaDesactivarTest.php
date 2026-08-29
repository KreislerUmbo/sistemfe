<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\GuiaController;
use App\Http\Controllers\AgenciaViajes\GuiaTarifaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\GuiaTarifa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 29-ago-2026 — GuiaTarifaController no tenía update()/destroy() a
// propósito desde la Sesión 5 ("el plan solo pide GET/POST anidado bajo
// guía"), pero eso dejaba sin forma real de editar/eliminar una tarifa de
// guía en toda la vida del vertical. Se replica el mismo patrón ya
// probado con proveedor_tarifas (ver ProveedorTarifaDesactivarTest):
// activo (boolean) + desactivar()/activar() como retiro reversible,
// destroy() real bloqueado si está en uso, update() con versionado.
// Mismo patrón de infraestructura: Postgres real (sistemafe_test_migrations),
// transacción por test revertida.
class GuiaTarifaDesactivarTest extends TestCase
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

    private function crearGuiaTarifa(): GuiaTarifa
    {
        $destino = DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);
        $guia = Guia::create(['nombre' => 'Guía Test', 'documento' => '10000001', 'telefono' => '999000001']);

        return GuiaTarifa::create([
            'guia_id' => $guia->id,
            'destino_id' => $destino->id,
            'modalidad' => 'dia_local',
            'costo_diario' => 80,
            'tipo_margen' => 'porcentaje',
            'margen_valor' => 25,
            'moneda' => 'PEN',
            'vigente_desde' => now()->toDateString(),
        ]);
    }

    private function crearAlternativaConPasajeros(): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '12345678', 'full_name' => 'Cliente Test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0002', 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'created_at' => now(), 'updated_at' => now(),
        ]);

        CotizacionPasajero::create(['cotizacion_id' => $cotizacionId, 'tipo_pax' => 'adulto', 'edad' => 30]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId,
            'nombre' => 'Alternativa 1',
            'moneda_cotizacion' => 'PEN',
            'tipo_cambio_aplicado' => 1,
            'tipo_cambio_origen' => 'dia',
        ]);
    }

    public function test_tarifa_nace_activa_por_defecto(): void
    {
        $tarifa = $this->crearGuiaTarifa();

        $this->assertTrue($tarifa->fresh()->activo);
    }

    public function test_store_devuelve_activo_true_explicito_en_el_json(): void
    {
        $guia = Guia::create(['nombre' => 'Guía Test 2', 'documento' => '10000002', 'telefono' => '999000002']);
        $destino = DestinoAtractivo::create(['nombre' => 'Río Mayo', 'tipo' => 'zona']);

        $respuesta = app(GuiaTarifaController::class)->store(new Request([
            'destino_id' => $destino->id, 'modalidad' => 'dia_local',
            'costo_diario' => 60, 'tipo_margen' => 'porcentaje', 'margen_valor' => 20,
            'moneda' => 'PEN', 'vigente_desde' => now()->toDateString(),
        ]), (string) $guia->id);

        $this->assertSame(true, $respuesta->getData(true)['guia_tarifa']['activo']);
    }

    public function test_update_actualiza_tarifa_sin_uso_directo(): void
    {
        $tarifa = $this->crearGuiaTarifa();

        $respuesta = app(GuiaTarifaController::class)->update(new Request([
            'destino_id' => $tarifa->destino_id, 'modalidad' => 'grupo_multidia',
            'costo_diario' => 95, 'tipo_margen' => 'porcentaje', 'margen_valor' => 30,
            'moneda' => 'PEN', 'vigente_desde' => $tarifa->vigente_desde->toDateString(),
        ]), (string) $tarifa->id);

        $payload = $respuesta->getData(true);
        $this->assertArrayNotHasKey('version_anterior_id', $payload, 'Sin uso previo no debe versionar.');
        $this->assertSame('grupo_multidia', $payload['guia_tarifa']['modalidad']);
        $this->assertSame($tarifa->id, GuiaTarifa::where('modalidad', 'grupo_multidia')->first()->id);
    }

    // update() con versionado (tarifa ya en uso): la versión nueva hereda
    // el estado activo/inactivo de la que reemplaza.
    public function test_update_versionado_preserva_inactivo_de_la_tarifa_anterior(): void
    {
        $tarifa = $this->crearGuiaTarifa();
        $alternativa = $this->crearAlternativaConPasajeros();
        app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'guia',
            'guia_tarifa_id' => $tarifa->id,
        ]), (string) $alternativa->id);

        // Se desactiva DESPUÉS de haberse usado — mismo orden que el caso
        // real (una tarifa se usa, después se retira del catálogo).
        app(GuiaTarifaController::class)->desactivar((string) $tarifa->id);

        $respuesta = app(GuiaTarifaController::class)->update(new Request([
            'destino_id' => $tarifa->destino_id, 'modalidad' => 'dia_local',
            'costo_diario' => 100, 'tipo_margen' => 'porcentaje', 'margen_valor' => 25,
            'moneda' => 'PEN', 'vigente_desde' => now()->toDateString(),
        ]), (string) $tarifa->id);

        $payload = $respuesta->getData(true);
        $this->assertArrayHasKey('version_anterior_id', $payload, 'Debe haber versionado (la tarifa ya estaba en uso).');
        $this->assertSame(false, $payload['guia_tarifa']['activo']);
    }

    public function test_desactivar_no_borra_la_fila(): void
    {
        $tarifa = $this->crearGuiaTarifa();

        $respuesta = app(GuiaTarifaController::class)->desactivar((string) $tarifa->id);
        $payload = $respuesta->getData(true);

        $this->assertSame(200, $payload['code']);
        $this->assertFalse($tarifa->fresh()->activo);
        $this->assertNotNull(GuiaTarifa::find($tarifa->id), 'La fila no debe borrarse, solo marcarse inactiva.');
    }

    public function test_activar_revierte_la_desactivacion(): void
    {
        $tarifa = $this->crearGuiaTarifa();
        app(GuiaTarifaController::class)->desactivar((string) $tarifa->id);

        app(GuiaTarifaController::class)->activar((string) $tarifa->id);

        $this->assertTrue($tarifa->fresh()->activo);
    }

    public function test_picker_de_guia_excluye_tarifas_desactivadas(): void
    {
        $tarifa = $this->crearGuiaTarifa();
        app(GuiaTarifaController::class)->desactivar((string) $tarifa->id);

        $respuesta = app(GuiaController::class)->show((string) $tarifa->guia_id);
        $ids = collect($respuesta->getData(true)['guia']['guia_tarifas'])->pluck('id');

        $this->assertNotContains($tarifa->id, $ids);
    }

    public function test_picker_de_guia_incluye_tarifas_activas(): void
    {
        $tarifa = $this->crearGuiaTarifa();

        $respuesta = app(GuiaController::class)->show((string) $tarifa->guia_id);
        $ids = collect($respuesta->getData(true)['guia']['guia_tarifas'])->pluck('id');

        $this->assertContains($tarifa->id, $ids);
    }

    public function test_destroy_bloqueado_sugiere_desactivar_en_el_mensaje(): void
    {
        $tarifa = $this->crearGuiaTarifa();
        $alternativa = $this->crearAlternativaConPasajeros();

        app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'guia',
            'guia_tarifa_id' => $tarifa->id,
        ]), (string) $alternativa->id);

        $respuesta = app(GuiaTarifaController::class)->destroy((string) $tarifa->id);
        $payload = $respuesta->getData(true);

        $this->assertSame(422, $payload['code']);
        $this->assertStringContainsString('desactivala', $payload['message']);
        $this->assertNotNull(GuiaTarifa::find($tarifa->id), 'No debe borrarse cuando está en uso.');
    }

    public function test_destroy_sigue_borrando_de_verdad_cuando_no_esta_en_uso(): void
    {
        $tarifa = $this->crearGuiaTarifa();

        $respuesta = app(GuiaTarifaController::class)->destroy((string) $tarifa->id);

        $this->assertSame(200, $respuesta->getData(true)['code']);
        $this->assertNull(GuiaTarifa::find($tarifa->id));
    }

    public function test_item_nuevo_rechaza_tarifa_desactivada(): void
    {
        $tarifa = $this->crearGuiaTarifa();
        app(GuiaTarifaController::class)->desactivar((string) $tarifa->id);
        $alternativa = $this->crearAlternativaConPasajeros();

        $respuesta = app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'guia',
            'guia_tarifa_id' => $tarifa->id,
        ]), (string) $alternativa->id);

        $this->assertSame(422, $respuesta->getData(true)['code']);
    }
}
