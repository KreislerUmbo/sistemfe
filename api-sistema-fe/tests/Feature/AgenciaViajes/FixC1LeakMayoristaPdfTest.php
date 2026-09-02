<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\OpcionMayorista;
use App\Models\AgenciaViajes\Proveedor;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Fix C1 — PEGAR-EN-CLAUDE-CODE-fix-leak-mayorista-pdf.md,
// auditoria-arquitectonica-agencia-viajes.md §9.3.
//
// Actualizado en Sesión M2: AlternativaController::resolverNombreItemPdf()
// se eliminó al centralizar — ahora AlternativaController::pdf() llama
// directo a ReservaController::resolverNombreItem($item, null, 'cliente'),
// el ÚNICO resolver de nombre del vertical (confirmado: ningún otro
// lugar del blade referencia proveedor/opcionMayorista directo).
class FixC1LeakMayoristaPdfTest extends TestCase
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

    private function invocarResolverNombreItemPdf(AlternativaItem $item): string
    {
        return ReservaController::resolverNombreItem($item->fresh(['opcionMayorista.proveedor']), null, 'cliente');
    }

    private function crearAlternativaConItemMayorista(?string $descripcionPublica): array
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => '55667788', 'full_name' => 'Cliente Test C1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-C1-' . random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Panamá', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $alternativa = Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa C1', 'estado' => 'borrador',
            'moneda_cotizacion' => 'USD', 'tipo_cambio_aplicado' => 1, 'tipo_cambio_origen' => 'dia',
        ]);

        $proveedor = Proveedor::create(['razon_social' => 'Mayorista Secreto SAC', 'estado' => true]);
        $opcion = OpcionMayorista::create([
            'alternativa_id' => $alternativa->id, 'proveedor_id' => $proveedor->id, 'moneda' => 'USD',
            'estado' => 'elegida', 'descripcion_publica' => $descripcionPublica,
        ]);

        $item = AlternativaItem::create([
            'alternativa_id' => $alternativa->id, 'origen_tipo' => AlternativaItem::ORIGEN_MAYORISTA,
            'opcion_mayorista_id' => $opcion->id, 'modo_precio' => 'tarifa_fija', 'cantidad' => 1,
            'moneda_costo' => 'USD', 'costo_snapshot' => 100, 'precio_venta_snapshot' => 150, 'precio_convertido' => 150,
        ]);

        return [$item, $proveedor];
    }

    public function test_sin_descripcion_publica_el_pdf_no_revela_la_razon_social_del_proveedor(): void
    {
        [$item, $proveedor] = $this->crearAlternativaConItemMayorista(null);

        $nombre = $this->invocarResolverNombreItemPdf($item);

        $this->assertSame('Paquete mayorista', $nombre);
        $this->assertStringNotContainsString($proveedor->razon_social, $nombre);
    }

    public function test_con_descripcion_publica_el_pdf_muestra_exactamente_ese_texto(): void
    {
        [$item, $proveedor] = $this->crearAlternativaConItemMayorista('Paquete Panamá 6D/5N');

        $nombre = $this->invocarResolverNombreItemPdf($item);

        $this->assertSame('Paquete Panamá 6D/5N', $nombre);
        $this->assertStringNotContainsString($proveedor->razon_social, $nombre);
    }

    // Regresión — ReservaController::resolverNombreItem() (uso INTERNO,
    // reporte operativo) NO se tocó: el fallback a datos del proveedor
    // real sigue siendo correcto ahí, a propósito, ver brief §"contexto".
    public function test_resolver_interno_de_reserva_sigue_cayendo_al_proveedor_real(): void
    {
        [$item, $proveedor] = $this->crearAlternativaConItemMayorista(null);

        $nombre = ReservaController::resolverNombreItem($item->fresh(['opcionMayorista.proveedor']));

        $this->assertSame($proveedor->razon_social, $nombre);
    }

    // Regresión end-to-end contra el HTML real del blade (no solo el
    // resolver aislado) — confirma que ningún OTRO punto del template
    // interpola opcionMayorista directo, saltándose el resolver (la única
    // referencia a "razon_social" que SÍ debe seguir ahí es la de
    // $empresa — la propia agencia, no el mayorista, dato legítimo).
    public function test_el_blade_del_pdf_no_referencia_opcion_mayorista_directo(): void
    {
        $blade = file_get_contents(base_path('resources/views/pdf/agencia-viajes/alternativa.blade.php'));

        $this->assertStringNotContainsString('opcionMayorista', $blade);
        $this->assertStringNotContainsString('proveedor', $blade);
    }
}
