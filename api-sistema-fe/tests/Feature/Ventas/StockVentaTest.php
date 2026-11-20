<?php

declare(strict_types=1);

namespace Tests\Feature\Ventas;

use App\Http\Controllers\Sale\SaleController;
use App\Models\Cash\Branch;
use App\Models\Client\Client;
use App\Models\Product\Categorie;
use App\Models\Product\Product;
use App\Models\Sale\Sale;
use App\Models\Sale\SaleDetail;
use App\Models\Sale\SerieComprobante;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Creditos\CreditosTestCase;

/**
 * T-113 — el stock se valida (con lock) al guardar, editar y eliminar una venta.
 * CA1-CA5; CA6 (concurrencia real) es manual contra agencia-demo.
 */
final class StockVentaTest extends CreditosTestCase
{
    private Categorie $categoria;
    private Client $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $sede = Branch::create(['name' => 'Sede T113 ' . uniqid(), 'is_active' => true]);
        SerieComprobante::create([
            'branch_id' => $sede->id, 'tipo_comprobante_codigo' => 'NV', 'moneda' => 'PEN', 'serie' => 'NV01',
            'correlativo_actual' => 0, 'correlativo_inicial' => 1, 'fecha_inicio' => '2026-01-01', 'activo' => true,
        ]);
        $this->categoria = Categorie::create(['title' => 'Cat T113', 'state' => 1]);

        $usuario = User::factory()->create(['branch_id' => $sede->id]);
        $rol = Role::create(['name' => 'rol-t113-' . uniqid(), 'guard_name' => 'api']);
        foreach (['emitir_nota_venta', 'edit_sale', 'cambiar_precio_venta'] as $p) {
            $rol->givePermissionTo(Permission::firstOrCreate(['name' => $p, 'guard_name' => 'api']));
        }
        $usuario->assignRole($rol);
        $usuario->role_id = $rol->id;
        $usuario->save();
        Auth::guard('api')->setUser($usuario->fresh());

        $this->cliente = Client::factory()->create(['n_document' => '45678912', 'type_client' => 1]);
    }

    private function producto(array $extra = []): Product
    {
        return Product::create($extra + [
            'title' => 'Bien T113', 'sku' => 'SKU-T113-' . uniqid(), 'categorie_id' => $this->categoria->id,
            'price_general' => 10, 'price_company' => 8, 'state' => 1, 'unidad_medida' => 'NIU', 'stock' => 5,
            'is_discount' => false, 'disponiblidad' => 2, 'include_igv' => false, 'is_icbper' => false,
            'is_ivap' => false, 'is_isc' => false, 'is_especial_nota' => false, 'tip_afe_igv_default' => '10',
            'tipo_bien_servicio' => 'BIEN', 'controla_stock' => true,
        ]);
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
            'discount' => 0, 'discount_global' => 0, 'retencion_igv' => 0,
            'state_payment' => 1, 'debt' => $total, 'paid_out' => 0,
            'sale_details' => $lineas, 'payments' => [], 'advance_applications' => [],
        ];
    }

    private function guardar(array $lineas): int
    {
        $r = app(SaleController::class)->store(new Request($this->payload($lineas)));

        return json_decode($r->getContent(), true)['sale_id'];
    }

    private function actualizar(int $idVenta, array $lineas): void
    {
        app(SaleController::class)->update(new Request($this->payload($lineas)), (string) $idVenta);
    }

    private function assertSolo(callable $accion, int $quedan, string $titulo): void
    {
        try {
            $accion();
            $this->fail('Se esperaba un 422 por stock.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString("Solo quedan {$quedan} unidades de «{$titulo}»", $e->getMessage());
        }
    }

    public function test_ca1_vender_mas_de_lo_que_hay_da_422_y_no_guarda_nada(): void
    {
        $p = $this->producto(['stock' => 1]);
        $ventas = Sale::count();

        $this->assertSolo(fn () => $this->guardar([$this->linea($p, 2)]), 1, 'Bien T113');

        $this->assertSame($ventas, Sale::count());
        $this->assertEquals(1, $p->fresh()->stock);
    }

    public function test_ca2_valida_la_suma_de_las_lineas_del_mismo_producto(): void
    {
        $p = $this->producto(['stock' => 5]);

        $this->assertSolo(fn () => $this->guardar([$this->linea($p, 3), $this->linea($p, 3)]), 5, 'Bien T113');
        $this->assertEquals(5, $p->fresh()->stock);

        $this->guardar([$this->linea($p, 3), $this->linea($p, 2)]);
        $this->assertEquals(0, $p->fresh()->stock);
    }

    public function test_ca3_servicio_venta_sin_stock_y_controla_stock_false_se_venden_sin_tocar_stock(): void
    {
        $servicio = $this->producto(['stock' => 0, 'tipo_bien_servicio' => 'SERVICIO']);
        $sinStock = $this->producto(['stock' => 0, 'disponiblidad' => 1]);
        $noControla = $this->producto(['stock' => 0, 'controla_stock' => false]);

        $this->guardar([$this->linea($servicio, 3), $this->linea($sinStock, 3), $this->linea($noControla, 3)]);

        foreach ([$servicio, $sinStock, $noControla] as $p) {
            $this->assertEquals(0, $p->fresh()->stock);
        }
    }

    public function test_ca4_editar_valida_solo_la_diferencia_y_bajar_devuelve(): void
    {
        $p = $this->producto(['stock' => 3]);
        $idVenta = $this->guardar([$this->linea($p, 2)]); // stock 1
        $idLinea = SaleDetail::where('sale_id', $idVenta)->value('id');

        // 2 → 4 con stock 1: la diferencia (2) no alcanza.
        $this->assertSolo(fn () => $this->actualizar($idVenta, [$this->linea($p, 4, $idLinea)]), 1, 'Bien T113');
        $this->assertEquals(2, SaleDetail::find($idLinea)->quantity);
        $this->assertEquals(1, $p->fresh()->stock);

        // 2 → 3 con stock 1: la diferencia (1) sí alcanza.
        $this->actualizar($idVenta, [$this->linea($p, 3, $idLinea)]);
        $this->assertEquals(0, $p->fresh()->stock);

        // 3 → 1: devuelve 2.
        $this->actualizar($idVenta, [$this->linea($p, 1, $idLinea)]);
        $this->assertEquals(2, $p->fresh()->stock);
    }

    public function test_ca4_quitar_una_linea_devuelve_su_stock(): void
    {
        $a = $this->producto(['stock' => 5]);
        $b = $this->producto(['stock' => 5]);
        $idVenta = $this->guardar([$this->linea($a, 2), $this->linea($b, 2)]);
        $idA = SaleDetail::where('sale_id', $idVenta)->where('product_id', $a->id)->value('id');

        $this->actualizar($idVenta, [$this->linea($a, 2, $idA)]);

        $this->assertEquals(3, $a->fresh()->stock);
        $this->assertEquals(5, $b->fresh()->stock);
    }

    public function test_ca5_eliminar_devuelve_el_stock_de_los_bienes_y_no_toca_servicios(): void
    {
        $bien = $this->producto(['stock' => 5]);
        $servicio = $this->producto(['stock' => 7, 'tipo_bien_servicio' => 'SERVICIO']);
        $idVenta = $this->guardar([$this->linea($bien, 4), $this->linea($servicio, 2)]);
        $this->assertEquals(1, $bien->fresh()->stock);

        app(SaleController::class)->destroy((string) $idVenta);

        $this->assertEquals(5, $bien->fresh()->stock);
        $this->assertEquals(7, $servicio->fresh()->stock);
    }
}
