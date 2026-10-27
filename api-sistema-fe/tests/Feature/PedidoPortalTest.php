<?php

namespace Tests\Feature;

use App\Http\Controllers\OrderController;
use App\Http\Requests\Portal\PedidoPortalRequest;
use App\Models\Client\Client;
use App\Models\Order;
use App\Models\Product\Categorie;
use App\Models\Product\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// T-068: el pedido del checkout del portal (ruta pública, también invitados)
// guardaba precios, subtotal, descuento y total tal como los mandaba el
// navegador. Ahora se calculan con el producto. Postgres real, transacción.
class PedidoPortalTest extends TestCase
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

    private function producto(float $precio, int $state = 1): Product
    {
        $categoria = Categorie::create(['title' => 'Categoría Portal ' . uniqid(), 'state' => 1]);

        return Product::create([
            'title' => 'Producto Portal ' . uniqid(),
            'sku' => 'SKU-POR-' . uniqid(),
            'categorie_id' => $categoria->id,
            'price_general' => $precio,
            'price_company' => $precio,
            'state' => $state,
            'unidad_medida' => 'NIU',
            'stock' => 20,
            'is_discount' => false,
            'disponiblidad' => 1,
            'include_igv' => false,
            'is_icbper' => false,
            'is_ivap' => false,
            'is_isc' => false,
            'is_especial_nota' => false,
            'tip_afe_igv_default' => '10',
        ]);
    }

    private function pedido(array $datos)
    {
        $request = PedidoPortalRequest::create('/', 'POST', $datos + [
            'nombres' => 'Ana',
            'apellidos' => 'Rivera',
            'email' => 'ana.' . uniqid() . '@correo.test',
            'telefono' => '999888777',
            'entrega_tipo' => 'tienda',
            'comprobante_tipo' => 'boleta',
            'metodo_pago' => 'yape',
        ]);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();

        return app(OrderController::class)->store($request);
    }

    public function test_el_precio_lo_pone_el_servidor_no_el_navegador(): void
    {
        $producto = $this->producto(50);

        $respuesta = $this->pedido([
            'items' => [['id' => $producto->id, 'cantidad' => 2, 'precio' => 1]],
            'subtotal' => 2, 'descuento' => 0, 'total' => 2,
        ]);

        $this->assertSame(201, $respuesta->getStatusCode(), json_encode($respuesta->getData(true)));
        $pedido = Order::with('items')->find($respuesta->getData(true)['order_number']);
        $this->assertSame(100.0, (float) $pedido->subtotal);
        $this->assertSame(100.0, (float) $pedido->total);
        $this->assertSame(50.0, (float) $pedido->items->first()->precio_unitario);
        $this->assertSame(100.0, (float) $pedido->items->first()->subtotal);
    }

    public function test_un_descuento_enviado_por_el_navegador_no_se_aplica(): void
    {
        $producto = $this->producto(40);

        $respuesta = $this->pedido([
            'items' => [['id' => $producto->id, 'cantidad' => 1]],
            'cupon' => 'TECH20', 'descuento' => 8, 'total' => 32,
        ]);

        $pedido = Order::find($respuesta->getData(true)['order_number']);
        $this->assertSame(0.0, (float) $pedido->descuento);
        $this->assertSame(40.0, (float) $pedido->total);
        $this->assertSame('TECH20', $pedido->cupon, 'el código queda como referencia');
    }

    public function test_producto_inactivo_inexistente_o_cantidad_cero_se_rechazan(): void
    {
        $inactivo = $this->producto(10, 0);
        $pedidosAntes = Order::count();

        foreach ([
            [['id' => $inactivo->id, 'cantidad' => 1]],
            [['id' => 999999999, 'cantidad' => 1]],
            [['id' => $this->producto(10)->id, 'cantidad' => 0]],
        ] as $items) {
            try {
                $this->pedido(['items' => $items]);
                $this->fail('Se esperaba un error de validación: ' . json_encode($items));
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }

        $this->assertSame($pedidosAntes, Order::count(), 'ningún pedido rechazado se guarda');
    }

    public function test_invitado_sin_cuenta_se_registra_como_cliente(): void
    {
        $producto = $this->producto(15);
        $correo = 'invitado.' . uniqid() . '@correo.test';

        $respuesta = $this->pedido([
            'email' => $correo,
            'items' => [['id' => $producto->id, 'cantidad' => 1]],
        ]);

        $this->assertSame(201, $respuesta->getStatusCode(), json_encode($respuesta->getData(true)));
        $cliente = Client::where('email', $correo)->first();
        $this->assertNotNull($cliente);
        $this->assertSame('SND', $cliente->type_document);
        $this->assertSame($cliente->id, Order::find($respuesta->getData(true)['order_number'])->client_id);
    }

    public function test_invitado_con_dni_de_un_cliente_existente_usa_ese_cliente(): void
    {
        $existente = Client::factory()->create(['type_document' => 'DNI', 'n_document' => '45678912', 'email' => 'otro@correo.test']);
        $producto = $this->producto(15);

        $respuesta = $this->pedido([
            'dni' => '45678912',
            'items' => [['id' => $producto->id, 'cantidad' => 1]],
        ]);

        $this->assertSame(201, $respuesta->getStatusCode(), json_encode($respuesta->getData(true)));
        $this->assertSame($existente->id, Order::find($respuesta->getData(true)['order_number'])->client_id);
    }
}
