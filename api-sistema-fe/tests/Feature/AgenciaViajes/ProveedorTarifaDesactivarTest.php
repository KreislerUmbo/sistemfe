<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaItemController;
use App\Http\Controllers\AgenciaViajes\ProveedorTarifaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\DestinoAtractivo;
use App\Models\AgenciaViajes\DestinoServicio;
use App\Models\AgenciaViajes\Proveedor;
use App\Models\AgenciaViajes\ProveedorServicio;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Models\AgenciaViajes\Servicio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 26-ago-2026 — hasta esta sesión, ProveedorTarifaController::destroy()
// bloqueaba borrar una tarifa en uso SIN ofrecer ninguna alternativa real
// (mensaje literal: "hablalo con Umbo — por ahora no hay forma de
// desactivarla"). `activo` (boolean, separado a propósito de
// vigente_desde/vigente_hasta — ver comentario de la migración) + los
// endpoints desactivar()/activar() cierran ese gap. Mismo patrón de
// infraestructura que PrecioPorPasajeroTest: Postgres real
// (sistemafe_test_migrations), transacción por test revertida.
class ProveedorTarifaDesactivarTest extends TestCase
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

    private function crearProveedorTarifa(): ProveedorTarifa
    {
        $destino = DestinoAtractivo::create(['nombre' => 'Alto Mayo', 'tipo' => 'zona']);
        $servicio = Servicio::create(['nombre' => 'Traslado ida y vuelta']);
        $destinoServicio = DestinoServicio::create([
            'destino_atractivo_id' => $destino->id,
            'servicio_id' => $servicio->id,
        ]);
        $proveedor = Proveedor::create(['razon_social' => 'Transportes Test SAC', 'estado' => true]);
        $proveedorServicio = ProveedorServicio::create([
            'proveedor_id' => $proveedor->id,
            'destino_servicio_id' => $destinoServicio->id,
        ]);

        return ProveedorTarifa::create([
            'proveedor_servicio_id' => $proveedorServicio->id,
            'tipo_tarifa' => 'publica',
            'modalidad' => 'privado',
            'moneda' => 'PEN',
            'precio_costo' => 80,
            'margen_tipo' => 'porcentaje',
            'margen_valor' => 25,
            'precio_venta_adulto' => 100,
            'vigente_desde' => now()->toDateString(),
            'tip_afe_igv' => '10',
            'destino_tributario' => 'nacional',
        ]);
    }

    private function crearAlternativaConPasajeros(): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '12345678', 'full_name' => 'Cliente Test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-2026-0001', 'cliente_id' => $clienteId,
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
        $tarifa = $this->crearProveedorTarifa();

        $this->assertTrue($tarifa->fresh()->activo);
    }

    // store() debe devolver activo=true explícito en el JSON, no null —
    // Model::create() no refresca el modelo en memoria después del insert,
    // así que sin setearlo a mano el default de BD no se reflejaba en la
    // respuesta (aunque la fila en BD sí quedaba correcta).
    public function test_store_devuelve_activo_true_explicito_en_el_json(): void
    {
        $tarifa = $this->crearProveedorTarifa();
        $proveedorServicio = $tarifa->proveedorServicio;

        $respuesta = app(ProveedorTarifaController::class)->store(new Request([
            'tipo_tarifa' => 'publica', 'modalidad' => 'privado', 'moneda' => 'PEN',
            'precio_costo' => 50, 'margen_tipo' => 'porcentaje', 'margen_valor' => 20,
            'precio_venta_adulto' => 60, 'vigente_desde' => now()->toDateString(),
            'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ]), (string) $proveedorServicio->id);

        $this->assertSame(true, $respuesta->getData(true)['proveedor_tarifa']['activo']);
    }

    // update() con versionado (tarifa ya en uso): la versión nueva hereda
    // el estado activo/inactivo de la que reemplaza — editar el precio de
    // una tarifa desactivada no debe reactivarla en silencio.
    public function test_update_versionado_preserva_inactivo_de_la_tarifa_anterior(): void
    {
        $tarifa = $this->crearProveedorTarifa();
        $alternativa = $this->crearAlternativaConPasajeros();
        app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'proveedor',
            'proveedor_tarifa_id' => $tarifa->id,
            'modo_precio' => 'tarifa_fija',
        ]), (string) $alternativa->id);

        // Se desactiva DESPUÉS de haberse usado — mismo orden que el caso
        // real (una tarifa se usa, después se retira del catálogo).
        app(ProveedorTarifaController::class)->desactivar((string) $tarifa->id);

        $respuesta = app(ProveedorTarifaController::class)->update(new Request([
            'tipo_tarifa' => 'publica', 'modalidad' => 'privado', 'moneda' => 'PEN',
            'precio_costo' => 90, 'margen_tipo' => 'porcentaje', 'margen_valor' => 25,
            'precio_venta_adulto' => 112.5, 'vigente_desde' => now()->toDateString(),
            'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
        ]), (string) $tarifa->id);

        $payload = $respuesta->getData(true);
        $this->assertArrayHasKey('version_anterior_id', $payload, 'Debe haber versionado (la tarifa ya estaba en uso).');
        $this->assertSame(false, $payload['proveedor_tarifa']['activo']);
    }

    public function test_desactivar_no_borra_la_fila(): void
    {
        $tarifa = $this->crearProveedorTarifa();

        $respuesta = app(ProveedorTarifaController::class)->desactivar((string) $tarifa->id);
        $payload = $respuesta->getData(true);

        $this->assertSame(200, $payload['code']);
        $this->assertFalse($tarifa->fresh()->activo);
        $this->assertNotNull(ProveedorTarifa::find($tarifa->id), 'La fila no debe borrarse, solo marcarse inactiva.');
    }

    public function test_activar_revierte_la_desactivacion(): void
    {
        $tarifa = $this->crearProveedorTarifa();
        app(ProveedorTarifaController::class)->desactivar((string) $tarifa->id);

        app(ProveedorTarifaController::class)->activar((string) $tarifa->id);

        $this->assertTrue($tarifa->fresh()->activo);
    }

    public function test_biblioteca_excluye_tarifas_desactivadas(): void
    {
        $tarifa = $this->crearProveedorTarifa();
        app(ProveedorTarifaController::class)->desactivar((string) $tarifa->id);

        $respuesta = app(ProveedorTarifaController::class)->biblioteca(new Request());
        $ids = collect($respuesta->getData(true)['proveedor_tarifas'])->pluck('id');

        $this->assertNotContains($tarifa->id, $ids);
    }

    public function test_biblioteca_incluye_tarifas_activas(): void
    {
        $tarifa = $this->crearProveedorTarifa();

        $respuesta = app(ProveedorTarifaController::class)->biblioteca(new Request());
        $ids = collect($respuesta->getData(true)['proveedor_tarifas'])->pluck('id');

        $this->assertContains($tarifa->id, $ids);
    }

    public function test_destroy_bloqueado_sugiere_desactivar_en_el_mensaje(): void
    {
        $tarifa = $this->crearProveedorTarifa();
        $alternativa = $this->crearAlternativaConPasajeros();

        app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'proveedor',
            'proveedor_tarifa_id' => $tarifa->id,
            'modo_precio' => 'tarifa_fija',
        ]), (string) $alternativa->id);

        $respuesta = app(ProveedorTarifaController::class)->destroy((string) $tarifa->id);
        $payload = $respuesta->getData(true);

        $this->assertSame(422, $payload['code']);
        $this->assertStringContainsString('desactivala', $payload['message']);
        $this->assertStringNotContainsString('hablalo con Umbo', $payload['message']);
        $this->assertNotNull(ProveedorTarifa::find($tarifa->id), 'No debe borrarse cuando está en uso.');
    }

    public function test_destroy_sigue_borrando_de_verdad_cuando_no_esta_en_uso(): void
    {
        $tarifa = $this->crearProveedorTarifa();

        $respuesta = app(ProveedorTarifaController::class)->destroy((string) $tarifa->id);

        $this->assertSame(200, $respuesta->getData(true)['code']);
        $this->assertNull(ProveedorTarifa::find($tarifa->id));
    }

    public function test_item_nuevo_rechaza_tarifa_desactivada(): void
    {
        $tarifa = $this->crearProveedorTarifa();
        app(ProveedorTarifaController::class)->desactivar((string) $tarifa->id);
        $alternativa = $this->crearAlternativaConPasajeros();

        $respuesta = app(AlternativaItemController::class)->store(new Request([
            'origen_tipo' => 'proveedor',
            'proveedor_tarifa_id' => $tarifa->id,
            'modo_precio' => 'tarifa_fija',
        ]), (string) $alternativa->id);

        $this->assertSame(422, $respuesta->getData(true)['code']);
    }
}
