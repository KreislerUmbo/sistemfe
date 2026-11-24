<?php

declare(strict_types=1);

namespace Tests\Feature\Ventas;

use App\Http\Controllers\Sale\SaleController;
use App\Http\Controllers\Sale\SaleDetailController;
use App\Http\Controllers\Sale\SalePaymentController;
use App\Models\Cash\Branch;
use App\Models\Client\Client;
use App\Models\Product\Categorie;
use App\Models\Product\Product;
use App\Models\Sale\Sale;
use App\Models\Sale\SaleDetail;
use App\Models\Sale\SalePayment;
use App\Models\Sale\SerieComprobante;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Creditos\CreditosTestCase;
use Tests\Feature\Ventas\Concerns\CabeceraDePantalla;

/**
 * T-121 — POST/DELETE de sale_payments y sale_details responden 410 y no tocan la venta, sus pagos,
 * sus líneas ni el stock (movían dinero sin caja). Las rutas y permisos siguen: GateBucketBRoutesTest.
 */
final class T121RutasLegadasTest extends CreditosTestCase
{
    use CabeceraDePantalla;

    private Categorie $categoria;
    private Client $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $sede = Branch::create(['name' => 'Sede T121 ' . uniqid(), 'is_active' => true]);
        SerieComprobante::create([
            'branch_id' => $sede->id, 'tipo_comprobante_codigo' => 'NV', 'moneda' => 'PEN', 'serie' => 'NV01',
            'correlativo_actual' => 0, 'correlativo_inicial' => 1, 'fecha_inicio' => '2026-01-01', 'activo' => true,
        ]);
        $this->categoria = Categorie::create(['title' => 'Cat T121', 'state' => 1]);
        $this->cliente = Client::factory()->create(['n_document' => '45678913', 'type_client' => 1]);

        $usuario = User::factory()->create(['branch_id' => $sede->id]);
        $rol = Role::create(['name' => 'rol-t121-' . uniqid(), 'guard_name' => 'api']);
        $rol->givePermissionTo(Permission::firstOrCreate(['name' => 'emitir_nota_venta', 'guard_name' => 'api']));
        $usuario->assignRole($rol);
        $usuario->role_id = $rol->id;
        $usuario->save();
        Auth::guard('api')->setUser($usuario->fresh());
    }

    /** Venta de 2 × 50 + IGV, al contado, sin pagos (debt = total). */
    private function venta(): Sale
    {
        $p = Product::create([
            'title' => 'Producto T121', 'sku' => 'SKU-T121-' . uniqid(), 'categorie_id' => $this->categoria->id,
            'price_general' => 50, 'price_company' => 50, 'state' => 1, 'unidad_medida' => 'NIU', 'stock' => 500,
            'is_discount' => false, 'disponiblidad' => 1, 'include_igv' => false, 'is_icbper' => false,
            'is_ivap' => false, 'is_isc' => false, 'is_especial_nota' => false, 'tip_afe_igv_default' => '10',
        ]);
        $linea = [
            'product' => ['id' => $p->id, 'categorie_id' => $this->categoria->id, 'is_isc' => false, 'percentage_isc' => 0],
            'unidad_medida' => 'NIU', 'quantity' => 2, 'price_base' => 50, 'price_final' => 59, 'discount' => 0,
            'subtotal' => 100, 'mto_valor_venta' => 100, 'mto_base_igv' => 100, 'porcentaje_igv' => 18.0,
            'igv' => 18, 'isc' => 0, 'tipo_isc' => '01', 'icbper' => 0, 'total_impuestos' => 18, 'tip_afe_igv' => '10',
        ];
        $payload = $this->conCabeceraDeLasLineas([
            'date' => '2026-01-01', 'tipo_comprobante_codigo' => 'NV', 'n_transaction' => '00000001',
            'client_id' => $this->cliente->id, 'type_client' => 1, 'cod_tipo_doc_cliente' => '1',
            'currency' => 'PEN', 'is_exportacion' => 0, 'destino' => 'nacional', 'type_payment' => 1,
            'discount_global' => 0, 'retencion_igv' => 0,
            'porcentaje_retencion' => 0.03, 'porcentaje_percepcion' => 0.02, 'porcentaje_detraccion' => 0,
            'state_payment' => 1, 'paid_out' => 0,
            'sale_details' => [$linea], 'payments' => [], 'advance_applications' => [],
        ]);
        $payload['debt'] = $payload['total'];
        $id = json_decode(app(SaleController::class)->store(new Request($payload))->getContent(), true)['sale_id'];

        return Sale::findOrFail($id);
    }

    /** Lo que las rutas viejas cambiaban: cabecera de la venta, pagos, líneas y stock. */
    private function foto(Sale $venta): array
    {
        $venta = $venta->fresh();
        $linea = SaleDetail::where('sale_id', $venta->id)->first();

        return [
            'total' => (string) $venta->total, 'debt' => (string) $venta->debt, 'paid_out' => (string) $venta->paid_out,
            'saldo_pendiente' => (string) $venta->saldo_pendiente, 'state_payment' => (int) $venta->state_payment,
            'pagos' => SalePayment::where('sale_id', $venta->id)->pluck('id')->sort()->values()->all(),
            'lineas' => SaleDetail::where('sale_id', $venta->id)->pluck('id')->sort()->values()->all(),
            'stock' => (string) Product::find($linea->product_id)->stock,
        ];
    }

    private function assert410(JsonResponse $respuesta): void
    {
        $this->assertSame(410, $respuesta->getStatusCode());
        $this->assertStringContainsString('Ruta retirada', $respuesta->getData(true)['message']);
    }

    public function test_sale_payments_responden_410(): void
    {
        $venta = $this->venta();
        $pago = SalePayment::create([
            'sale_id' => $venta->id, 'method_payment' => 'Efectivo', 'amount' => 10, 'date_payment' => '2026-01-01',
            'moneda_pago' => 'PEN', 'monto_pagado' => 10, 'tipo_cambio' => 1,
        ]);
        $antes = $this->foto($venta);

        $this->assert410(app(SalePaymentController::class)->store(new Request([
            'sale_id' => $venta->id, 'method_payment' => 'Efectivo', 'amount' => 20, 'date_payment' => '2026-01-01',
        ])));
        $this->assert410(app(SalePaymentController::class)->destroy((string) $pago->id));

        $this->assertSame($antes, $this->foto($venta));
        $this->assertNotNull($pago->fresh());
    }

    public function test_sale_details_responden_410(): void
    {
        $venta = $this->venta();
        $linea = SaleDetail::where('sale_id', $venta->id)->firstOrFail();
        $antes = $this->foto($venta);

        $this->assert410(app(SaleDetailController::class)->store(new Request([
            'sale_id' => $venta->id, 'product' => ['id' => $linea->product_id, 'categorie_id' => $this->categoria->id],
            'quantity' => 3, 'tip_afe_igv' => '10',
        ])));
        $this->assert410(app(SaleDetailController::class)->destroy((string) $linea->id));

        $this->assertSame($antes, $this->foto($venta));
        $this->assertNotNull($linea->fresh());
    }
}
