<?php

namespace Tests\Feature;

use App\Http\Controllers\Compras\CompraController;
use App\Models\Compras\Compra;
use App\Models\Compras\CompraLinea;
use App\Models\Product\Product;
use App\Models\Product\StockMovimiento;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\Compras\CompraException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// T-162 — las líneas de una compra como las del comprobante del proveedor, contra Postgres real.
class CompraDetalleComprobanteTest extends TestCase
{
    private Proveedor $proveedor;

    private int $numero = 0;

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
            'database.connections.central.database' => 'sistemafe_test_migrations',
        ]);
        DB::purge('pgsql');
        DB::purge('central');
        DB::beginTransaction();
        DB::connection('central')->beginTransaction();

        auth('api')->setUser(User::factory()->create());
        $this->proveedor = Proveedor::create(['razon_social' => 'T162 Servicios SAC', 'tipo_documento' => 'RUC', 'numero_documento' => '20162000001']);
    }

    protected function tearDown(): void
    {
        DB::connection('central')->rollBack();
        DB::rollBack();
        parent::tearDown();
    }

    private function producto(array $cambios = []): Product
    {
        return Product::create($cambios + [
            'title' => 'Producto T162', 'sku' => 'SKU-T162-'.uniqid(), 'categorie_id' => 1,
            'price_general' => 10, 'price_company' => 8, 'state' => 1, 'unidad_medida' => 'KGM', 'stock' => 10,
            'is_discount' => 0, 'disponiblidad' => 2, 'include_igv' => 0, 'is_icbper' => 0, 'is_ivap' => 0,
            'tipo_bien_servicio' => 'BIEN', 'controla_stock' => true,
        ]);
    }

    /** Línea sin producto. IGV del 18 % solo si es gravada. */
    private function linea(string $descripcion, string $cantidad, string $valorUnitario, string $afectacion = '10', array $cambios = []): array
    {
        $valor = round((float) $cantidad * (float) $valorUnitario, 2);
        $igv = $afectacion === '10' ? round($valor * 0.18, 2) : 0;

        return $cambios + [
            'descripcion' => $descripcion,
            'tipo_afectacion_igv' => $afectacion,
            'cantidad' => $cantidad,
            'valor_unitario' => $valorUnitario,
            'valor_venta' => number_format($valor, 2, '.', ''),
            'igv' => number_format($igv, 2, '.', ''),
            'total' => number_format($valor + $igv, 2, '.', ''),
        ];
    }

    /** Cabecera que cuadra con las líneas, salvo lo que se cambie. */
    private function datos(array $lineas, array $cambios = []): array
    {
        $porTipo = fn (array $tipos) => array_sum(array_column(
            array_filter($lineas, fn ($l) => in_array($l['tipo_afectacion_igv'] ?? '10', $tipos, true)), 'valor_venta'));
        $montos = [
            'base_gravada' => $porTipo(['10']), 'exonerado' => $porTipo(['20']), 'inafecto' => $porTipo(['30', '40']),
            'igv' => array_sum(array_column($lineas, 'igv')), 'otros' => 0,
        ];
        $montos = array_map(fn ($m) => number_format($m, 2, '.', ''), array_intersect_key($cambios, $montos) + $montos);

        return array_merge([
            'proveedor_id' => $this->proveedor->id,
            'tipo_comprobante_codigo' => '01',
            'serie' => 'F162',
            'numero' => (string) ++$this->numero,
            'fecha_emision' => '2026-03-10',
            'moneda' => 'PEN',
            'condicion' => 'contado',
            'con_credito_fiscal' => '1',
            'lineas' => $lineas,
        ], $montos, ['total' => number_format(array_sum($montos), 2, '.', '')], $cambios);
    }

    private function enviar(array $datos, ?int $id = null): JsonResponse
    {
        $request = Request::create('/api/compras', 'POST', $datos);
        try {
            return $id === null
                ? app(CompraController::class)->store($request)
                : app(CompraController::class)->update($request, (string) $id);
        } catch (CompraException $e) {
            return $e->render();
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    private function comprar(array $lineas, array $cambios = []): array
    {
        $respuesta = $this->enviar($this->datos($lineas, $cambios));
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());

        return $respuesta->getData(true);
    }

    private function nota(int $referencia, array $lineas, array $cambios = []): JsonResponse
    {
        return $this->enviar($this->datos($lineas, $cambios + ['tipo_comprobante_codigo' => '07', 'compra_referencia_id' => $referencia]));
    }

    private function anular(int $id): int
    {
        return app(CompraController::class)->anular(new Request(['motivo' => 'Prueba']), (string) $id)->getStatusCode();
    }

    private function assertRechazo(JsonResponse $respuesta, string $texto): void
    {
        $this->assertSame(422, $respuesta->getStatusCode(), $respuesta->getContent());
        $this->assertStringContainsString($texto, $respuesta->getData(true)['message']);
    }

    // CA1
    public function test_lineas_sin_producto_no_mueven_stock(): void
    {
        $movimientos = StockMovimiento::count();
        $respuesta = $this->comprar([
            $this->linea('Transporte Tarapoto–Lamas', '1', '300'),
            $this->linea('  Almuerzo 12 pax  ', '12', '25', '10', ['unidad_medida' => 'NIU', 'codigo_proveedor' => 'ALM-01', 'factor' => '5']),
        ]);

        $this->assertArrayNotHasKey('aviso', $respuesta);
        $this->assertSame($movimientos, StockMovimiento::count());
        $lineas = $respuesta['compra']['lineas'];
        $this->assertSame(['Transporte Tarapoto–Lamas', 'Almuerzo 12 pax'], array_column($lineas, 'descripcion'));
        $this->assertSame(['ZZ', 'NIU'], array_column($lineas, 'unidad_medida'));
        $this->assertSame([null, 'ALM-01'], array_column($lineas, 'codigo_proveedor'));
        $this->assertSame([null, null], array_column($lineas, 'product_id'));
        $this->assertSame([null, null], array_column($lineas, 'producto'));
        $this->assertSame([null, null], array_column($lineas, 'costo_unitario_base'));
        // El factor es de presentaciones de producto: sin producto queda en 1.
        $this->assertSame('1.000000', $lineas[1]['factor']);
        $this->assertSame([], $respuesta['compra']['devolvible']);
        $this->assertSame(200, $this->anular($respuesta['compra']['id']));
        $this->assertSame($movimientos, StockMovimiento::count());
    }

    public function test_con_producto_se_precargan_descripcion_y_unidad_y_solo_ese_mueve_stock(): void
    {
        $producto = $this->producto();
        $flete = $this->linea('Flete', '1', '20');
        $mercaderia = $this->linea('', '10', '6', '10', ['product_id' => $producto->id]);

        $respuesta = $this->comprar([$mercaderia, $flete]);

        $this->assertSame(['Producto T162', 'Flete'], array_column($respuesta['compra']['lineas'], 'descripcion'));
        $this->assertSame(['KGM', 'ZZ'], array_column($respuesta['compra']['lineas'], 'unidad_medida'));
        $this->assertSame(20.0, (float) $producto->refresh()->stock);
        $this->assertSame(1, StockMovimiento::where('documento_tipo', 'compra')->where('documento_id', $respuesta['compra']['id'])->count());
        $this->assertSame(200, $this->anular($respuesta['compra']['id']));
        $this->assertSame(10.0, (float) $producto->refresh()->stock);
    }

    // CA2
    public function test_factura_mixta_cuadra_por_afectacion_o_avisa(): void
    {
        $lineas = [$this->linea('Hospedaje', '1', '100'), $this->linea('Alimentos Amazonía', '1', '50', '20')];

        $this->assertArrayNotHasKey('aviso', $this->comprar($lineas));

        // Suma igual, repartida distinta.
        $respuesta = $this->comprar($lineas, ['base_gravada' => 90, 'exonerado' => 60]);
        $this->assertSame('No cuadra por afectación del IGV: gravado 100.00 en las líneas y 90.00 en el comprobante; '
            .'exonerado 50.00 en las líneas y 60.00 en el comprobante', $respuesta['aviso']);

        $respuesta = $this->comprar($lineas, ['exonerado' => 40]);
        $this->assertSame('Las líneas suman 150.00 y la base del comprobante es 140.00', $respuesta['aviso']);

        // La exportación cuadra contra lo inafecto.
        $this->assertArrayNotHasKey('aviso', $this->comprar([$this->linea('Servicio al exterior', '1', '80', '40')]));
    }

    // CA3
    public function test_linea_no_gravada_no_lleva_igv(): void
    {
        foreach (['20', '30', '40'] as $afectacion) {
            $this->assertRechazo($this->enviar($this->datos([
                $this->linea('Entradas', '1', '100', $afectacion, ['igv' => '18.00', 'total' => '118.00']),
            ])), '«Entradas»: una línea exonerada, inafecta o de exportación no lleva IGV.');
        }
    }

    public function test_valida_descripcion_afectacion_unidad_y_largos(): void
    {
        $enviar = fn (array $cambios) => $this->enviar($this->datos([$cambios + $this->linea('Guía', '1', '100')]));

        $this->assertRechazo($enviar(['descripcion' => '']), 'descripción');
        $this->assertRechazo($enviar(['descripcion' => '   ']), 'descripción');
        $this->assertRechazo($enviar(['descripcion' => str_repeat('a', 501)]), '500 caracteres');
        $this->assertRechazo($enviar(['codigo_proveedor' => str_repeat('a', 51)]), '50 caracteres');
        // El backend no inventa la afectación.
        $this->assertRechazo($enviar(['tipo_afectacion_igv' => '']), 'Elige la afectación');
        $this->assertRechazo($enviar(['tipo_afectacion_igv' => '11']), 'no se admite');
        $this->assertRechazo($enviar(['unidad_medida' => 'XXX']), 'unidad de medida XXX no existe');
        $this->assertRechazo($this->enviar(['lineas' => 'no es json'] + $this->datos([])), 'no se pudieron leer');
        $this->assertRechazo($this->enviar(['lineas' => '[1,2]'] + $this->datos([])), 'lineas');
    }

    // La pantalla manda las líneas como un texto JSON (max_input_vars).
    public function test_acepta_las_lineas_como_texto_json_hasta_cien(): void
    {
        $lineas = array_fill(0, 100, ['product_id' => null, 'codigo_proveedor' => ''] + $this->linea('Entrada', '1', '10'));

        $respuesta = $this->enviar(['lineas' => json_encode($lineas)] + $this->datos($lineas));

        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
        $this->assertCount(100, $respuesta->getData(true)['compra']['lineas']);
        $lineas[] = $lineas[0];
        $this->assertRechazo($this->enviar(['lineas' => json_encode($lineas)] + $this->datos($lineas)), 'hasta 100 líneas');
    }

    // Revisión: en el texto JSON, `""` es «sin producto» y la afectación puede venir como número.
    public function test_texto_json_con_producto_vacio_y_afectacion_numerica(): void
    {
        $lineas = [['product_id' => '', 'codigo_proveedor' => '  '] + $this->linea('  Guía local  ', '1', '50')];
        $enviadas = $lineas;
        $enviadas[0]['tipo_afectacion_igv'] = 10;

        $respuesta = $this->enviar(['lineas' => json_encode($enviadas)] + $this->datos($lineas));

        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
        $linea = CompraLinea::where('compra_id', $respuesta->getData(true)['compra']['id'])->sole();
        $this->assertNull($linea->product_id);
        $this->assertNull($linea->codigo_proveedor);
        $this->assertSame('Guía local', $linea->descripcion);
        $this->assertSame('10', $linea->tipo_afectacion_igv);
    }

    public function test_los_tipos_nuevos_solo_admiten_lineas_sin_producto(): void
    {
        $producto = $this->producto();
        foreach (['02', '05', '14'] as $tipo) {
            $this->comprar([$this->linea('Honorarios', '1', '100', '30')], ['tipo_comprobante_codigo' => $tipo]);
            $this->assertRechazo($this->enviar($this->datos(
                [$this->linea('Mercadería', '1', '100', '10', ['product_id' => $producto->id])],
                ['tipo_comprobante_codigo' => $tipo],
            )), 'no lleva productos');
        }
        $this->assertRechazo($this->enviar($this->datos([$this->linea('Peaje', '1', '10')], ['tipo_comprobante_codigo' => '13'])),
            'no lleva líneas');
    }

    // Sin productos no hubo stock: la cabecera se edita; las líneas siguen fijas.
    public function test_sin_productos_se_edita_la_cabecera_pero_no_las_lineas(): void
    {
        $datos = $this->datos([$this->linea('Transporte', '1', '100')]);
        $id = $this->enviar($datos)->getData(true)['compra']['id'];
        $sinLineas = collect($datos)->except('lineas')->all();

        $otro = Proveedor::create(['razon_social' => 'T162 Otro', 'tipo_documento' => 'RUC', 'numero_documento' => '20162000002']);
        foreach ([['proveedor_id' => $otro->id], ['moneda' => 'USD', 'tipo_cambio' => '3.75'], ['tipo_comprobante_codigo' => '03']] as $cambio) {
            $respuesta = $this->enviar($cambio + $sinLineas, $id);
            $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
        }

        $this->assertRechazo($this->enviar(['tipo_comprobante_codigo' => '13'] + $sinLineas, $id), 'no lleva líneas');
        $this->assertRechazo($this->enviar(['lineas' => [$this->linea('Transporte', '2', '100')]] + $sinLineas, $id), 'anúlala');
        $this->assertSame(['Transporte'], CompraLinea::where('compra_id', $id)->pluck('descripcion')->all());
    }

    // CA6
    public function test_las_notas_de_credito_no_pasan_del_monto_de_la_compra(): void
    {
        $compra = $this->comprar([$this->linea('Transporte', '1', '100')])['compra']['id']; // 118
        $datos = $this->datos([$this->linea('Descuento', '1', '60')], // 70.80
            ['tipo_comprobante_codigo' => '07', 'compra_referencia_id' => $compra, 'serie' => 'FC01']);
        $nota = $this->enviar($datos)->getData(true)['compra']['id'];

        $this->assertRechazo($this->nota($compra, [$this->linea('Devolución', '1', '41')]), 'quedan 47.20 por acreditar');
        $this->assertRechazo($this->nota($compra, [], ['base_gravada' => 100]), 'quedan 47.20');
        $this->assertSame(200, $this->nota($compra, [$this->linea('Devolución', '1', '40')])->getStatusCode()); // justo 47.20

        // Editar la nota no la cuenta dos veces, pero tampoco la deja crecer.
        $sinLineas = collect($datos)->except('lineas')->all();
        $this->assertSame(200, $this->enviar($sinLineas, $nota)->getStatusCode());
        $this->assertRechazo($this->enviar(['base_gravada' => '61.00', 'total' => '71.80'] + $sinLineas, $nota), 'quedan 70.80');

        // Ni la compra puede quedar por debajo de sus notas, ni cambiar de moneda.
        $original = collect($this->datos([], ['base_gravada' => 100, 'igv' => 18]))->except('lineas')->all();
        $original['numero'] = Compra::findOrFail($compra)->numero;
        $this->assertRechazo($this->enviar(['base_gravada' => '99.00', 'total' => '117.00'] + $original, $compra), 'menor que sus notas');
        $this->assertRechazo($this->enviar(['moneda' => 'USD', 'tipo_cambio' => '3.75'] + $original, $compra), 'moneda');
        $this->assertSame(200, $this->enviar($original, $compra)->getStatusCode());

        $this->assertSame(200, $this->anular($nota));
        $this->assertSame(200, $this->nota($compra, [$this->linea('Devolución', '1', '60')])->getStatusCode());
        // En otra moneda no hay con qué comparar.
        $this->assertRechazo($this->nota($compra, [$this->linea('Devolución', '1', '1')], ['moneda' => 'USD', 'tipo_cambio' => '3.75']),
            'misma moneda');
    }

    // CA6: con productos sigue además la regla de D3 (unidades).
    public function test_nota_mixta_respeta_las_unidades_y_la_nota_sin_productos_no_frena_la_anulacion(): void
    {
        $producto = $this->producto();
        $compra = $this->comprar([
            $this->linea('', '10', '6', '10', ['product_id' => $producto->id]),
            $this->linea('Flete', '1', '100'),
        ])['compra']['id'];

        $this->assertRechazo($this->nota($compra, [$this->linea('', '11', '6', '10', ['product_id' => $producto->id])]), 'como máximo 10');
        $flete = $this->nota($compra, [$this->linea('Flete cobrado de más', '1', '100')]);
        $this->assertSame(200, $flete->getStatusCode(), $flete->getContent());
        $this->assertSame(20.0, (float) $producto->refresh()->stock);

        // La nota no devolvió productos: no hay que anularla antes que la compra (D8).
        $this->assertSame(200, $this->anular($compra));
        $this->assertSame(10.0, (float) $producto->refresh()->stock);
    }

    // CA5: la migración completa las líneas anteriores a T-162. El DDL se deshace con la transacción.
    public function test_la_migracion_completa_las_lineas_existentes(): void
    {
        $migracion = require database_path('migrations/tenant/core/2026_11_30_200000_add_detalle_comprobante_to_compra_lineas.php');
        $producto = $this->producto();
        $compra = fn (array $montos) => $this->comprar([], $montos)['compra']['id'];
        $casos = [
            'gravada' => [$compra(['base_gravada' => 100, 'igv' => 18]), 18, '10'],
            'exonerada' => [$compra(['exonerado' => 100]), 0, '20'],
            'inafecta' => [$compra(['inafecto' => 100]), 0, '30'],
            'mixta' => [$compra(['exonerado' => 50, 'inafecto' => 50]), 0, null],
        ];

        $migracion->down();
        $ids = [];
        foreach ($casos as $nombre => [$compraId, $igv]) {
            $ids[$nombre] = DB::table('compra_lineas')->insertGetId([
                'compra_id' => $compraId, 'product_id' => $producto->id, 'factor' => 1, 'cantidad' => 1,
                'valor_unitario' => 100, 'valor_venta' => 100, 'igv' => $igv, 'total' => 100 + $igv, 'costo_unitario_base' => 100,
            ]);
        }
        ob_start();
        $migracion->up();
        $salida = ob_get_clean();

        foreach ($casos as $nombre => [$compraId, , $afectacion]) {
            $linea = CompraLinea::findOrFail($ids[$nombre]);
            $this->assertSame(['Producto T162', 'KGM', $afectacion], [$linea->descripcion, $linea->unidad_medida, $linea->tipo_afectacion_igv], $nombre);
        }
        $this->assertStringContainsString("línea {$ids['mixta']} (compra {$casos['mixta'][0]})", $salida);
        $this->assertStringNotContainsString("línea {$ids['gravada']} ", $salida);
        $this->assertSame(0, CompraLinea::where('descripcion', '')->count());
    }
}
