<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\AlternativaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\Proveedor;
use App\Services\AgenciaViajes\AlternativaPdfService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\TestCase;

// T-023 — PDF de cotización con la comparación de hoteles abierta (H4) y descuento bien
// calculado (H10). Se verifica sobre el HTML real que se manda a DomPDF.
class T023PdfComparacionAbiertaTest extends TestCase
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

    private function crearAlternativa(string $moneda = 'PEN', float $tc = 1): Alternativa
    {
        $clienteId = DB::table('clients')->insertGetId([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10000000, 99999999), 'full_name' => 'Cliente Test T023',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cotizacionId = DB::table('cotizaciones')->insertGetId([
            'codigo_prefijo' => 'TEST', 'codigo' => 'TEST-T023-'.random_int(1000, 9999), 'cliente_id' => $clienteId,
            'destino' => 'Tarapoto', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Alternativa::create([
            'cotizacion_id' => $cotizacionId, 'nombre' => 'Alternativa T023', 'estado' => 'borrador',
            'moneda_cotizacion' => $moneda, 'tipo_cambio_aplicado' => $tc, 'tipo_cambio_origen' => 'dia',
        ]);
    }

    // Hotel de catálogo con una tarifa; devuelve el ítem. $precio = precio de lista en $moneda.
    private function hotel(
        Alternativa $alternativa,
        string $nombre,
        float $precio,
        ?string $grupo = null,
        bool $elegida = false,
        string $tipoHabitacion = 'doble',
        string $moneda = 'PEN',
    ): AlternativaItem {
        $destinoAtractivoId = DB::table('destinos_atractivos')->insertGetId([
            'nombre' => 'Zona T023 '.random_int(1000, 99999), 'tipo' => 'lugar', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $servicioId = DB::table('servicios')->insertGetId(['nombre' => 'Hospedaje T023', 'created_at' => now(), 'updated_at' => now()]);
        $destinoServicioId = DB::table('destino_servicio')->insertGetId([
            'destino_atractivo_id' => $destinoAtractivoId, 'servicio_id' => $servicioId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $proveedor = Proveedor::create(['razon_social' => $nombre, 'estado' => true]);
        $psId = DB::table('proveedor_servicios')->insertGetId([
            'proveedor_id' => $proveedor->id, 'destino_servicio_id' => $destinoServicioId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $tarifaId = DB::table('proveedor_tarifas')->insertGetId([
            'proveedor_servicio_id' => $psId, 'tipo_tarifa' => 'publica', 'modalidad' => 'privado', 'moneda' => $moneda,
            'precio_costo' => $precio * 0.7, 'margen_tipo' => 'fijo', 'margen_valor' => $precio * 0.3, 'precio_venta_adulto' => $precio,
            'tipo_habitacion' => $tipoHabitacion, 'vigente_desde' => '2026-01-01', 'tip_afe_igv' => '10', 'destino_tributario' => 'nacional',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->item($alternativa, $precio, $moneda, [
            'origen_tipo' => 'proveedor', 'proveedor_tarifa_id' => $tarifaId,
            'grupo_opcion_id' => $grupo, 'opcion_elegida' => $elegida,
        ]);
    }

    // Servicio suelto (sin grupo). $modo 'por_persona': el snapshot ya es el total de la línea.
    private function servicio(Alternativa $alternativa, float $precio, string $moneda = 'PEN', string $modo = 'tarifa_fija', int $cantidad = 1): AlternativaItem
    {
        return $this->item($alternativa, $precio, $moneda, [
            'origen_tipo' => 'manual', 'descripcion_manual' => 'Servicio T023', 'modo_precio' => $modo, 'cantidad' => $cantidad,
        ]);
    }

    private function item(Alternativa $alternativa, float $precio, string $moneda, array $extra): AlternativaItem
    {
        $convertido = $moneda === $alternativa->moneda_cotizacion
            ? $precio
            : ($moneda === 'USD' ? $precio * (float) $alternativa->tipo_cambio_aplicado : round($precio / (float) $alternativa->tipo_cambio_aplicado, 2));

        return AlternativaItem::create($extra + [
            'alternativa_id' => $alternativa->id, 'modo_precio' => 'tarifa_fija', 'cantidad' => 1, 'moneda_costo' => $moneda,
            'costo_snapshot' => round($precio * 0.7, 2), 'precio_venta_snapshot' => $precio, 'precio_convertido' => $convertido,
        ]);
    }

    // Deja alternativas.total como lo deja el sistema al guardar (con el descuento global vigente).
    private function recalcular(Alternativa $alternativa, float $descuentoPct = 0): void
    {
        // update() guarda el % en la columna y luego llama a aplicarDescuentoGlobal().
        $alternativa->update(['descuento_global_pct' => $descuentoPct]);
        app(AlternativaController::class)->aplicarDescuentoGlobal($alternativa->fresh(), $descuentoPct);
    }

    // [datos de la vista, html renderizado] del PDF real de la alternativa.
    private function pdf(Alternativa $alternativa): array
    {
        $datos = null;
        View::composer('pdf.agencia-viajes.alternativa', function ($view) use (&$datos) {
            $datos = $view->getData();
        });
        $bytes = app(AlternativaPdfService::class)->contenido((string) $alternativa->id)['bytes'];
        $this->assertNotEmpty($bytes);

        return [$datos, view('pdf.agencia-viajes.alternativa', $datos)->render()];
    }

    private function html(Alternativa $alternativa): string
    {
        return $this->pdf($alternativa)[1];
    }

    // ── H10 ─────────────────────────────────────────────────────────────────────────────

    // CA1 — el caso real del 10-nov: Cumbaza 300 vs Rio Sol 560 (elegida), sin descuento.
    public function test_ca1_grupo_resuelto_sin_descuento_no_dice_incluye_descuento(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $this->hotel($alternativa, 'Cumbaza Hotel', 300, $grupo, false);
        $this->hotel($alternativa, 'Hotel Rio Sol', 560, $grupo, true);
        $this->recalcular($alternativa, 0);

        [$datos, $html] = $this->pdf($alternativa);

        $this->assertEquals(560.0, $datos['total']);
        $this->assertFalse($datos['hayDescuento']);
        $this->assertStringNotContainsString('Incluye descuento', $html);
    }

    // CA2 — alternativa en USD con un ítem en PEN: el snapshot no se convierte hoy.
    public function test_ca2_item_en_otra_moneda_sin_descuento_no_dice_incluye_descuento(): void
    {
        $alternativa = $this->crearAlternativa('USD', 3.7);
        $this->servicio($alternativa, 370, 'PEN'); // = USD 100
        $this->servicio($alternativa, 50, 'USD');
        $this->recalcular($alternativa, 0);

        [$datos, $html] = $this->pdf($alternativa);

        $this->assertEquals(150.0, $datos['total']);
        $this->assertFalse($datos['hayDescuento']);
        $this->assertStringNotContainsString('Incluye descuento', $html);
    }

    // CA2 (variante) — ítem por_persona: su snapshot ya es el total de la línea, no se multiplica.
    public function test_ca2_item_por_persona_no_multiplica_por_cantidad(): void
    {
        $alternativa = $this->crearAlternativa();
        $this->servicio($alternativa, 400, 'PEN', 'por_persona', 4);
        $this->recalcular($alternativa, 0);

        [$datos] = $this->pdf($alternativa);

        $this->assertEquals(400.0, $datos['total']);
        $this->assertEquals(400.0, $datos['totalOriginal']);
        $this->assertFalse($datos['hayDescuento']);
    }

    // CA3 — con descuento global real del 10% el monto mostrado es el real.
    public function test_ca3_descuento_global_real_muestra_el_monto_real(): void
    {
        ConfiguracionAgencia::query()->delete();
        ConfiguracionAgencia::create(['dias_vigencia_cotizacion' => 7, 'mostrar_descuento_como_linea' => true]);
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $this->hotel($alternativa, 'Cumbaza Hotel', 300, $grupo, false);
        $this->hotel($alternativa, 'Hotel Rio Sol', 500, $grupo, true);
        $this->servicio($alternativa, 500);
        $this->recalcular($alternativa, 10);

        [$datos, $html] = $this->pdf($alternativa);

        // Lista: 500 + 500 = 1000; con 10% → 900.
        $this->assertEquals(900.0, $datos['total']);
        $this->assertEquals(1000.0, $datos['totalOriginal']);
        $this->assertEquals(100.0, $datos['descuentoMonto']);
        $this->assertStringContainsString('Incluye descuento aplicado: -PEN 100.00', $html);
    }

    // ── H4 ──────────────────────────────────────────────────────────────────────────────

    // CA4 — un grupo abierto: un total por opción, ninguna fila resaltada.
    public function test_ca4_un_grupo_abierto_muestra_un_total_por_opcion(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $this->hotel($alternativa, 'Hotel A', 1000, $grupo);
        $this->hotel($alternativa, 'Hotel B', 1200, $grupo);
        $this->servicio($alternativa, 500);
        $this->recalcular($alternativa, 0);

        [$datos, $html] = $this->pdf($alternativa);

        $this->assertSame('por_opcion', $datos['precioPorOpcion']['modo']);
        $this->assertStringContainsString('Con Hotel A (doble):', $html);
        $this->assertStringContainsString('Con Hotel B (doble):', $html);
        $this->assertStringContainsString('1,500.00', $html);
        $this->assertStringContainsString('1,700.00', $html);
        $this->assertStringNotContainsString('precio-elegido-nota">Elegida', $html);
        $this->assertStringNotContainsString('opción confirmada resaltada', $html);
        $this->assertFalse($datos['hayDescuento']);
    }

    // CA4 (variante) — con descuento global, la fila "elegida" recibe el % como en aplicarDescuentoGlobal().
    public function test_ca4_el_descuento_global_se_aplica_a_la_opcion_y_a_los_servicios(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $this->hotel($alternativa, 'Hotel A', 1000, $grupo);
        $this->hotel($alternativa, 'Hotel B', 1200, $grupo);
        $this->servicio($alternativa, 500);
        $this->recalcular($alternativa, 10);

        [$datos] = $this->pdf($alternativa);

        // Con A: 1000*0.9 + 500*0.9 = 1350; con B: 1200*0.9 + 450 = 1530. Es lo que daría elegirla de verdad.
        $this->assertEquals([1350.0, 1530.0], array_column($datos['precioPorOpcion']['opciones'], 'total'));
        foreach ([0, 1] as $i) {
            $real = $this->crearAlternativa();
            $g = (string) Str::uuid();
            $this->hotel($real, 'Hotel A', 1000, $g, $i === 0);
            $this->hotel($real, 'Hotel B', 1200, $g, $i === 1);
            $this->servicio($real, 500);
            $this->recalcular($real, 10);
            $this->assertEquals($datos['precioPorOpcion']['opciones'][$i]['total'], (float) $real->fresh()->total);
        }
    }

    // CA5 — dos grupos abiertos: "Desde" el mínimo de cada uno.
    public function test_ca5_dos_grupos_abiertos_muestran_desde_con_el_minimo(): void
    {
        $alternativa = $this->crearAlternativa();
        $g1 = (string) Str::uuid();
        $g2 = (string) Str::uuid();
        $this->hotel($alternativa, 'Hotel A', 1000, $g1);
        $this->hotel($alternativa, 'Hotel B', 1200, $g1);
        $this->hotel($alternativa, 'Hotel C', 800, $g2);
        $this->hotel($alternativa, 'Hotel D', 900, $g2);
        $this->servicio($alternativa, 500);
        $this->recalcular($alternativa, 0);

        [$datos, $html] = $this->pdf($alternativa);

        $this->assertSame('desde', $datos['precioPorOpcion']['modo']);
        $this->assertEquals(2300.0, $datos['precioPorOpcion']['desde']); // 1000 + 800 + 500
        $this->assertStringContainsString('Desde', $html);
        $this->assertStringContainsString('2,300.00', $html);
        $this->assertStringContainsString('el total final depende de los hoteles que elijas', $html);
        $this->assertStringNotContainsString('Con Hotel A', $html);
    }

    // CA6 — grupo resuelto: un solo total y la elegida resaltada, como siempre.
    public function test_ca6_grupo_resuelto_sale_como_antes(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $this->hotel($alternativa, 'Hotel A', 1000, $grupo, false);
        $this->hotel($alternativa, 'Hotel B', 1200, $grupo, true);
        $this->recalcular($alternativa, 0);

        [$datos, $html] = $this->pdf($alternativa);

        $this->assertSame('ninguno', $datos['precioPorOpcion']['modo']);
        $this->assertStringContainsString('1,200.00', $html);
        $this->assertStringNotContainsString('Con Hotel', $html);
        $this->assertStringContainsString('precio-elegido-nota">Elegida', $html);
    }

    // Seguridad: el nombre del hotel va escapado en el bloque de precios.
    public function test_nombre_de_hotel_se_escapa_en_el_bloque_de_precios(): void
    {
        $alternativa = $this->crearAlternativa();
        $grupo = (string) Str::uuid();
        $this->hotel($alternativa, '<b>Hotel X</b>', 100, $grupo);
        $this->hotel($alternativa, 'Hotel Y', 200, $grupo);
        $this->recalcular($alternativa, 0);

        $html = $this->html($alternativa);

        $this->assertStringContainsString('Con &lt;b&gt;Hotel X&lt;/b&gt;', $html);
    }
}
