<?php

declare(strict_types=1);

namespace Tests\Feature\Ventas;

use App\Http\Controllers\Greenter\GreenterService;
use App\Http\Controllers\Product\ProductController;
use App\Http\Controllers\Sale\NotaElectronicaController;
use App\Http\Controllers\Sale\SaleController;
use App\Models\Cash\Branch;
use App\Models\Client\Client;
use App\Models\Company;
use App\Models\Product\Categorie;
use App\Models\Product\Product;
use App\Models\Product\StockMovimiento;
use App\Models\Sale\Note;
use App\Models\Sale\NoteDetail;
use App\Models\Sale\SaleDetail;
use App\Models\Sale\SerieComprobante;
use App\Models\User;
use Greenter\Factory\FeFactory;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Response\BaseResult;
use Greenter\Model\Sale\Note as GreenterNote;
use Greenter\See;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Creditos\CreditosTestCase;

/** T-153 — kárdex y servicio único de stock. Un test por criterio (CA1, CA2, CA3, CA5). */
final class KardexStockTest extends CreditosTestCase
{
    private Categorie $categoria;
    private Client $cliente;
    private User $vendedor;

    protected function setUp(): void
    {
        parent::setUp();
        $sede = Branch::create(['name' => 'Sede T153 ' . uniqid(), 'is_active' => true]);
        SerieComprobante::create([
            'branch_id' => $sede->id, 'tipo_comprobante_codigo' => 'NV', 'moneda' => 'PEN', 'serie' => 'NV01',
            'correlativo_actual' => 0, 'correlativo_inicial' => 1, 'fecha_inicio' => '2026-01-01', 'activo' => true,
        ]);
        $this->categoria = Categorie::create(['title' => 'Cat T153', 'state' => 1]);

        $usuario = User::factory()->create(['branch_id' => $sede->id]);
        $rol = Role::create(['name' => 'rol-t153-' . uniqid(), 'guard_name' => 'api']);
        foreach (['emitir_nota_venta', 'edit_sale', 'cambiar_precio_venta'] as $p) {
            $rol->givePermissionTo(Permission::firstOrCreate(['name' => $p, 'guard_name' => 'api']));
        }
        $usuario->assignRole($rol);
        $usuario->role_id = $rol->id;
        $usuario->save();
        $this->vendedor = $usuario->fresh();
        Auth::guard('api')->setUser($this->vendedor);

        $this->cliente = Client::factory()->create(['n_document' => '45678912', 'type_client' => 1]);
    }

    private function datosProducto(array $extra = []): array
    {
        return $extra + [
            'title' => 'Bien T153 ' . uniqid(), 'sku' => 'SKU-T153-' . uniqid(), 'categorie_id' => $this->categoria->id,
            'price_general' => 10, 'price_company' => 8, 'state' => 1, 'unidad_medida' => 'NIU', 'stock' => 10,
            'is_discount' => 0, 'disponiblidad' => 2, 'include_igv' => 0, 'is_icbper' => 0,
            'is_ivap' => 0, 'is_isc' => 0, 'is_especial_nota' => 0, 'tip_afe_igv_default' => '10',
            'tipo_bien_servicio' => 'BIEN',
        ];
    }

    /** Alta por la pantalla de productos: deja la fila «stock inicial». */
    private function crear(array $extra = []): Product
    {
        $r = app(ProductController::class)->store(new Request($this->datosProducto($extra)));

        return Product::findOrFail(json_decode($r->getContent(), true)['product']['id']);
    }

    /** Edición por la ficha: la pantalla solo manda `stock` si el usuario lo cambió. */
    private function editar(Product $p, array $extra): void
    {
        $datos = $this->datosProducto(['title' => $p->title, 'sku' => $p->sku]);
        unset($datos['stock']);
        app(ProductController::class)->update(new Request($extra + $datos), (string) $p->id);
    }

    private function linea(Product $p, int|float $cantidad, ?int $id = null): array
    {
        $subtotal = round(10 * $cantidad, 2);
        $igv = round($subtotal * 0.18, 2);

        return array_filter([
            'id' => $id,
            'product' => ['id' => $p->id, 'categorie_id' => $this->categoria->id],
            'unidad_medida' => 'NIU', 'quantity' => $cantidad, 'price_base' => 10, 'price_final' => 11.8,
            'discount' => 0, 'subtotal' => $subtotal, 'mto_valor_venta' => $subtotal, 'mto_base_igv' => $subtotal,
            'porcentaje_igv' => 18, 'igv' => $igv, 'total_impuestos' => $igv, 'tip_afe_igv' => '10',
        ], fn ($v) => $v !== null);
    }

    /** @param list<array> $lineas */
    private function payload(array $lineas): array
    {
        $subtotal = array_sum(array_column($lineas, 'subtotal'));
        $total = round($subtotal * 1.18, 2);

        return [
            'date' => '2026-01-01', 'tipo_comprobante_codigo' => 'NV', 'n_transaction' => '00000001',
            'client_id' => $this->cliente->id, 'type_client' => 1, 'cod_tipo_doc_cliente' => '1',
            'currency' => 'PEN', 'is_exportacion' => 0, 'destino' => 'nacional', 'type_payment' => 1,
            'subtotal' => $subtotal, 'igv' => round($total - $subtotal, 2), 'total' => $total,
            'mto_oper_gravadas' => $subtotal, 'total_impuestos' => round($total - $subtotal, 2),
            'valor_venta' => $subtotal, 'mto_imp_venta' => $total, 'redondeo' => 0,
            'discount' => 0, 'discount_global' => 0, 'retencion_igv' => 0,
            'state_payment' => 1, 'debt' => $total, 'paid_out' => 0,
            'sale_details' => $lineas, 'payments' => [], 'advance_applications' => [],
        ];
    }

    private function vender(array $lineas): int
    {
        $r = app(SaleController::class)->store(new Request($this->payload($lineas)));

        return json_decode($r->getContent(), true)['sale_id'];
    }

    /** @return list<array{0:string,1:?string,2:?int,3:float,4:float}> tipo, documento, id, cantidad, saldo */
    private function kardex(Product $p): array
    {
        return StockMovimiento::where('product_id', $p->id)->orderBy('id')->get()
            ->map(fn ($m) => [$m->tipo, $m->documento_tipo, $m->documento_id, $m->cantidad, $m->saldo])->all();
    }

    private function assertCuadra(Product $p, float $stock): void
    {
        $this->assertEquals($stock, $p->fresh()->stock);
        $this->assertEquals($stock, StockMovimiento::where('product_id', $p->id)->orderByDesc('id')->value('saldo'));
    }

    /** Emite la NC por el controller real, con SUNAT simulado en «aceptado». */
    private function emitirNotaConReposicion(int $ventaId, SaleDetail $linea, int $cantidad): Note
    {
        Storage::fake('public');
        Company::create([
            'razon_social' => 'Empresa T153 SAC', 'razon_social_comercial' => 'Empresa T153',
            'n_document' => '20' . random_int(100_000_000, 999_999_999),
        ]);
        $this->app->instance(GreenterService::class, $this->greenterQueAceptaLaNota());
        $nota = Note::create([
            'sale_id' => $ventaId, 'tipo_doc' => '07', 'tipo_doc_afectado' => '03', 'serie_afectada' => 'B001',
            'correlativo_afectado' => 1, 'client_id' => $this->cliente->id, 'cod_motivo' => '07',
            'des_motivo' => 'Devolución por ítem', 'tipo_afectacion' => 'parcial', 'reponer_stock' => true,
            'serie' => 'BC01', 'currency' => 'PEN', 'status' => 'pendiente', 'user_id' => $this->vendedor->id,
            'subtotal' => 10 * $cantidad, 'igv' => 1.8 * $cantidad, 'total' => 11.8 * $cantidad,
        ]);
        NoteDetail::create([
            'note_id' => $nota->id, 'sale_detail_id' => $linea->id, 'product_id' => $linea->product_id,
            'description' => 'Bien T153', 'unidad_medida' => 'NIU', 'quantity' => $cantidad, 'price_base' => 10,
            'price_final' => 11.8, 'discount' => 0, 'subtotal' => 10 * $cantidad, 'igv' => 1.8 * $cantidad,
            'tip_afe_igv' => '10', 'porcentaje_igv' => 18, 'mto_valor_venta' => 10 * $cantidad,
            'mto_base_igv' => 10 * $cantidad, 'total_impuestos' => 1.8 * $cantidad, 'percentage_isc' => 0,
            'isc' => 0, 'icbper' => 0,
        ]);

        $r = app(NotaElectronicaController::class)->enviarNotaSunat(new Request(['note_id' => $nota->id]));
        $this->assertSame('aceptado', $r->getData(true)['note']['status'] ?? null, $r->getContent());

        return $nota->fresh();
    }

    /** SUNAT simulado (mismo doble que ReservaFacturacionTest). */
    private function greenterQueAceptaLaNota(): GreenterService
    {
        return new class extends GreenterService {
            public function getSee(): See
            {
                return new class extends See {
                    public function send(DocumentInterface $document): ?BaseResult
                    {
                        return null;
                    }

                    public function getFactory(): FeFactory
                    {
                        return new class extends FeFactory {
                            public function getLastXml(): ?string
                            {
                                return '<root/>';
                            }
                        };
                    }
                };
            }

            public function getNote(array $datos_nota, $empresa, $nota): GreenterNote
            {
                return new GreenterNote();
            }

            public function procesarRespuestaSunat($resultado): array
            {
                return ['cdrZip' => 'cdr-de-prueba'];
            }
        };
    }

    public function test_ca1_venta_edicion_eliminacion_y_nc_dejan_su_fila_con_documento_y_saldo(): void
    {
        $a = $this->crear(['stock' => 10]);
        $b = $this->crear(['stock' => 5]);

        // Venta: una sola fila por producto aunque vaya en dos líneas.
        $v1 = $this->vender([$this->linea($a, 1), $this->linea($a, 2), $this->linea($b, 1)]);
        $this->assertCuadra($a, 7);
        $this->assertCuadra($b, 4);

        // Edición: solo la diferencia; el producto que no cambia no escribe.
        $idsA = SaleDetail::where('sale_id', $v1)->where('product_id', $a->id)->orderBy('id')->pluck('id');
        $idB = SaleDetail::where('sale_id', $v1)->where('product_id', $b->id)->value('id');
        app(SaleController::class)->update(new Request($this->payload([
            $this->linea($a, 1, $idsA[0]), $this->linea($a, 4, $idsA[1]), $this->linea($b, 1, $idB),
        ])), (string) $v1);
        $this->assertCuadra($a, 5);

        app(SaleController::class)->destroy((string) $v1);
        $this->assertCuadra($a, 10);
        $this->assertCuadra($b, 5);

        $this->assertSame([
            ['ajuste', null, null, 10.0, 10.0],
            ['venta', 'venta', $v1, -3.0, 7.0],
            ['venta_edicion', 'venta', $v1, -2.0, 5.0],
            ['venta_eliminada', 'venta', $v1, 5.0, 10.0],
        ], $this->kardex($a));
        $this->assertSame([
            ['ajuste', null, null, 5.0, 5.0],
            ['venta', 'venta', $v1, -1.0, 4.0],
            ['venta_eliminada', 'venta', $v1, 1.0, 5.0],
        ], $this->kardex($b));

        // NC con reposición, aceptada por SUNAT.
        $v2 = $this->vender([$this->linea($a, 4)]);
        $nota = $this->emitirNotaConReposicion($v2, SaleDetail::where('sale_id', $v2)->firstOrFail(), 3);
        $this->assertCuadra($a, 9);
        $this->assertSame(['nc_venta', 'nota', $nota->id, 3.0, 9.0], last($this->kardex($a)));
        $this->assertSame(
            $this->vendedor->id,
            StockMovimiento::where('product_id', $a->id)->orderByDesc('id')->value('user_id'),
        );

        // El historial que ve la ficha: del más nuevo al más viejo, con documento y usuario.
        $r = app(ProductController::class)->kardex(new Request(), (string) $a->id);
        $filas = json_decode($r->getContent(), true)['movimientos'];
        $this->assertSame('nc_venta', $filas[0]['tipo']);
        $this->assertStringStartsWith("Nota #{$nota->id}", $filas[0]['documento']);
        $this->assertStringContainsString("Venta #{$v2} (NV01-", $filas[1]['documento']);
        $this->assertStringContainsString($this->vendedor->name, $filas[0]['usuario']);
        $this->assertStringEndsWith('Z', $filas[0]['created_at']);
    }

    public function test_ca2_cambiar_el_stock_en_la_ficha_exige_motivo_y_deja_un_ajuste(): void
    {
        $p = $this->crear(['stock' => 10]);
        $this->assertSame([['ajuste', null, null, 10.0, 10.0]], $this->kardex($p));
        $this->assertSame('stock inicial', StockMovimiento::where('product_id', $p->id)->value('motivo'));

        try {
            $this->editar($p, ['stock' => 7, 'price_general' => 99]);
            $this->fail('Se esperaba un 422 por falta de motivo.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertEquals(10, $p->fresh()->stock);
        $this->assertEquals(10, $p->fresh()->price_general); // no se guardó nada de la ficha
        $this->assertCount(1, $this->kardex($p));

        // Guardar la ficha sin tocar el stock no pide motivo ni escribe.
        $this->editar($p, ['price_general' => 12]);
        $this->editar($p, ['stock' => '10']);
        $this->assertCount(1, $this->kardex($p));

        $this->editar($p, ['stock' => 7, 'motivo_stock' => 'merma en almacén']);
        $this->assertCuadra($p, 7);
        $ultimo = StockMovimiento::where('product_id', $p->id)->orderByDesc('id')->first();
        $this->assertSame(['ajuste', -3.0, 'merma en almacén', $this->vendedor->id],
            [$ultimo->tipo, $ultimo->cantidad, $ultimo->motivo, $ultimo->user_id]);

        $this->assertContains('permission:list_product', Route::getRoutes()->match(
            Request::create("/api/products/{$p->id}/kardex")
        )->gatherMiddleware());
    }

    public function test_ca3_un_producto_que_no_controla_stock_no_escribe_kardex(): void
    {
        $servicio = $this->crear(['tipo_bien_servicio' => 'SERVICIO', 'stock' => 0]);
        $sinStock = $this->crear(['disponiblidad' => 1, 'stock' => 3]);
        $noControla = Product::create($this->datosProducto(['controla_stock' => false, 'stock' => 0]));

        $venta = $this->vender([$this->linea($servicio, 2), $this->linea($sinStock, 2), $this->linea($noControla, 2)]);
        app(SaleController::class)->destroy((string) $venta);
        $this->editar($sinStock, ['disponiblidad' => 1, 'stock' => 8]); // sin motivo: no es un ajuste

        $this->assertEquals(8, $sinStock->fresh()->stock);
        foreach ([$servicio, $sinStock, $noControla] as $p) {
            $this->assertSame([], $this->kardex($p));
        }

        // Al empezar a controlar stock, el kárdex arranca en el stock que tiene.
        $this->editar($sinStock, ['disponiblidad' => 2]);
        $this->assertSame([['ajuste', null, null, 8.0, 8.0]], $this->kardex($sinStock));
    }

    public function test_ca5_la_migracion_deja_el_saldo_inicial_de_cada_producto_que_controla_stock(): void
    {
        $controla = Product::create($this->datosProducto(['stock' => 4.5]));
        $eliminado = Product::create($this->datosProducto(['stock' => 2]));
        $eliminado->delete();
        $servicio = Product::create($this->datosProducto(['tipo_bien_servicio' => 'SERVICIO']));
        $sinStock = Product::create($this->datosProducto(['disponiblidad' => 1]));
        $noControla = Product::create($this->datosProducto(['controla_stock' => false]));

        // Se vuelve a correr la migración real dentro de la transacción del test.
        $migracion = require database_path('migrations/tenant/core/2026_11_27_100000_create_stock_movimientos_table.php');
        $migracion->down();
        $migracion->up();

        foreach ([[$controla, 4.5], [$eliminado, 2.0]] as [$p, $stock]) {
            $this->assertSame([['ajuste', null, null, $stock, $stock]], $this->kardex($p));
            $this->assertSame('saldo inicial', StockMovimiento::where('product_id', $p->id)->value('motivo'));
        }
        foreach ([$servicio, $sinStock, $noControla] as $p) {
            $this->assertSame([], $this->kardex($p));
        }

        $esperadas = Product::withTrashed()->get()->filter(fn (Product $p) => $p->controlaStock())->count();
        $this->assertSame($esperadas, StockMovimiento::count());
    }
}
