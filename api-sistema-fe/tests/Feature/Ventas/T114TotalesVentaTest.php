<?php

declare(strict_types=1);

namespace Tests\Feature\Ventas;

use App\Http\Controllers\Sale\SaleController;
use App\Models\Advance\Advance;
use App\Models\Cash\Branch;
use App\Models\Client\Client;
use App\Models\Product\Categorie;
use App\Models\Product\DetractionCode;
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
 * T-114 — Los totales de la cabecera de una venta se validan en el backend contra la suma de sus
 * líneas (TotalesVentaValidator), con y sin el permiso `cambiar_precio_venta`.
 *
 * La cabecera "buena" la arma CabeceraDePantalla (la cuenta de sumDetails() de register.vue en
 * floats, independiente del validador): que pase es la prueba de que las dos cuentas coinciden.
 */
final class T114TotalesVentaTest extends CreditosTestCase
{
    use CabeceraDePantalla;

    private Categorie $categoria;
    private ?Client $cliente = null;

    protected function setUp(): void
    {
        parent::setUp();
        $sede = Branch::create(['name' => 'Sede T114 ' . uniqid(), 'is_active' => true]);
        SerieComprobante::create([
            'branch_id' => $sede->id, 'tipo_comprobante_codigo' => 'NV', 'moneda' => 'PEN', 'serie' => 'NV01',
            'correlativo_actual' => 0, 'correlativo_inicial' => 1, 'fecha_inicio' => '2026-01-01', 'activo' => true,
        ]);
        $this->categoria = Categorie::create(['title' => 'Cat T114', 'state' => 1]);
        $this->cliente = Client::factory()->create(['n_document' => '45678912', 'type_client' => 1]);
        $this->entrarComo(['emitir_nota_venta', 'edit_sale', 'cambiar_precio_venta'], $sede);
    }

    // detraction_codes es central: esa conexión no se revierte con el test, así que se limpia en tearDown().
    private function codigoDetraccion(): void
    {
        DetractionCode::where('codigo', '999')->delete();
        DetractionCode::query()->forceCreate([
            'codigo' => '999', 'nombre' => 'T114', 'tasa_porcentaje' => 12, 'monto_minimo' => 400, 'tipo' => 'SERVICIO', 'estado' => true,
        ]);
    }

    protected function tearDown(): void
    {
        DetractionCode::where('codigo', '999')->delete();
        parent::tearDown();
    }

    private function entrarComo(array $permisos, ?Branch $sede = null): void
    {
        $sede ??= Branch::first();
        $usuario = User::factory()->create(['branch_id' => $sede->id]);
        $rol = Role::create(['name' => 'rol-t114-' . uniqid(), 'guard_name' => 'api']);
        foreach ($permisos as $p) {
            $rol->givePermissionTo(Permission::firstOrCreate(['name' => $p, 'guard_name' => 'api']));
        }
        $usuario->assignRole($rol);
        $usuario->role_id = $rol->id;
        $usuario->save();
        Auth::guard('api')->setUser($usuario->fresh());
    }

    private function producto(array $extra = []): Product
    {
        return Product::create($extra + [
            'title' => 'Producto T114', 'sku' => 'SKU-T114-' . uniqid(), 'categorie_id' => $this->categoria->id,
            'price_general' => 50, 'price_company' => 50, 'state' => 1, 'unidad_medida' => 'NIU', 'stock' => 500,
            'is_discount' => false, 'disponiblidad' => 1, 'include_igv' => false, 'is_icbper' => false,
            'is_ivap' => false, 'is_isc' => false, 'is_especial_nota' => false, 'tip_afe_igv_default' => '10',
        ]);
    }

    /** Una línea como la deja updateItem() de register.vue. */
    private function linea(Product $p, string $afectacion, float $cantidad = 2, float $precio = 50, array $o = []): array
    {
        $valor = $cantidad * $precio;
        $isc = isset($o['isc_pct']) ? $valor * $o['isc_pct'] / 100 : 0.0;
        $tasa = match ($afectacion) { '10', '11' => 18.0, '17' => 4.0, default => 0.0 };
        $igv = ($valor + $isc) * $tasa / 100;
        $icbper = ($o['bolsas'] ?? 0) * 0.5;

        return [
            'product' => [
                'id' => $p->id, 'categorie_id' => $this->categoria->id,
                'is_isc' => isset($o['isc_pct']), 'percentage_isc' => $o['isc_pct'] ?? 0,
            ],
            'unidad_medida' => 'NIU', 'quantity' => $cantidad, 'price_base' => $precio,
            'price_final' => round(($valor + $igv + $isc + $icbper) / $cantidad, 4), 'discount' => 0,
            'subtotal' => round($valor, 4), 'mto_valor_venta' => round($valor, 6), 'mto_base_igv' => round($valor, 6),
            'porcentaje_igv' => $tasa, 'igv' => round($igv, 4), 'isc' => round($isc, 4), 'tipo_isc' => $o['tipo_isc'] ?? '01',
            'icbper' => round($icbper, 4), 'total_impuestos' => round($igv + $isc + $icbper, 4), 'tip_afe_igv' => $afectacion,
        ];
    }

    /** Venta de contado, pendiente de pago, con la cabecera que armaría la pantalla. */
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

    private function assertRechazo(callable $accion, string $fragmento = 'Los totales de la venta'): void
    {
        try {
            $accion();
            $this->fail('Se esperaba un 422 y la venta se guardó.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString($fragmento, $e->getMessage());
        }
    }

    // ── CA1: una venta armada por la pantalla pasa, en cada régimen ───

    public function test_ca1_gravada_pasa(): void
    {
        $this->assertSame(200, $this->guardar($this->venta([$this->linea($this->producto(), '10')]))['code']);
    }

    public function test_ca1_exonerada_amazonia_pasa(): void
    {
        $payload = $this->venta([$this->linea($this->producto(), '20')], ['destino' => 'amazonia']);
        $this->assertSame(200, $this->guardar($payload)['code']);
        $this->assertEquals(100.0, Sale::latest('id')->value('mto_oper_exoneradas'));
    }

    public function test_ca1_exportacion_pasa(): void
    {
        $this->assertSame(200, $this->guardar($this->venta([$this->linea($this->producto(), '40')], ['is_exportacion' => 1]))['code']);
    }

    public function test_ca1_gratuita_junto_a_una_gravada_pasa(): void
    {
        $p = $this->producto();
        $payload = $this->venta([$this->linea($p, '10'), $this->linea($p, '11', 1, 30)]);
        $this->assertEquals(30.0, $payload['mto_oper_gratuitas']);
        $this->assertSame(200, $this->guardar($payload)['code']);
    }

    public function test_ca1_ivap_pasa(): void
    {
        $this->assertSame(200, $this->guardar($this->venta([$this->linea($this->producto(), '17')]))['code']);
    }

    public function test_ca1_isc_al_valor_y_al_valor_segun_pvp_y_especifico_pasan(): void
    {
        $p = $this->producto(['is_isc' => true, 'percentage_isc' => 10]);
        foreach (['01', '03'] as $tipo) {
            $this->assertSame(200, $this->guardar($this->venta([$this->linea($p, '10', 2, 50, ['isc_pct' => 10, 'tipo_isc' => $tipo])]))['code'], $tipo);
        }
        // Específico: monto fijo por unidad; no es porcentual ni se prorratea.
        $especifico = $this->linea($p, '10', 2, 50);
        $especifico['product']['is_isc'] = true;
        $especifico['tipo_isc'] = '02';
        $especifico['isc'] = 3.0;
        $especifico['igv'] = round((100 + 3) * 0.18, 4);
        $especifico['total_impuestos'] = round($especifico['igv'] + 3, 4);
        $this->assertSame(200, $this->guardar($this->venta([$especifico]))['code']);
    }

    public function test_ca1_icbper_pasa(): void
    {
        $p = $this->producto(['is_icbper' => 2]);
        $this->assertSame(200, $this->guardar($this->venta([$this->linea($p, '10', 2, 50, ['bolsas' => 2])]))['code']);
    }

    public function test_ca1_retencion_pasa_y_rebajarla_no(): void
    {
        // base 1180 > 700 → retención 3 % = 35,40 y total 1144,60
        $payload = $this->venta([$this->linea($this->producto(), '10', 10, 100)], ['retencion_igv' => 1]);
        $this->assertEquals(35.4, $payload['monto_retencion']);
        $r = $this->guardar($payload);
        $this->assertSame(200, $r['code']);
        // T-124: la venta guarda la tasa con la que se retuvo.
        $this->assertEquals(0.03, Sale::latest('id')->first()->porcentaje_retencion);

        $payload['monto_retencion'] = 50.0;
        $payload['total'] = 1130.0;
        $this->assertRechazo(fn () => $this->guardar($payload), 'monto_retencion');
    }

    public function test_ca1_retencion_bajo_el_minimo_es_cero(): void
    {
        $payload = $this->venta([$this->linea($this->producto(), '10')], ['retencion_igv' => 1]); // base 118 < 700
        $this->assertEquals(0.0, $payload['monto_retencion']);
        $this->assertSame(200, $this->guardar($payload)['code']);
        // Sin retención no se guarda tasa (T-124).
        $this->assertEquals(0, Sale::latest('id')->first()->porcentaje_retencion);
    }

    public function test_ca1_detraccion_pasa_con_el_porcentaje_y_el_minimo_del_codigo(): void
    {
        $this->codigoDetraccion();
        $extra = ['retencion_igv' => 2, 'codigo_detraccion' => '999', 'porcentaje_detraccion' => 0.12];
        $lineas = [$this->linea($this->producto(), '10', 10, 100)];

        // base 1180 > 400 → detracción 12 % = 141,60
        $payload = $this->conCabeceraDeLasLineas($this->venta($lineas, $extra), 400.0);
        $payload['debt'] = $payload['total'];
        $this->assertEquals(141.6, $payload['monto_detraccion']);
        $this->assertSame(200, $this->guardar($payload)['code']);

        // el porcentaje enviado no es el del código
        $falso = $this->conCabeceraDeLasLineas($this->venta($lineas, ['porcentaje_detraccion' => 0.05] + $extra), 400.0);
        $this->assertRechazo(fn () => $this->guardar($falso), 'detracción');
    }

    public function test_ca1_percepcion_pasa(): void
    {
        $payload = $this->venta([$this->linea($this->producto(), '10', 10, 100)], ['retencion_igv' => 3]);
        $this->assertEquals(23.6, $payload['monto_percepcion']);
        $this->assertSame(200, $this->guardar($payload)['code']);
    }

    public function test_ca1_con_anticipo_el_total_de_la_cabecera_es_el_bruto_y_pasa(): void
    {
        $adelanto = $this->adelanto(50.0);
        $payload = $this->venta([$this->linea($this->producto(), '10')], [
            'retencion_igv' => 0,
            'advance_applications' => [['advance_id' => $adelanto->id, 'amount' => 50]],
        ]);
        $payload['debt'] = $payload['total'] - 50;
        $this->assertSame(200, $this->guardar($payload)['code']);
        // store() descuenta el adelanto del total guardado (ya existía): la cabecera que se valida es la bruta.
        $this->assertEquals(68.0, (float) Sale::latest('id')->value('total'));
    }

    private function adelanto(float $monto): Advance
    {
        $venta = Sale::factory()->create(['type' => 'advance', 'client_id' => $this->cliente->id, 'currency' => 'PEN', 'total' => $monto]);

        return Advance::create([
            'client_id' => $this->cliente->id, 'sale_id' => $venta->id, 'amount' => $monto,
            'currency' => 'PEN', 'payment_method' => 'EFECTIVO',
        ]);
    }

    // ── CA2: total o IGV rebajados → 422, con y sin el permiso de precio ─

    public function test_ca2_total_o_igv_rebajados_dan_422_con_y_sin_el_permiso(): void
    {
        $producto = $this->producto();
        $campos = [
            'total' => 100.0, 'igv' => 10.0, 'subtotal' => 80.0, 'total_impuestos' => 5.0,
            'mto_oper_gravadas' => 80.0, 'valor_venta' => 80.0, 'mto_imp_venta' => 100.0, 'discount' => 5.0,
            'isc_total' => 7.0, 'icbper_total' => 1.0, 'ivap_total' => 3.0, 'mto_oper_gratuitas' => 9.0,
        ];

        foreach (['con permiso' => ['emitir_nota_venta', 'cambiar_precio_venta'], 'sin permiso' => ['emitir_nota_venta']] as $quien => $permisos) {
            $this->entrarComo($permisos);
            foreach ($campos as $campo => $valor) {
                $payload = $this->venta([$this->linea($producto, '10')]);
                $payload[$campo] = $valor;
                $this->assertRechazo(fn () => $this->guardar($payload), $campo === 'discount' ? 'discount' : $campo);
            }
            $this->assertSame(0, Sale::count(), $quien);
        }
    }

    public function test_ca2_una_cabecera_sin_los_totales_se_rechaza(): void
    {
        $payload = $this->venta([$this->linea($this->producto(), '10')]);
        unset($payload['mto_oper_gravadas'], $payload['total_impuestos']);

        $this->assertRechazo(fn () => $this->guardar($payload));
    }

    public function test_ca2_una_afectacion_que_la_pantalla_no_genera_se_rechaza(): void
    {
        $linea = $this->linea($this->producto(), '10');
        $linea['tip_afe_igv'] = '21';
        $payload = $this->venta([$this->linea($this->producto(), '10')]);
        $payload['sale_details'] = [$linea];

        $this->assertRechazo(fn () => $this->guardar($payload), 'afectación');
    }

    // ── CA3: descuento global prorrateado ─────────────────────────────

    public function test_ca3_descuento_global_prorrateado_entre_gravada_y_exonerada_pasa(): void
    {
        $p = $this->producto();
        $payload = $this->venta([$this->linea($p, '10', 1, 100), $this->linea($p, '20', 1, 50)], ['discount_global' => 15]);
        // neto: gravada 90, exonerada 45; IGV 16,20; total 151,20
        $this->assertEquals(90.0, $payload['mto_oper_gravadas']);
        $this->assertEquals(45.0, $payload['mto_oper_exoneradas']);
        $this->assertEquals(151.2, $payload['total']);
        $this->assertSame(200, $this->guardar($payload)['code']);
    }

    public function test_ca3_con_descuento_global_sin_prorratear_el_igv_da_422(): void
    {
        $p = $this->producto();
        $payload = $this->venta([$this->linea($p, '10', 1, 100), $this->linea($p, '20', 1, 50)], ['discount_global' => 15]);
        $payload['igv'] = 18.0; // IGV sin descontar
        $this->assertRechazo(fn () => $this->guardar($payload), 'igv');
    }

    // ── CA4: update() ─────────────────────────────────────────────────

    public function test_ca4_update_con_totales_correctos_pasa_y_con_totales_rebajados_no_cambia_nada(): void
    {
        $producto = $this->producto();
        $id = $this->guardar($this->venta([$this->linea($producto, '10')]))['sale_id'];
        $idLinea = SaleDetail::where('sale_id', $id)->value('id');

        $bueno = $this->venta([['id' => $idLinea] + $this->linea($producto, '10', 3, 50)]);
        $this->assertSame(200, $this->actualizar($id, $bueno)['code']);
        $this->assertEquals(177.0, (float) Sale::find($id)->total);

        $malo = $this->venta([['id' => $idLinea] + $this->linea($producto, '10', 4, 50)]);
        $malo['total'] = 100.0;
        $malo['igv'] = 10.0;
        $this->assertRechazo(fn () => $this->actualizar($id, $malo));

        $venta = Sale::find($id);
        $this->assertEquals(177.0, (float) $venta->total);
        $this->assertEquals(3.0, (float) SaleDetail::find($idLinea)->quantity);
    }

    public function test_ca4_update_con_detraccion_usa_la_tasa_vigente_del_codigo(): void
    {
        $this->codigoDetraccion();
        $producto = $this->producto();
        $lineas = [$this->linea($producto, '10', 10, 100)];
        $extra = ['retencion_igv' => 2, 'codigo_detraccion' => '999', 'porcentaje_detraccion' => 0.12];
        $id = $this->guardar($this->conCabeceraDeLasLineas($this->venta($lineas, $extra), 400.0))['sale_id'];
        $idLinea = SaleDetail::where('sale_id', $id)->value('id');
        $lineas[0]['id'] = $idLinea;

        // La tasa del código cambia a 10 %: edit.vue la recarga del código y manda 0,10.
        DetractionCode::where('codigo', '999')->update(['tasa_porcentaje' => 10]);
        $vigente = $this->conCabeceraDeLasLineas($this->venta($lineas, ['porcentaje_detraccion' => 0.10] + $extra), 400.0);
        $vigente['debt'] = $vigente['total'];
        $this->assertSame(200, $this->actualizar($id, $vigente)['code']);
        $this->assertEquals(118.0, (float) Sale::find($id)->monto_detraccion);

        // Mandar la tasa vieja (o cualquier otra) ya no vale.
        $vieja = $this->conCabeceraDeLasLineas($this->venta($lineas, $extra), 400.0);
        $this->assertRechazo(fn () => $this->actualizar($id, $vieja), 'detracción');
    }

    public function test_ca4_la_tasa_de_retencion_inventada_se_rechaza_al_crear(): void
    {
        $payload = $this->venta([$this->linea($this->producto(), '10', 10, 100)], ['retencion_igv' => 1, 'porcentaje_retencion' => 0.005]);
        $this->assertRechazo(fn () => $this->guardar($payload), 'retención');
    }
}
