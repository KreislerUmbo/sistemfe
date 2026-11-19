<?php

namespace Tests\Feature;

use App\Http\Controllers\Advance\AdvanceController;
use App\Http\Controllers\Client\ClientAddressController;
use App\Http\Controllers\Sale\FacturacionElectronicaController;
use App\Http\Controllers\Sale\NotaElectronicaController;
use App\Http\Controllers\Sale\SaleController;
use App\Http\Requests\Client\ClientAddressRequest;
use App\Models\Cash\Branch;
use App\Models\Client\Client;
use App\Models\Client\ClientAddress;
use App\Models\Company;
use App\Models\Product\Categorie;
use App\Models\Product\Product;
use App\Models\Sale\Note;
use App\Models\Sale\NoteSerie;
use App\Models\Sale\Sale;
use App\Models\Sale\SerieComprobante;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Creditos\CreditosTestCase;

// T-065 (obs. 18, H61): otras direcciones del cliente y dirección elegida en el comprobante.
// Postgres real, transacción por test (base CreditosTestCase).
class DireccionClienteTest extends CreditosTestCase
{
    private const FISCAL = 'Jr. Fiscal 100, Tarapoto';

    private function empresa(): Client
    {
        return Client::factory()->empresa()->create([
            'full_name' => 'Transportes Selva SAC', 'n_document' => '20111111111', 'address' => self::FISCAL,
        ]);
    }

    private function sucursal(Client $cliente, string $nombre = 'Sucursal Tarapoto', array $extra = []): ClientAddress
    {
        return $cliente->direcciones()->create([
            'nombre' => $nombre, 'direccion' => "Av. {$nombre} 500",
        ] + $extra + ['activo' => true]);
    }

    private function html(Sale $venta, string $plantilla = 'a4'): string
    {
        $venta = Sale::with(['client', 'user', 'sale_details.product', 'sale_details.product_categorie', 'sale_payments'])->find($venta->id);

        return view('pdf.' . $plantilla, [
            'venta' => $venta,
            'empresa' => Company::first() ?? new Company(['razon_social' => 'Empresa Test', 'ruc' => '20000000001']),
            'qr' => '', 'logo' => null,
        ])->render();
    }

    /** Sede con series de factura, usuario con caja abierta (para anticipos, notas y ventas). */
    private function emisor(array $permisos = ['emitir_nota_venta']): User
    {
        $branch = Branch::create(['name' => 'Sede T-065 ' . uniqid(), 'is_active' => true]);
        foreach ([['01', 'F001'], ['NV', 'NV01']] as [$tipo, $serie]) {
            SerieComprobante::create([
                'branch_id' => $branch->id, 'tipo_comprobante_codigo' => $tipo, 'moneda' => 'PEN', 'serie' => $serie,
                'correlativo_actual' => 0, 'correlativo_inicial' => 1, 'fecha_inicio' => '2026-01-01', 'activo' => true,
            ]);
        }
        NoteSerie::firstOrCreate(['tipo_doc' => '07', 'tipo_doc_afectado' => '01'], ['serie' => 'FC01']);
        $usuario = $this->usuario($permisos);
        $usuario->update(['branch_id' => $branch->id]);
        $usuario = $usuario->fresh();
        $this->abrirCaja($usuario);
        Auth::guard('api')->setUser($usuario);

        return $usuario;
    }

    private function anticipo(Client $cliente, ?int $direccionId = null): array
    {
        $respuesta = app(AdvanceController::class)->store(new Request([
            'client_id' => $cliente->id, 'amount' => 118.00, 'currency' => 'PEN',
            'payment_method' => 'EFECTIVO', 'tip_afe_igv' => '10',
        ] + ($direccionId ? ['client_address_id' => $direccionId] : [])));

        return json_decode($respuesta->getContent(), true);
    }

    private function payloadVenta(Client $cliente, array $extra = []): array
    {
        $producto = Product::create([
            'title' => 'Producto T065', 'sku' => 'SKU-T065-' . uniqid(),
            'categorie_id' => Categorie::create(['title' => 'Cat T065', 'state' => 1])->id,
            'price_general' => 50, 'price_company' => 50, 'state' => 1, 'unidad_medida' => 'NIU', 'stock' => 50,
            'is_discount' => false, 'disponiblidad' => 1, 'include_igv' => false, 'is_icbper' => false,
            'is_ivap' => false, 'is_isc' => false, 'is_especial_nota' => false, 'tip_afe_igv_default' => '10',
        ]);

        return [
            'date' => '2026-01-01', 'tipo_comprobante_codigo' => 'NV', 'n_transaction' => '00000001',
            'client_id' => $cliente->id, 'type_client' => 1, 'cod_tipo_doc_cliente' => '6',
            'currency' => 'PEN', 'is_exportacion' => 0, 'destino' => 'nacional', 'type_payment' => 1,
            'subtotal' => 100.00, 'igv' => 18.00, 'total' => 118.00, 'discount' => 0, 'discount_global' => 0,
            'retencion_igv' => 0, 'state_payment' => 3, 'debt' => 0, 'paid_out' => 118,
            'sale_details' => [[
                'product' => ['id' => $producto->id, 'categorie_id' => $producto->categorie_id],
                'unidad_medida' => 'NIU', 'quantity' => 2, 'price_base' => 50, 'price_final' => 59, 'discount' => 0,
                'subtotal' => 100, 'mto_valor_venta' => 100, 'mto_base_igv' => 100, 'porcentaje_igv' => 18,
                'igv' => 18, 'total_impuestos' => 18, 'tip_afe_igv' => '10',
            ]],
            'payments' => [['method_payment' => 'EFECTIVO', 'amount' => 118.00, 'date_payment' => '2026-01-01']],
            'advance_applications' => [],
        ] + $extra;
    }

    private function emitirVenta(array $payload): Sale
    {
        app(SaleController::class)->store(new Request($payload));

        return Sale::orderByDesc('id')->first();
    }

    private function notaDe(Sale $venta, array $extra = [])
    {
        return app(NotaElectronicaController::class)->store(new Request([
            'sale_id' => $venta->id, 'tipo_doc' => '07', 'tipo_afectacion' => 'total',
            'cod_motivo' => '01', 'des_motivo' => 'Anulación de la operación',
        ] + $extra));
    }

    // CA1
    public function test_ca1_la_venta_con_direccion_elegida_la_copia_y_la_siguiente_sin_ella_usa_la_fiscal(): void
    {
        $this->emisor();
        $cliente = $this->empresa();
        $tarapoto = $this->sucursal($cliente);
        $this->sucursal($cliente, 'Sucursal Yurimaguas');

        $elegida = $this->emitirVenta($this->payloadVenta($cliente, ['client_address_id' => $tarapoto->id]));
        $this->assertSame($tarapoto->id, $elegida->client_address_id);
        $this->assertSame('Av. Sucursal Tarapoto 500', $elegida->direccion_cliente);
        $this->assertStringContainsString('Av. Sucursal Tarapoto 500', $this->html($elegida));
        $this->assertStringNotContainsString(self::FISCAL, $this->html($elegida));

        $fiscal = $this->emitirVenta($this->payloadVenta($cliente));
        $this->assertNull($fiscal->client_address_id);
        $this->assertSame(self::FISCAL, $fiscal->direccion_cliente);
    }

    // CA2
    public function test_ca2_direccion_de_otro_cliente_o_inactiva_da_422_en_venta_y_anticipo_y_nota(): void
    {
        $this->emisor();
        $cliente = $this->empresa();
        $otro = Client::factory()->empresa()->create();
        $ajena = $this->sucursal($otro, 'Ajena');
        $inactiva = $this->sucursal($cliente, 'Inactiva', ['activo' => false]);

        foreach ([$ajena, $inactiva] as $direccion) {
            $this->assertRechazada(fn () => app(SaleController::class)->store(
                new Request($this->payloadVenta($cliente, ['client_address_id' => $direccion->id]))
            ));
            $this->assertRechazada(fn () => app(AdvanceController::class)->store(new Request([
                'client_id' => $cliente->id, 'amount' => 118.00, 'currency' => 'PEN',
                'payment_method' => 'EFECTIVO', 'tip_afe_igv' => '10', 'client_address_id' => $direccion->id,
            ])));
        }
        $this->assertSame(0, Sale::count(), 'ninguna venta ni anticipo se creó');

        // update() de una venta existente
        $venta = $this->emitirVenta($this->payloadVenta($cliente));
        $this->assertRechazada(fn () => app(SaleController::class)->update(
            new Request($this->payloadVenta($cliente, ['client_address_id' => $ajena->id])), (string) $venta->id
        ));
        $this->assertNull($venta->fresh()->client_address_id);

        // nota
        $adelanto = Sale::find($this->anticipo($cliente)['sale_id']);
        Sale::where('id', $adelanto->id)->update(['correlativo' => 1, 'n_operacion' => 'F001-00000001', 'xml' => '<x/>', 'cdr' => 'cdr']);
        $this->assertRechazada(fn () => $this->notaDe($adelanto, ['client_address_id' => $ajena->id]));
        $this->assertRechazada(fn () => $this->notaDe($adelanto, ['client_address_id' => $inactiva->id]));
        $this->assertSame(0, Note::count());
    }

    private function assertRechazada(callable $accion): void
    {
        try {
            $accion();
            $this->fail('Debía rechazarse con 422.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('client_address_id', $e->errors());
        }
    }

    // CA3
    public function test_ca3_editar_y_enviar_sin_cdr_conserva_la_direccion_elegida(): void
    {
        $this->emisor();
        $cliente = $this->empresa();
        $tarapoto = $this->sucursal($cliente);
        $venta = $this->emitirVenta($this->payloadVenta($cliente, ['client_address_id' => $tarapoto->id]));

        // Editar sin mandar la dirección: se conserva; el cliente corrige su fiscal: no la pisa.
        $cliente->update(['address' => 'Av. Fiscal Nueva 999']);
        app(SaleController::class)->update(new Request($this->payloadVenta($cliente)), (string) $venta->id);
        $venta->refresh();
        $this->assertSame($tarapoto->id, $venta->client_address_id);
        $this->assertSame('Av. Sucursal Tarapoto 500', $venta->direccion_cliente);

        // La dirección se desactiva después: lo ya elegido sigue imprimiéndose.
        $tarapoto->update(['activo' => false]);
        Sale::where('id', $venta->id)->update(['correlativo' => null, 'n_operacion' => null, 'xml' => null, 'cdr' => null, 'type_payment' => 1]);
        Company::create(['razon_social' => 'Empresa de Prueba SAC', 'razon_social_comercial' => 'Empresa de Prueba', 'n_document' => '20123456789']);
        try {
            app(FacturacionElectronicaController::class)->enviarSunat(new Request(['sale_id' => $venta->id]));
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame('Av. Sucursal Tarapoto 500', $venta->fresh()->direccion_cliente);
        $this->assertSame($tarapoto->id, $venta->fresh()->client_address_id);
    }

    public function test_editar_reenviando_la_misma_direccion_ya_desactivada_se_acepta_pero_no_si_es_nueva(): void
    {
        $this->emisor();
        $cliente = $this->empresa();
        $tarapoto = $this->sucursal($cliente);
        $yurimaguas = $this->sucursal($cliente, 'Sucursal Yurimaguas', ['activo' => false]);
        $venta = $this->emitirVenta($this->payloadVenta($cliente, ['client_address_id' => $tarapoto->id]));
        $tarapoto->update(['activo' => false]);

        // La pantalla de edición (T-107) reenvía la dirección guardada: corregir otra cosa no se bloquea.
        app(SaleController::class)->update(
            new Request($this->payloadVenta($cliente, ['client_address_id' => $tarapoto->id, 'description' => 'corregida'])), (string) $venta->id
        );
        $this->assertSame($tarapoto->id, $venta->fresh()->client_address_id);
        $this->assertSame('Av. Sucursal Tarapoto 500', $venta->fresh()->direccion_cliente);

        // Elegir otra dirección inactiva sigue rechazándose.
        $this->assertRechazada(fn () => app(SaleController::class)->update(
            new Request($this->payloadVenta($cliente, ['client_address_id' => $yurimaguas->id])), (string) $venta->id
        ));
        $this->assertSame($tarapoto->id, $venta->fresh()->client_address_id);
    }

    public function test_al_editar_una_venta_cambiando_de_cliente_la_direccion_elegida_se_limpia(): void
    {
        $this->emisor();
        $cliente = $this->empresa();
        $otro = Client::factory()->empresa()->create(['address' => 'Calle Otro 1']);
        $tarapoto = $this->sucursal($cliente);
        $venta = $this->emitirVenta($this->payloadVenta($cliente, ['client_address_id' => $tarapoto->id]));

        app(SaleController::class)->update(new Request($this->payloadVenta($otro)), (string) $venta->id);

        $venta->refresh();
        $this->assertNull($venta->client_address_id);
        $this->assertSame('Calle Otro 1', $venta->direccion_cliente);
    }

    // CA4
    public function test_ca4_la_nota_hereda_la_direccion_de_la_venta_o_usa_otra_del_mismo_cliente(): void
    {
        $this->emisor();
        $cliente = $this->empresa();
        $tarapoto = $this->sucursal($cliente);
        $yurimaguas = $this->sucursal($cliente, 'Sucursal Yurimaguas');
        $adelanto = Sale::find($this->anticipo($cliente, $tarapoto->id)['sale_id']);
        Sale::where('id', $adelanto->id)->update(['correlativo' => 1, 'n_operacion' => 'F001-00000001', 'xml' => '<x/>', 'cdr' => 'cdr']);

        $hereda = $this->notaDe($adelanto);
        $this->assertSame(200, $hereda->getStatusCode(), json_encode($hereda->getData(true)));
        $nota = Note::find($hereda->getData(true)['note']['id']);
        $this->assertSame($tarapoto->id, $nota->client_address_id);
        $this->assertSame('Av. Sucursal Tarapoto 500', $nota->direccion_cliente);

        $otra = $this->notaDe($adelanto, ['client_address_id' => $yurimaguas->id]);
        $this->assertSame(200, $otra->getStatusCode(), json_encode($otra->getData(true)));
        $nota2 = Note::find($otra->getData(true)['note']['id']);
        $this->assertSame($yurimaguas->id, $nota2->client_address_id);
        $this->assertSame('Av. Sucursal Yurimaguas 500', $nota2->direccion_cliente);
    }

    // CA5 (anticipo; la factura de reserva está en Fila13Fase1cFacturacionTest)
    public function test_ca5_el_anticipo_con_direccion_elegida_la_copia(): void
    {
        $this->emisor();
        $cliente = $this->empresa();
        $tarapoto = $this->sucursal($cliente);

        $adelanto = Sale::find($this->anticipo($cliente, $tarapoto->id)['sale_id']);
        $this->assertSame($tarapoto->id, $adelanto->client_address_id);
        $this->assertSame('Av. Sucursal Tarapoto 500', $adelanto->direccion_cliente);

        $sin = Sale::find($this->anticipo($cliente)['sale_id']);
        $this->assertNull($sin->client_address_id);
        $this->assertSame(self::FISCAL, $sin->direccion_cliente);
    }

    // CA6 — se ejecuta el middleware de permisos que la ruta realmente declara.
    public function test_ca6_con_solo_register_sale_puede_listar_y_agregar_pero_no_editar_ni_desactivar(): void
    {
        $this->usuario(['register_sale']);

        $this->assertSame('ok', $this->correrMiddleware('GET', 'api/clients/{id}/direcciones'));
        $this->assertSame('ok', $this->correrMiddleware('POST', 'api/clients/{id}/direcciones'));
        $this->assertSame(403, $this->codigoDeMiddleware('PUT', 'api/client-direcciones/{id}'));

        $this->usuario(['edit_client']);
        $this->assertSame('ok', $this->correrMiddleware('PUT', 'api/client-direcciones/{id}'));

        $this->usuario(['cash.close_others_session']);
        $this->assertSame(403, $this->codigoDeMiddleware('GET', 'api/clients/{id}/direcciones'));
        $this->assertSame(403, $this->codigoDeMiddleware('POST', 'api/clients/{id}/direcciones'));
    }

    private function correrMiddleware(string $metodo, string $uri): string
    {
        $ruta = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === $uri && in_array($metodo, $r->methods()));
        $this->assertNotNull($ruta, "ruta {$metodo} {$uri}");
        $permiso = collect($ruta->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'));
        $this->assertNotNull($permiso, 'la ruta debe declarar un permiso');

        return app(PermissionMiddleware::class)
            ->handle(Request::create('/x', $metodo), fn () => response('ok'), substr($permiso, strlen('permission:')), 'api')
            ->getContent();
    }

    private function codigoDeMiddleware(string $metodo, string $uri): int
    {
        try {
            $this->correrMiddleware($metodo, $uri);
        } catch (UnauthorizedException $e) {
            return $e->getStatusCode();
        }

        return 200;
    }

    // CRUD de la API
    public function test_listar_agregar_editar_y_desactivar(): void
    {
        $cliente = $this->empresa();
        $ctrl = app(ClientAddressController::class);
        $crear = fn (array $d) => $ctrl->store($this->peticion($d), $cliente->id);

        $this->assertSame(201, $crear(['nombre' => 'Sucursal Tarapoto', 'direccion' => 'Jr. Lima 1', 'distrito' => 'Tarapoto', 'ubigeo_distrito' => '220901'])->getStatusCode());
        $crear(['nombre' => 'Sucursal Yurimaguas', 'direccion' => 'Jr. Loreto 2']);
        $tarapoto = ClientAddress::where('nombre', 'Sucursal Tarapoto')->first();
        $this->assertTrue($tarapoto->activo);
        $this->assertSame('220901', $tarapoto->ubigeo_distrito);

        $ctrl->update($this->peticion(['nombre' => 'Tarapoto centro', 'direccion' => 'Jr. Lima 1', 'activo' => false]), $tarapoto->id);
        $this->assertFalse($tarapoto->fresh()->activo);
        $this->assertSame('Tarapoto centro', $tarapoto->fresh()->nombre);

        $activas = $ctrl->index(new Request(), $cliente->id)->getData(true)['direcciones'];
        $this->assertSame(['Sucursal Yurimaguas'], array_column($activas, 'nombre'));
        $todas = $ctrl->index(new Request(['todas' => 1]), $cliente->id)->getData(true)['direcciones'];
        $this->assertCount(2, $todas);
        $this->assertSame('Tarapoto centro', end($todas)['nombre'], 'las inactivas van al final');

        $ctrl->update($this->peticion(['nombre' => 'Tarapoto centro', 'direccion' => 'Jr. Lima 1', 'activo' => true]), $tarapoto->id);
        $this->assertTrue($tarapoto->fresh()->activo);
    }

    public function test_la_validacion_exige_nombre_y_direccion_y_limita_el_largo(): void
    {
        $reglas = (new ClientAddressRequest())->rules();
        $v = fn (array $d) => validator($d, $reglas);

        $this->assertTrue($v(['direccion' => 'x'])->fails());
        $this->assertTrue($v(['nombre' => 'x'])->fails());
        $this->assertTrue($v(['nombre' => str_repeat('n', 101), 'direccion' => 'x'])->fails());
        $this->assertTrue($v(['nombre' => 'n', 'direccion' => str_repeat('d', 256)])->fails());
        $this->assertTrue($v(['nombre' => 'n', 'direccion' => 'd', 'referencia' => str_repeat('r', 201)])->fails());
        $this->assertFalse($v(['nombre' => 'n', 'direccion' => str_repeat('d', 255)])->fails());
        // region/provincia/distrito: columnas de 50 → 422, no un 500 de Postgres.
        $this->assertTrue($v(['nombre' => 'n', 'direccion' => 'd', 'provincia' => str_repeat('p', 51)])->fails());
        $this->assertFalse($v(['nombre' => 'n', 'direccion' => 'd', 'provincia' => str_repeat('p', 50)])->fails());
    }

    private function peticion(array $datos): ClientAddressRequest
    {
        $peticion = ClientAddressRequest::create('/x', 'POST', $datos);
        $peticion->setContainer(app())->setRedirector(app('redirect'));
        $peticion->validateResolved();

        return $peticion;
    }

    // CA7
    public function test_ca7_la_migracion_con_filas_falla_con_mensaje_y_no_altera_nada(): void
    {
        $migracion = require base_path('database/migrations/tenant/core/2026_11_18_100000_adapt_client_addresses_and_add_client_address_id.php');
        $migracion->down();
        $cliente = $this->empresa();
        DB::table('client_addresses')->insert([
            'client_id' => $cliente->id, 'nombre' => 'Vieja', 'direccion' => 'Dirección vieja',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $migracion->up();
            $this->fail('Debía fallar con filas en client_addresses.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('1 fila', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('client_addresses', 'departamento'));
        $this->assertTrue(Schema::hasColumn('client_addresses', 'principal'));
        $this->assertFalse(Schema::hasColumn('client_addresses', 'activo'));
        $this->assertFalse(Schema::hasColumn('sales', 'client_address_id'));
        $this->assertSame(1, DB::table('client_addresses')->count());
    }

    public function test_la_direccion_no_se_borra_si_un_comprobante_la_usa(): void
    {
        $this->emisor();
        $cliente = $this->empresa();
        $tarapoto = $this->sucursal($cliente);
        $this->emitirVenta($this->payloadVenta($cliente, ['client_address_id' => $tarapoto->id]));

        $this->expectException(\Illuminate\Database\QueryException::class);
        $tarapoto->delete();
    }
}
