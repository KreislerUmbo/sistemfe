<?php

declare(strict_types=1);

namespace Tests\Feature\Ventas;

use App\Http\Controllers\Sale\SaleController;
use App\Models\Cash\Branch;
use App\Models\Client\Client;
use App\Models\CommercialQuote\CommercialQuote;
use App\Models\CommercialQuote\CommercialQuoteItem;
use App\Models\Product\Categorie;
use App\Models\Product\Product;
use App\Models\Sale\Sale;
use App\Models\Sale\SaleDetail;
use App\Models\Sale\SerieComprobante;
use App\Models\TipoCambio\TipoCambioSunat;
use App\Models\User;
use App\Services\Roles\RolPropietario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Creditos\CreditosTestCase;
use Tests\Feature\Ventas\Concerns\CabeceraDePantalla;

/**
 * T-109 — permiso cambiar_precio_venta y validaciones nuevas de SaleController::store()/update():
 * precio de catálogo (o de la cotización de origen) sin el permiso, boleta de más de S/ 700 sin
 * documento y crédito a un cliente sin documento.
 */
final class T109PrecioYValidacionesVentaTest extends CreditosTestCase
{
    use CabeceraDePantalla; // T-114: los totales de la cabecera se validan contra las líneas


    private Branch $sede;
    private Categorie $categoria;
    private ?Client $clienteCompartido = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sede = Branch::create(['name' => 'Sede T109 ' . uniqid(), 'is_active' => true]);
        foreach ([['NV', 'PEN', 'NV01'], ['03', 'PEN', 'B001'], ['03', 'USD', 'B002']] as [$tipo, $moneda, $serie]) {
            SerieComprobante::create([
                'branch_id' => $this->sede->id, 'tipo_comprobante_codigo' => $tipo, 'moneda' => $moneda, 'serie' => $serie,
                'correlativo_actual' => 0, 'correlativo_inicial' => 1, 'fecha_inicio' => '2026-01-01', 'activo' => true,
            ]);
        }
        $this->categoria = Categorie::create(['title' => 'Cat T109', 'state' => 1]);
    }

    /** @param list<string> $permisos */
    private function entrarComo(array $permisos): User
    {
        $usuario = User::factory()->create(['branch_id' => $this->sede->id]);
        $rol = Role::create(['name' => 'rol-t109-' . uniqid(), 'guard_name' => 'api']);
        foreach ($permisos as $p) {
            $rol->givePermissionTo(Permission::firstOrCreate(['name' => $p, 'guard_name' => 'api']));
        }
        $usuario->assignRole($rol);
        $usuario->role_id = $rol->id;
        $usuario->save();
        Auth::guard('api')->setUser($usuario->fresh());

        return $usuario->fresh();
    }

    private function producto(array $extra = []): Product
    {
        return Product::create($extra + [
            'title' => 'Producto T109', 'sku' => 'SKU-T109-' . uniqid(), 'categorie_id' => $this->categoria->id,
            'price_general' => 10, 'price_company' => 8, 'state' => 1, 'unidad_medida' => 'NIU', 'stock' => 500,
            'is_discount' => false, 'disponiblidad' => 1, 'include_igv' => false, 'is_icbper' => false,
            'is_ivap' => false, 'is_isc' => false, 'is_especial_nota' => false, 'tip_afe_igv_default' => '10',
        ]);
    }

    private function clienteT109(array $extra = []): Client
    {
        return Client::factory()->create($extra + ['n_document' => '45678912', 'type_client' => 1]);
    }

    /** Venta de contado, sin pagos (queda pendiente), de una sola línea. */
    private function payload(Product $producto, Client $cliente, float|string $precioBase, array $extra = [], int|float $cantidad = 1): array
    {
        $subtotal = round((float) $precioBase * $cantidad, 2);
        $total = round($subtotal * 1.18, 2);

        return $extra + [
            'date' => '2026-01-01',
            'tipo_comprobante_codigo' => 'NV',
            'n_transaction' => '00000001',
            'client_id' => $cliente->id,
            'type_client' => $cliente->type_client,
            'cod_tipo_doc_cliente' => '1',
            'currency' => 'PEN',
            'is_exportacion' => 0,
            'destino' => 'nacional',
            'type_payment' => 1,
            'subtotal' => $subtotal, 'igv' => round($total - $subtotal, 2), 'total' => $total,
            'discount' => 0, 'discount_global' => 0, 'retencion_igv' => 0,
            'state_payment' => 1, 'debt' => $total, 'paid_out' => 0,
            'sale_details' => [[
                'product' => ['id' => $producto->id, 'categorie_id' => $this->categoria->id],
                'unidad_medida' => 'NIU', 'quantity' => $cantidad,
                'price_base' => $precioBase, 'price_final' => round((float) $precioBase * 1.18, 2),
                'discount' => 0, 'subtotal' => $subtotal, 'mto_valor_venta' => $subtotal, 'mto_base_igv' => $subtotal,
                'porcentaje_igv' => 18, 'igv' => round($total - $subtotal, 2), 'total_impuestos' => round($total - $subtotal, 2),
                'tip_afe_igv' => '10',
            ]],
            'payments' => [],
            'advance_applications' => [],
        ];
    }

    private function guardar(array $payload): array
    {
        $respuesta = app(SaleController::class)->store(new Request($this->conCabeceraDeLasLineas($payload)));

        return json_decode($respuesta->getContent(), true);
    }

    private function assertRechazo(array $payload, string $fragmento): void
    {
        try {
            $this->guardar($payload);
            $this->fail('Se esperaba un 422 y la venta se guardó.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString($fragmento, $e->getMessage());
        }
    }

    // ── CA1 ───────────────────────────────────────────────────────────

    public function test_sin_el_permiso_un_precio_distinto_del_catalogo_da_422(): void
    {
        $this->entrarComo(['emitir_nota_venta']);
        $producto = $this->producto();

        $this->assertRechazo($this->payload($producto, $this->clienteT109(), 7), 'No tienes permiso para cambiar el precio');
        $this->assertSame(0, Sale::count());
    }

    public function test_sin_el_permiso_el_precio_de_catalogo_general_pasa(): void
    {
        $this->entrarComo(['emitir_nota_venta']);

        $this->assertSame(200, $this->guardar($this->payload($this->producto(), $this->clienteT109(), 10))['code']);
    }

    public function test_sin_el_permiso_a_una_empresa_le_corresponde_el_precio_empresa(): void
    {
        $this->entrarComo(['emitir_nota_venta']);
        $producto = $this->producto();
        $empresa = $this->clienteT109(['type_client' => 2]);

        $this->assertSame(200, $this->guardar($this->payload($producto, $empresa, 8))['code']);
        $this->assertRechazo($this->payload($producto, $empresa, 10), 'No tienes permiso');
    }

    public function test_sin_el_permiso_con_igv_incluido_se_compara_el_precio_sin_igv_con_tolerancia(): void
    {
        $this->entrarComo(['emitir_nota_venta']);
        // 118 con IGV incluido → 100 sin IGV (la tasa sale de tax_configs, 18 si no hay).
        $producto = $this->producto(['include_igv' => true, 'price_general' => 118, 'price_company' => 118]);
        $cliente = $this->clienteT109();

        // El front manda la división sin redondear; un céntimo de diferencia todavía vale…
        $this->assertSame(200, $this->guardar($this->payload($producto, $cliente, 100.004))['code']);
        // …pero el precio con IGV (118) no es el precio base.
        $this->assertRechazo($this->payload($producto, $cliente, 118), 'No tienes permiso');
    }

    public function test_sin_el_permiso_un_producto_con_catalogo_en_cero_se_vende_a_precio_libre(): void
    {
        $this->entrarComo(['emitir_nota_venta']);
        $generico = $this->producto(['price_general' => 0, 'price_company' => 0]);

        $this->assertSame(200, $this->guardar($this->payload($generico, $this->clienteT109(), 100))['code']);
    }

    // ── CA2 ───────────────────────────────────────────────────────────

    public function test_con_el_permiso_cualquier_precio_pasa(): void
    {
        $this->entrarComo(['emitir_nota_venta', 'cambiar_precio_venta']);

        $this->assertSame(200, $this->guardar($this->payload($this->producto(), $this->clienteT109(), 3.5))['code']);
    }

    // ── CA3 ───────────────────────────────────────────────────────────

    public function test_boleta_de_mas_de_700_sin_documento_da_422_y_de_700_pasa(): void
    {
        $this->entrarComo(['emitir_boleta', 'cambiar_precio_venta']);
        $producto = $this->producto();
        $varios = $this->clienteT109(['n_document' => '00000000']);

        // total = subtotal × 1,18: 593,22 → 700,00 ; 593,23 → 700,01
        $this->assertRechazo($this->payload($producto, $varios, 593.23, ['tipo_comprobante_codigo' => '03']), 'más de S/ 700');
        $this->assertSame(200, $this->guardar($this->payload($producto, $varios, 593.22, ['tipo_comprobante_codigo' => '03']))['code']);
    }

    public function test_boleta_de_mas_de_700_con_dni_pasa(): void
    {
        $this->entrarComo(['emitir_boleta', 'cambiar_precio_venta']);

        $this->assertSame(200, $this->guardar($this->payload($this->producto(), $this->clienteT109(), 593.23, ['tipo_comprobante_codigo' => '03']))['code']);
    }

    public function test_boleta_en_dolares_se_convierte_con_el_ultimo_tipo_de_cambio(): void
    {
        $this->entrarComo(['emitir_boleta', 'cambiar_precio_venta']);
        $producto = $this->producto();
        $varios = $this->clienteT109(['n_document' => '00000000']);
        $usd = ['tipo_comprobante_codigo' => '03', 'currency' => 'USD'];

        // Sin ningún T.C. cargado no se bloquea.
        TipoCambioSunat::query()->delete();
        $this->assertSame(200, $this->guardar($this->payload($producto, $varios, 200, $usd))['code']);

        // Con T.C. 3,75: total 236 USD = S/ 885 → 422; total 118 USD = S/ 442,50 → pasa.
        TipoCambioSunat::create(['fecha' => '2026-01-01', 'compra' => 3.70, 'venta' => 3.75, 'fuente' => 'test', 'consultado_en' => now()]);
        $this->assertRechazo($this->payload($producto, $varios, 200, $usd), 'más de S/ 700');
        $this->assertSame(200, $this->guardar($this->payload($producto, $varios, 100, $usd))['code']);
    }

    // ── CA4 ───────────────────────────────────────────────────────────

    public function test_credito_a_un_cliente_sin_documento_da_422(): void
    {
        $this->entrarComo(['emitir_nota_venta', 'cambiar_precio_venta']);
        $total = 118.0;

        $payload = $this->payload($this->producto(), $this->clienteT109(['n_document' => '00000000']), 100, [
            'type_payment' => 2, 'credit_type' => 'cuotas_fijas',
            'cronograma' => [['numero_cuota' => 1, 'monto_programado' => $total, 'fecha_vencimiento' => '2026-02-01']],
        ]);

        $this->assertRechazo($payload, 'cliente identificado');
    }

    // ── CA5 ───────────────────────────────────────────────────────────

    public function test_el_propietario_recibe_el_permiso_y_los_demas_roles_no(): void
    {
        Permission::firstOrCreate(['name' => 'cambiar_precio_venta', 'guard_name' => 'api']);
        $vendedor = Role::create(['name' => 'Vendedor-t109', 'guard_name' => 'api']);
        $vendedor->givePermissionTo(Permission::firstOrCreate(['name' => 'register_sale', 'guard_name' => 'api']));

        RolPropietario::sincronizar();

        $this->assertTrue(Role::findByName(RolPropietario::NOMBRE, 'api')->hasPermissionTo('cambiar_precio_venta'));
        $this->assertFalse($vendedor->fresh()->hasPermissionTo('cambiar_precio_venta'));
    }

    public function test_la_migracion_da_el_permiso_a_los_administradores_que_existen_y_no_a_los_demas(): void
    {
        $admin = Role::create(['name' => 'Administrador de agencia', 'guard_name' => 'api']);
        $empresario = Role::create(['name' => 'Admin Empresario', 'guard_name' => 'api']);
        $vendedor = Role::create(['name' => 'Vendedor de agencia', 'guard_name' => 'api']);
        $cajero = Role::create(['name' => 'Cajero', 'guard_name' => 'api']);

        (require database_path('migrations/tenant/core/2026_11_19_200000_add_cambiar_precio_venta_permission.php'))->up();

        $this->assertTrue($admin->fresh()->hasPermissionTo('cambiar_precio_venta'));
        $this->assertTrue($empresario->fresh()->hasPermissionTo('cambiar_precio_venta'));
        $this->assertFalse($vendedor->fresh()->hasPermissionTo('cambiar_precio_venta'));
        $this->assertFalse($cajero->fresh()->hasPermissionTo('cambiar_precio_venta'));
        // No crea los roles que no existen en el tenant.
        $this->assertNull(Role::where('name', 'Admin-General')->first());
    }

    // ── CA6 ───────────────────────────────────────────────────────────

    public function test_editar_una_venta_sin_tocar_precios_pasa_sin_el_permiso(): void
    {
        $this->entrarComo(['emitir_nota_venta', 'edit_sale', 'cambiar_precio_venta']);
        $producto = $this->producto();
        $cliente = $this->clienteT109();
        $idVenta = $this->guardar($this->payload($producto, $cliente, 7))['sale_id']; // precio de regateo, ya guardado

        // Ahora el catálogo cambió y quien edita no tiene el permiso: la línea intacta no se rechaza.
        $producto->update(['price_general' => 12]);
        $this->entrarComo(['emitir_nota_venta', 'edit_sale']);
        $idLinea = SaleDetail::where('sale_id', $idVenta)->value('id');

        $payload = $this->payload($producto, $cliente, 7, [], 3);
        $payload['sale_details'][0]['id'] = $idLinea;
        $this->assertSame(200, $this->actualizar($idVenta, $payload)['code']);

        // Tocar el precio de esa línea sí exige el permiso.
        $payload = $this->payload($producto, $cliente, 6, [], 3);
        $payload['sale_details'][0]['id'] = $idLinea;
        try {
            $this->actualizar($idVenta, $payload);
            $this->fail('Se esperaba 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    private function actualizar(int $idVenta, array $payload): array
    {
        $respuesta = app(SaleController::class)->update(new Request($this->conCabeceraDeLasLineas($payload)), (string) $idVenta);

        return json_decode($respuesta->getContent(), true);
    }

    // ── Coherencia de importes (punto 3 de la revisión) ───────────────

    /** Venta de una línea de 2 × S/ 50 con los importes que se indican (los que calcularía updateItem()). */
    private function ventaConImportes(Product $producto, array $linea): array
    {
        $payload = $this->payload($producto, $this->clienteCompartido ??= $this->clienteT109(), 50, [], 2);
        $payload['sale_details'][0] = array_merge($payload['sale_details'][0], $linea);

        return $payload;
    }

    public function test_cada_afectacion_con_sus_importes_correctos_pasa(): void
    {
        $this->entrarComo(['emitir_nota_venta']);
        $producto = $this->producto(['price_general' => 50, 'price_company' => 50]);
        $sinIgv = ['igv' => 0, 'total_impuestos' => 0, 'porcentaje_igv' => 0, 'price_final' => 50];

        foreach ([
            'gratuita 11' => ['tip_afe_igv' => '11', 'igv' => 18, 'total_impuestos' => 18, 'porcentaje_igv' => 18, 'price_final' => 59],
            'exonerada 20' => ['tip_afe_igv' => '20'] + $sinIgv,
            'inafecta 30' => ['tip_afe_igv' => '30'] + $sinIgv,
            'exportación 40' => ['tip_afe_igv' => '40'] + $sinIgv,
            'IVAP 17' => ['tip_afe_igv' => '17', 'igv' => 4, 'total_impuestos' => 4, 'porcentaje_igv' => 4, 'price_final' => 52],
        ] as $caso => $linea) {
            $this->assertSame(200, $this->guardar($this->ventaConImportes($producto, $linea))['code'], $caso);
        }
    }

    public function test_isc_al_valor_e_icbper_con_sus_importes_correctos_pasan(): void
    {
        $this->entrarComo(['emitir_nota_venta']);

        // ISC 10 % al valor: isc 10, IGV (100 + 10) × 18 % = 19,80, price_final (100 + 29,80) / 2.
        $conIsc = $this->producto(['price_general' => 50, 'is_isc' => true, 'percentage_isc' => 10, 'tipo_isc' => '01']);
        $linea = ['isc' => 10, 'igv' => 19.8, 'total_impuestos' => 29.8, 'price_final' => 64.9, 'percentage_isc' => 10, 'tipo_isc' => '01'];
        $this->assertSame(200, $this->guardar($this->ventaConImportes($conIsc, $linea))['code']);

        // Sin el ISC en la línea, el importe no coincide.
        $this->assertRechazo($this->ventaConImportes($conIsc, ['igv' => 18, 'total_impuestos' => 18, 'price_final' => 59]), 'No tienes permiso');

        // ICBPER 2 bolsas × S/ 0,50 = 1,00; impuestos 19; price_final 119 / 2.
        $bolsa = $this->producto(['price_general' => 50, 'is_icbper' => 2]);
        $linea = ['icbper' => 1, 'per_icbper' => 0.5, 'total_impuestos' => 19, 'price_final' => 59.5];
        $this->assertSame(200, $this->guardar($this->ventaConImportes($bolsa, $linea))['code']);
    }

    public function test_con_el_subtotal_o_el_igv_rebajados_da_422_y_con_el_permiso_pasa(): void
    {
        $this->entrarComo(['emitir_nota_venta']);
        $producto = $this->producto(['price_general' => 50]);

        $this->assertRechazo($this->ventaConImportes($producto, ['subtotal' => 40, 'mto_base_igv' => 40]), 'No tienes permiso');
        $this->assertRechazo($this->ventaConImportes($producto, ['igv' => 5, 'total_impuestos' => 5]), 'el IGV');
        $this->assertRechazo($this->ventaConImportes($producto, ['price_final' => 20]), 'el precio final');
        $this->assertSame(0, Sale::count());

        $this->entrarComo(['emitir_nota_venta', 'cambiar_precio_venta']);
        $this->assertSame(200, $this->guardar($this->ventaConImportes($producto, ['subtotal' => 40, 'mto_base_igv' => 40]))['code']);
    }

    // ── Descuento global (revisión 2, punto 1) ────────────────────────

    public function test_sin_el_permiso_el_descuento_global_da_422(): void
    {
        $this->entrarComo(['emitir_nota_venta']);
        $producto = $this->producto();
        $cliente = $this->clienteT109();

        $this->assertRechazo($this->payload($producto, $cliente, 10, ['discount_global' => 5]), 'descuento global');
        $this->assertSame(0, Sale::count());

        // En cero (o sin mandarlo) la venta pasa.
        $this->assertSame(200, $this->guardar($this->payload($producto, $cliente, 10, ['discount_global' => 0]))['code']);
    }

    public function test_con_el_permiso_el_descuento_global_pasa(): void
    {
        $this->entrarComo(['emitir_nota_venta', 'cambiar_precio_venta']);

        $this->assertSame(200, $this->guardar($this->payload($this->producto(), $this->clienteT109(), 10, ['discount_global' => 5]))['code']);
    }

    public function test_al_editar_sin_el_permiso_un_descuento_global_que_no_cambio_pasa_y_uno_nuevo_no(): void
    {
        $this->entrarComo(['emitir_nota_venta', 'edit_sale', 'cambiar_precio_venta']);
        $producto = $this->producto();
        $cliente = $this->clienteT109();
        $idVenta = $this->guardar($this->payload($producto, $cliente, 10, ['discount_global' => 5]))['sale_id'];
        $idLinea = SaleDetail::where('sale_id', $idVenta)->value('id');

        $this->entrarComo(['emitir_nota_venta', 'edit_sale']);
        $igual = $this->payload($producto, $cliente, 10, ['discount_global' => 5]);
        $igual['sale_details'][0]['id'] = $idLinea;
        $this->assertSame(200, $this->actualizar($idVenta, $igual)['code']);

        $mayor = $this->payload($producto, $cliente, 10, ['discount_global' => 8]);
        $mayor['sale_details'][0]['id'] = $idLinea;
        try {
            $this->actualizar($idVenta, $mayor);
            $this->fail('Se esperaba 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('descuento global', $e->getMessage());
        }
    }

    // ── Descuento de línea (revisión 2, punto 2) ──────────────────────

    public function test_un_producto_que_no_admite_descuento_no_puede_llevarlo(): void
    {
        $this->entrarComo(['emitir_nota_venta']);
        // max_discount 10 pero is_discount ≠ 2: la pantalla ni muestra el botón.
        $sinDescuento = $this->producto(['price_general' => 50, 'max_discount' => 10, 'is_discount' => false]);
        $diezPorCiento = ['discount' => 10, 'subtotal' => 90, 'mto_base_igv' => 90, 'igv' => 16.2, 'total_impuestos' => 16.2, 'price_final' => 53.1];

        $this->assertRechazo($this->ventaConImportes($sinDescuento, $diezPorCiento), 'descuento');
    }

    public function test_el_descuento_no_puede_pasar_del_maximo_del_producto(): void
    {
        $this->entrarComo(['emitir_nota_venta']);
        $producto = $this->producto(['price_general' => 50, 'max_discount' => 10, 'is_discount' => 2]);

        // 10 % de 100 = 10: base 90, IGV 16,20, price_final 53,10.
        $diezPorCiento = ['discount' => 10, 'subtotal' => 90, 'mto_base_igv' => 90, 'igv' => 16.2, 'total_impuestos' => 16.2, 'price_final' => 53.1];
        $this->assertSame(200, $this->guardar($this->ventaConImportes($producto, $diezPorCiento))['code']);

        // 50 % de 100 = 50: coherente en importes, pero pasa del máximo.
        $mitad = ['discount' => 50, 'subtotal' => 50, 'mto_base_igv' => 50, 'igv' => 9, 'total_impuestos' => 9, 'price_final' => 29.5];
        $this->assertRechazo($this->ventaConImportes($producto, $mitad), 'descuento');
    }

    public function test_al_editar_solo_se_revisan_las_lineas_que_cambiaron(): void
    {
        $this->entrarComo(['emitir_nota_venta', 'edit_sale', 'cambiar_precio_venta']);
        $producto = $this->producto(['price_general' => 50]);
        $cliente = $this->clienteT109();
        // Línea vieja con importes que hoy no cuadrarían (se guardó con otra cuenta).
        $payload = $this->payload($producto, $cliente, 50, [], 2);
        $payload['sale_details'][0]['igv'] = 15;
        $payload['sale_details'][0]['total_impuestos'] = 15;
        $idVenta = $this->guardar($payload)['sale_id'];
        $idLinea = SaleDetail::where('sale_id', $idVenta)->value('id');

        $this->entrarComo(['emitir_nota_venta', 'edit_sale']);
        $payload['sale_details'][0]['id'] = $idLinea;
        $this->assertSame(200, $this->actualizar($idVenta, $payload)['code']);
    }

    // ── CA7 ───────────────────────────────────────────────────────────

    private function cotizacion(Client $cliente, Product $producto, float $precio, string $estado = 'enviada'): CommercialQuote
    {
        $cotizacion = CommercialQuote::create([
            'code' => 'T109-' . substr(uniqid(), -8), 'client_id' => $cliente->id, 'user_id' => Auth::guard('api')->id(),
            'currency' => 'PEN', 'status' => $estado, 'subtotal' => $precio, 'discount_global' => 0, 'total' => $precio,
        ]);
        CommercialQuoteItem::create([
            'commercial_quote_id' => $cotizacion->id, 'product_id' => $producto->id, 'description' => 'Línea',
            'unidad_medida' => 'NIU', 'quantity' => 1, 'unit_price' => $precio, 'discount_percent' => 0, 'subtotal' => $precio,
        ]);

        return $cotizacion;
    }

    public function test_venta_desde_cotizacion_acepta_el_precio_cotizado_y_rechaza_otro(): void
    {
        $this->entrarComo(['emitir_nota_venta']);
        $producto = $this->producto();
        $cliente = $this->clienteT109();
        $cotizacion = $this->cotizacion($cliente, $producto, 6.5);

        $this->assertSame(200, $this->guardar($this->payload($producto, $cliente, 6.5, ['commercial_quote_id' => $cotizacion->id]))['code']);
        $this->assertRechazo($this->payload($producto, $cliente, 5, ['commercial_quote_id' => $cotizacion->id]), 'No tienes permiso');
    }

    public function test_cotizacion_de_otro_cliente_o_no_convertible_se_ignora(): void
    {
        $this->entrarComo(['emitir_nota_venta']);
        $producto = $this->producto();
        $cliente = $this->clienteT109();

        $deOtro = $this->cotizacion($this->clienteT109(['n_document' => '11112222']), $producto, 6.5);
        $this->assertRechazo($this->payload($producto, $cliente, 6.5, ['commercial_quote_id' => $deOtro->id]), 'No tienes permiso');

        $anulada = $this->cotizacion($cliente, $producto, 6.5, 'anulada');
        $this->assertRechazo($this->payload($producto, $cliente, 6.5, ['commercial_quote_id' => $anulada->id]), 'No tienes permiso');

        // Sin cotización, el precio de catálogo sigue valiendo.
        $this->assertSame(200, $this->guardar($this->payload($producto, $cliente, 10, ['commercial_quote_id' => $deOtro->id]))['code']);
    }
}
