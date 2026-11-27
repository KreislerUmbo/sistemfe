<?php

namespace Tests\Feature;

use App\Http\Controllers\Compras\CompraController;
use App\Http\Controllers\Product\ProductController;
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

// T-154 — líneas de producto en la compra, contra Postgres real. CA6 (dos compras a la vez) está
// en CompraConcurrenciaTest.
class CompraLineasTest extends TestCase
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
        $this->proveedor = Proveedor::create(['razon_social' => 'T154 Mayorista SAC', 'tipo_documento' => 'RUC', 'numero_documento' => '20154000001']);
    }

    protected function tearDown(): void
    {
        DB::connection('central')->rollBack();
        DB::rollBack();
        parent::tearDown();
    }

    private function producto(float $stock = 10, ?string $costo = '5', array $cambios = []): Product
    {
        $producto = Product::create($cambios + [
            'title' => 'Producto T154', 'sku' => 'SKU-T154-'.uniqid(), 'categorie_id' => 1,
            'price_general' => 10, 'price_company' => 8, 'state' => 1, 'unidad_medida' => 'NIU', 'stock' => $stock,
            'is_discount' => 0, 'disponiblidad' => 2, 'include_igv' => 0, 'is_icbper' => 0, 'is_ivap' => 0,
            'tipo_bien_servicio' => 'BIEN', 'controla_stock' => true,
        ]);
        $producto->forceFill(['costo_promedio' => $costo])->save();

        return $producto;
    }

    /** Línea con IGV 18 % y montos coherentes. */
    private function linea(Product $producto, string $cantidad, string $valorUnitario, array $cambios = []): array
    {
        $valor = round((float) $cantidad * (float) $valorUnitario, 2);

        return $cambios + [
            'product_id' => $producto->id,
            'tipo_afectacion_igv' => '10',
            'cantidad' => $cantidad,
            'factor' => '1',
            'valor_unitario' => $valorUnitario,
            'valor_venta' => number_format($valor, 2, '.', ''),
            'igv' => number_format($valor * 0.18, 2, '.', ''),
            'total' => number_format($valor + round($valor * 0.18, 2), 2, '.', ''),
        ];
    }

    private function datos(array $lineas, array $cambios = []): array
    {
        $base = array_sum(array_column($lineas, 'valor_venta')) ?: 100;
        $igv = round($base * 0.18, 2);

        return array_merge([
            'proveedor_id' => $this->proveedor->id,
            'tipo_comprobante_codigo' => '01',
            'serie' => 'F154',
            'numero' => (string) ++$this->numero,
            'fecha_emision' => '2026-03-10',
            'moneda' => 'PEN',
            'base_gravada' => number_format($base, 2, '.', ''),
            'igv' => number_format($igv, 2, '.', ''),
            'exonerado' => '0',
            'inafecto' => '0',
            'otros' => '0',
            'total' => number_format($base + $igv, 2, '.', ''),
            'condicion' => 'contado',
            'con_credito_fiscal' => '1',
            'lineas' => $lineas,
        ], $cambios);
    }

    private function responder(callable $accion): JsonResponse
    {
        try {
            return $accion();
        } catch (CompraException $e) {
            return $e->render();
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    private function enviar(array $datos, ?int $id = null): JsonResponse
    {
        $request = Request::create('/api/compras', 'POST', $datos);

        return $this->responder(fn () => $id === null
            ? app(CompraController::class)->store($request)
            : app(CompraController::class)->update($request, (string) $id));
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

    private function anular(int $id): JsonResponse
    {
        return $this->responder(fn () => app(CompraController::class)->anular(new Request(['motivo' => 'Prueba']), (string) $id));
    }

    private function assertRechazo(JsonResponse $respuesta, string $texto): void
    {
        $this->assertSame(422, $respuesta->getStatusCode(), $respuesta->getContent());
        $this->assertStringContainsString($texto, $respuesta->getData(true)['message']);
    }

    private function assertProducto(Product $producto, float $stock, ?string $costo): void
    {
        $producto->refresh();
        $this->assertSame([$stock, $costo], [(float) $producto->stock, $producto->costo_promedio]);
    }

    private function kardex(Product $producto): array
    {
        return StockMovimiento::where('product_id', $producto->id)->orderBy('id')->get()
            ->map(fn (StockMovimiento $m) => [$m->tipo, $m->cantidad, $m->costo_unitario, $m->saldo, $m->documento_tipo, $m->documento_id])
            ->all();
    }

    public function test_ca1_entra_con_o_sin_igv_segun_el_credito_fiscal_y_promedia(): void
    {
        $producto = $this->producto();
        $id = $this->comprar([$this->linea($producto, '10', '6')])['compra']['id'];

        $this->assertProducto($producto, 20.0, '5.500000');
        $this->assertSame([['compra', 10.0, 6.0, 20.0, 'compra', $id]], $this->kardex($producto));

        $sinCredito = $this->producto();
        $this->comprar([$this->linea($sinCredito, '10', '6')], ['con_credito_fiscal' => '0']);

        $this->assertProducto($sinCredito, 20.0, '6.040000');
        $this->assertSame(7.08, $this->kardex($sinCredito)[0][2]);

        // La ficha y su kárdex muestran el costo y el documento.
        $ficha = app(ProductController::class)->show((string) $producto->id)->getData(true);
        $this->assertSame('5.500000', $ficha['product']['costo_promedio']);
        $fila = app(ProductController::class)->kardex(new Request, (string) $producto->id)->getData(true)['movimientos'][0];
        $this->assertSame([6, "Compra #{$id} (F154-1)"], [$fila['costo_unitario'], $fila['documento']]);
    }

    public function test_ca1_sin_stock_o_sin_costo_previo_el_promedio_es_el_de_la_compra(): void
    {
        $agotado = $this->producto(0, '99');
        $sinCosto = $this->producto(4, null);
        $this->comprar([$this->linea($agotado, '2', '3'), $this->linea($sinCosto, '1', '4'), $this->linea($sinCosto, '3', '8')]);

        $this->assertProducto($agotado, 2.0, '3.000000');
        // Dos líneas del mismo producto: una fila de kárdex con su costo ponderado.
        $this->assertProducto($sinCosto, 8.0, '7.000000');
        $this->assertCount(1, $this->kardex($sinCosto));
    }

    public function test_ca2_una_compra_sin_lineas_no_toca_stock_kardex_ni_costo(): void
    {
        $producto = $this->producto();
        $antes = StockMovimiento::count();
        $respuesta = $this->comprar([]);
        $this->assertArrayNotHasKey('aviso', $respuesta);
        $this->assertSame(200, $this->anular($respuesta['compra']['id'])->getStatusCode());

        $this->assertProducto($producto, 10.0, '5.000000');
        $this->assertSame($antes, StockMovimiento::count());
    }

    public function test_ca3_anular_devuelve_stock_y_promedio_y_no_deja_si_ya_se_vendio(): void
    {
        $producto = $this->producto();
        $id = $this->comprar([$this->linea($producto, '10', '6')])['compra']['id'];
        $this->assertSame(200, $this->anular($id)->getStatusCode());

        $this->assertProducto($producto, 10.0, '5.000000');
        $this->assertSame(['compra_anulada', -10.0, 6.0, 10.0, 'compra', $id], $this->kardex($producto)[1]);

        $vendido = $this->producto();
        $id = $this->comprar([$this->linea($vendido, '10', '6')])['compra']['id'];
        $vendido->refresh()->forceFill(['stock' => 5])->save(); // se vendieron 15

        $respuesta = $this->anular($id);
        $this->assertRechazo($respuesta, 'nota de crédito del proveedor');
        $this->assertStringContainsString('ajuste de stock', $respuesta->getData(true)['message']);
        $this->assertProducto($vendido, 5.0, '5.500000');
        $this->assertNull(Compra::findOrFail($id)->anulada_at);
    }

    public function test_ca3_si_no_queda_stock_o_el_promedio_saldria_negativo_no_cambia(): void
    {
        $producto = $this->producto(0, null);
        $id = $this->comprar([$this->linea($producto, '10', '6')])['compra']['id'];
        $this->anular($id);
        $this->assertProducto($producto, 0.0, '6.000000');

        $barato = $this->producto(0, null);
        $id = $this->comprar([$this->linea($barato, '1', '100')])['compra']['id'];
        $barato->forceFill(['stock' => 2, 'costo_promedio' => '1'])->save();
        $this->assertSame(200, $this->anular($id)->getStatusCode());
        $this->assertProducto($barato, 1.0, '1.000000');
    }

    public function test_ca4_la_nota_devuelve_sin_cambiar_el_promedio_y_anularla_repone(): void
    {
        $producto = $this->producto();
        $otro = $this->producto();
        $compra = $this->comprar([$this->linea($producto, '10', '6')])['compra']['id'];

        $respuesta = $this->nota($compra, [$this->linea($producto, '2', '6')]);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
        $nota = $respuesta->getData(true)['compra']['id'];

        $this->assertProducto($producto, 18.0, '5.500000');
        $this->assertSame(['nc_compra', -2.0, 5.5, 18.0, 'compra', $nota], $this->kardex($producto)[1]);

        // D3: no más de lo comprado menos lo devuelto, ni productos de fuera.
        $this->assertRechazo($this->nota($compra, [$this->linea($producto, '9', '6')]), 'como máximo 8');
        $this->assertRechazo($this->nota($compra, [$this->linea($otro, '1', '6')]), 'como máximo 0');
        $this->assertProducto($producto, 18.0, '5.500000');
        $devolvible = app(CompraController::class)->show((string) $compra)->getData(true)['compra']['devolvible'];
        $this->assertSame([['product_id' => $producto->id, 'maximo' => 8]], $devolvible);

        $this->assertSame(200, $this->anular($nota)->getStatusCode());
        $this->assertProducto($producto, 20.0, '5.500000');
        $this->assertSame(['nc_compra_anulada', 2.0, 5.5, 20.0, 'compra', $nota], $this->kardex($producto)[2]);
        // Anulada, ya no cuenta como devuelto.
        $this->assertSame(200, $this->nota($compra, [$this->linea($producto, '10', '6')])->getStatusCode());
    }

    public function test_ca5_el_factor_multiplica_las_unidades_y_divide_el_costo(): void
    {
        $producto = $this->producto(0, null);
        $id = $this->comprar([$this->linea($producto, '2', '60', ['factor' => '12'])])['compra']['id'];

        $this->assertProducto($producto, 24.0, '5.000000');
        $this->assertSame('5.000000', CompraLinea::where('compra_id', $id)->value('costo_unitario_base'));
    }

    public function test_ca7_en_dolares_el_costo_se_guarda_en_soles(): void
    {
        $producto = $this->producto(0, null);
        $this->comprar([$this->linea($producto, '10', '2')], ['moneda' => 'USD', 'tipo_cambio' => '3.75']);

        $this->assertProducto($producto, 10.0, '7.500000');
    }

    public function test_ca8_con_productos_solo_se_edita_la_cabecera_y_se_anula_en_orden(): void
    {
        $producto = $this->producto();
        $datos = $this->datos([$this->linea($producto, '10', '6')]);
        $id = $this->enviar($datos)->getData(true)['compra']['id'];

        // Cambia serie y observaciones, mandando las mismas líneas o ninguna.
        $this->assertSame(200, $this->enviar(['serie' => 'F999', 'observaciones' => 'Corregida'] + $datos, $id)->getStatusCode());
        $sinLineas = collect($datos)->except(['lineas', 'con_credito_fiscal'])->all();
        $this->assertSame(200, $this->enviar($sinLineas, $id)->getStatusCode());
        $this->assertTrue(Compra::findOrFail($id)->con_credito_fiscal);

        $otroProveedor = Proveedor::create(['razon_social' => 'T154 Otro', 'tipo_documento' => 'RUC', 'numero_documento' => '20154000002']);
        foreach ([
            ['moneda' => 'USD', 'tipo_cambio' => '3.75'],
            ['proveedor_id' => $otroProveedor->id],
            ['tipo_comprobante_codigo' => '03'],
            ['con_credito_fiscal' => '0'],
            ['lineas' => [$this->linea($producto, '11', '6')]],
        ] as $cambio) {
            $this->assertRechazo($this->enviar($cambio + $datos, $id), 'anúlala y regístrala de nuevo');
        }
        $this->assertProducto($producto, 20.0, '5.500000');
        $this->assertCount(1, $this->kardex($producto));

        // Una compra sin productos no puede recibirlos al editarla.
        $vacia = $this->datos([]);
        $idVacia = $this->enviar($vacia)->getData(true)['compra']['id'];
        $this->assertRechazo($this->enviar(['lineas' => [$this->linea($producto, '1', '6')]] + $vacia, $idVacia), 'anúlala');

        // D8: primero la nota con líneas; una nota sin líneas no estorba.
        $nota = $this->nota($id, [$this->linea($producto, '1', '6')], ['serie' => 'FC01'])->getData(true)['compra'];
        // T-162: las notas de una compra, juntas, ya no pasan de su total.
        $this->assertSame(200, $this->nota($id, [], ['base_gravada' => '10', 'igv' => '1.80', 'total' => '11.80'])->getStatusCode());
        $this->assertRechazo($this->anular($id), "Anula primero la nota de crédito FC01-{$nota['numero']}");
        $this->assertSame(200, $this->anular($nota['id'])->getStatusCode());
        $this->assertSame(200, $this->anular($id)->getStatusCode());
        $this->assertProducto($producto, 10.0, '5.000000');
    }

    public function test_ca9_tipos_sin_lineas_producto_sin_stock_y_aviso_de_cuadre(): void
    {
        $producto = $this->producto();
        foreach (['02', '05', '08', '10', '13', '14'] as $tipo) {
            $this->assertRechazo($this->enviar($this->datos([$this->linea($producto, '1', '6')], ['tipo_comprobante_codigo' => $tipo,
                'compra_referencia_id' => $tipo === '08' ? $this->comprar([])['compra']['id'] : null])), 'no lleva productos');
        }

        // Montos y producto de la línea.
        $this->assertRechazo($this->enviar($this->datos([$this->linea($producto, '10', '6', ['valor_venta' => '60.01'])])), 'cantidad × valor unitario');
        $this->assertRechazo($this->enviar($this->datos([$this->linea($producto, '0', '6')])), 'mayor a cero');
        $this->assertRechazo($this->enviar($this->datos([$this->linea($producto, '1', '6', ['factor' => '0'])])), 'factor');
        $this->assertRechazo($this->enviar($this->datos([$this->linea($producto, '1', '6', ['igv' => '-1'])])), 'negativos');
        $this->assertRechazo($this->enviar($this->datos([$this->linea($producto, '1', '6', ['product_id' => 999999999])])), 'no existe');
        // Revisión: el total es el costo sin crédito fiscal; un valor unitario vacío no pasa como 0.
        $this->assertRechazo($this->enviar($this->datos([$this->linea($producto, '1', '6', ['total' => '9.00'])],
            ['con_credito_fiscal' => '0'])), 'valor de venta + IGV (7.08)');
        $this->assertRechazo($this->enviar($this->datos([$this->linea($producto, '1', '6', ['valor_unitario' => ''])])), 'Completa los montos');
        // Unidades demasiado chicas: 422, no un 500 por desbordar costo_unitario_base.
        $this->assertRechazo($this->enviar($this->datos([$this->linea($producto, '0.000001', '0', ['factor' => '0.000001'])])), 'demasiado chica');
        $this->assertProducto($producto, 10.0, '5.000000');

        $servicio = $this->producto(0, null, ['tipo_bien_servicio' => 'SERVICIO']);
        $respuesta = $this->comprar([$this->linea($servicio, '3', '6')]);
        $this->assertArrayNotHasKey('aviso', $respuesta);
        $this->assertCount(1, $respuesta['compra']['lineas']);
        $this->assertProducto($servicio, 0.0, null);
        $this->assertSame([], $this->kardex($servicio));
        // Empieza a controlar stock después: anular no le quita lo que nunca entró.
        $servicio->forceFill(['tipo_bien_servicio' => 'BIEN'])->save();
        $this->assertSame(200, $this->anular($respuesta['compra']['id'])->getStatusCode());
        $this->assertProducto($servicio, 0.0, null);

        $descuadrada = $this->comprar([$this->linea($producto, '10', '6')], ['base_gravada' => '50', 'exonerado' => '5', 'igv' => '9', 'total' => '64']);
        $this->assertSame('Los productos suman 60.00 y la base del comprobante es 55.00', $descuadrada['aviso']);
        $this->assertProducto($producto, 20.0, '5.500000');
    }
}
