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
use Tests\Feature\Ventas\Concerns\CabeceraDePantalla;

/**
 * T-120 — A: update() no toca líneas de otra venta. B: el descuento global no puede ser negativo
 * ni mayor que el subtotal. (D, rutas legadas, se agrega en este mismo archivo.)
 */
final class T120BlindajeVentasTest extends CreditosTestCase
{
    use CabeceraDePantalla;

    private Categorie $categoria;
    private Client $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $sede = Branch::create(['name' => 'Sede T120 ' . uniqid(), 'is_active' => true]);
        SerieComprobante::create([
            'branch_id' => $sede->id, 'tipo_comprobante_codigo' => 'NV', 'moneda' => 'PEN', 'serie' => 'NV01',
            'correlativo_actual' => 0, 'correlativo_inicial' => 1, 'fecha_inicio' => '2026-01-01', 'activo' => true,
        ]);
        $this->categoria = Categorie::create(['title' => 'Cat T120', 'state' => 1]);
        $this->cliente = Client::factory()->create(['n_document' => '45678912', 'type_client' => 1]);

        $usuario = User::factory()->create(['branch_id' => $sede->id]);
        $rol = Role::create(['name' => 'rol-t120-' . uniqid(), 'guard_name' => 'api']);
        foreach (['emitir_nota_venta', 'edit_sale', 'cambiar_precio_venta'] as $p) {
            $rol->givePermissionTo(Permission::firstOrCreate(['name' => $p, 'guard_name' => 'api']));
        }
        $usuario->assignRole($rol);
        $usuario->role_id = $rol->id;
        $usuario->save();
        Auth::guard('api')->setUser($usuario->fresh());
    }

    private function producto(): Product
    {
        return Product::create([
            'title' => 'Producto T120', 'sku' => 'SKU-T120-' . uniqid(), 'categorie_id' => $this->categoria->id,
            'price_general' => 50, 'price_company' => 50, 'state' => 1, 'unidad_medida' => 'NIU', 'stock' => 500,
            'is_discount' => false, 'disponiblidad' => 1, 'include_igv' => false, 'is_icbper' => false,
            'is_ivap' => false, 'is_isc' => false, 'is_especial_nota' => false, 'tip_afe_igv_default' => '10',
        ]);
    }

    private function linea(Product $p, float $cantidad = 2, float $precio = 50): array
    {
        $valor = $cantidad * $precio;
        $igv = $valor * 0.18;

        return [
            'product' => ['id' => $p->id, 'categorie_id' => $this->categoria->id, 'is_isc' => false, 'percentage_isc' => 0],
            'unidad_medida' => 'NIU', 'quantity' => $cantidad, 'price_base' => $precio,
            'price_final' => round(($valor + $igv) / $cantidad, 4), 'discount' => 0,
            'subtotal' => round($valor, 4), 'mto_valor_venta' => round($valor, 6), 'mto_base_igv' => round($valor, 6),
            'porcentaje_igv' => 18.0, 'igv' => round($igv, 4), 'isc' => 0, 'tipo_isc' => '01',
            'icbper' => 0, 'total_impuestos' => round($igv, 4), 'tip_afe_igv' => '10',
        ];
    }

    private function venta(array $lineas, array $extra = []): array
    {
        $payload = $extra + [
            'date' => '2026-01-01', 'tipo_comprobante_codigo' => 'NV', 'n_transaction' => '00000001',
            'client_id' => $this->cliente->id, 'type_client' => 1, 'cod_tipo_doc_cliente' => '1',
            'currency' => 'PEN', 'is_exportacion' => 0, 'destino' => 'nacional', 'type_payment' => 1,
            'discount_global' => 0, 'retencion_igv' => 0,
            'porcentaje_retencion' => 0.03, 'porcentaje_percepcion' => 0.02, 'porcentaje_detraccion' => 0,
            'state_payment' => 1, 'paid_out' => 0,
            'sale_details' => $lineas, 'payments' => [], 'advance_applications' => [],
        ];
        $payload = $this->conCabeceraDeLasLineas($payload);
        $payload['debt'] = $payload['total'];

        return $payload;
    }

    private function guardar(array $payload): array
    {
        return json_decode(app(SaleController::class)->store(new Request($payload))->getContent(), true);
    }

    private function actualizar(int $id, array $payload): array
    {
        return json_decode(app(SaleController::class)->update(new Request($payload), (string) $id)->getContent(), true);
    }

    private function assertRechazo(callable $accion, string $fragmento): void
    {
        try {
            $accion();
            $this->fail('Se esperaba un 422 y la operación se aceptó.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString($fragmento, $e->getMessage());
        }
    }

    // ── A: línea de otra venta ────────────────────────────────────────

    public function test_update_rechaza_una_linea_de_otra_venta(): void
    {
        $p = $this->producto();
        $idA = $this->guardar($this->venta([$this->linea($p)]))['sale_id'];
        $idB = $this->guardar($this->venta([$this->linea($p)], ['n_transaction' => '00000002']))['sale_id'];
        $lineaB = SaleDetail::where('sale_id', $idB)->first();
        $cantidadB = (float) $lineaB->quantity;
        $totalA = (float) Sale::find($idA)->total;

        $payload = $this->venta([['id' => $lineaB->id] + $this->linea($p, 5)]);
        $this->assertRechazo(fn () => $this->actualizar($idA, $payload), 'no pertenece a esta venta');

        $this->assertEquals($cantidadB, (float) $lineaB->fresh()->quantity);
        $this->assertEquals($totalA, (float) Sale::find($idA)->total);
    }

    public function test_update_con_sus_propias_lineas_sigue_funcionando(): void
    {
        $p = $this->producto();
        $id = $this->guardar($this->venta([$this->linea($p)]))['sale_id'];
        $idLinea = SaleDetail::where('sale_id', $id)->value('id');

        $this->assertSame(200, $this->actualizar($id, $this->venta([['id' => $idLinea] + $this->linea($p, 3)]))['code']);
        $this->assertEquals(3.0, (float) SaleDetail::find($idLinea)->quantity);
    }

    // ── B: descuento global ───────────────────────────────────────────

    public function test_descuento_global_mayor_al_subtotal_da_422_en_store_y_update(): void
    {
        $p = $this->producto();
        $this->assertRechazo(
            fn () => $this->guardar($this->venta([$this->linea($p)], ['discount_global' => 100.01])),
            'discount_global'
        );

        $id = $this->guardar($this->venta([$this->linea($p)]))['sale_id'];
        $idLinea = SaleDetail::where('sale_id', $id)->value('id');
        $this->assertRechazo(
            fn () => $this->actualizar($id, $this->venta([['id' => $idLinea] + $this->linea($p)], ['discount_global' => 150])),
            'discount_global'
        );
    }

    public function test_descuento_global_igual_al_subtotal_pasa(): void
    {
        $this->assertSame(200, $this->guardar($this->venta([$this->linea($this->producto())], ['discount_global' => 100]))['code']);
    }

    public function test_descuento_global_negativo_da_422(): void
    {
        $this->assertRechazo(
            fn () => $this->guardar($this->venta([$this->linea($this->producto())], ['discount_global' => -10])),
            'discount_global'
        );
    }
}
