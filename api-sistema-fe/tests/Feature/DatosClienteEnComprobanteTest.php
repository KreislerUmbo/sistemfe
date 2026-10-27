<?php

namespace Tests\Feature;

use App\Http\Controllers\Advance\AdvanceController;
use App\Http\Controllers\Greenter\GreenterService;
use App\Http\Controllers\Sale\FacturacionElectronicaController;
use App\Http\Controllers\Sale\NotaElectronicaController;
use App\Models\Cash\Branch;
use App\Models\Cash\CashRegister;
use App\Models\Cash\CashSession;
use App\Models\Cash\PaymentMethod;
use App\Models\Client\Client;
use App\Models\Company;
use App\Models\Sale\Note;
use App\Models\Sale\NoteSerie;
use App\Models\Sale\Sale;
use App\Models\Sale\SerieComprobante;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// T-064 (obs. 18, H60): el comprobante guarda documento, razón social y
// dirección del cliente al emitirse. Antes el PDF (y el XML) los leían del
// cliente al imprimir: corregir un cliente cambiaba la reimpresión de todos sus
// comprobantes ya aceptados por SUNAT. Postgres real, transacción por test.
class DatosClienteEnComprobanteTest extends TestCase
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
            'database.connections.central.database' => 'sistemafe_test_migrations',
        ]);
        DB::purge('pgsql');
        DB::purge('central');
        DB::beginTransaction();
        DB::connection('central')->beginTransaction();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        DB::table('roles')->insertOrIgnore([
            'id' => 1, 'name' => 'rol-datos-cliente', 'guard_name' => 'api',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), (SELECT MAX(id) FROM roles))");

        PaymentMethod::firstOrCreate(
            ['code' => 'EFECTIVO'],
            ['name' => 'Efectivo', 'is_active' => true, 'sort_order' => 1, 'affects_cash_count' => true]
        );
        NoteSerie::firstOrCreate(['tipo_doc' => '07', 'tipo_doc_afectado' => '01'], ['serie' => 'FC01']);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    private function cliente(): Client
    {
        return Client::factory()->empresa()->create([
            'full_name' => 'Transportes Original SAC',
            'n_document' => '20111111111',
            'address' => 'Jr. Original 123, Tarapoto',
        ]);
    }

    private function corregirCliente(Client $cliente): void
    {
        $cliente->update([
            'full_name' => 'Transportes Corregido SAC',
            'n_document' => '20999999999',
            'address' => 'Av. Nueva 999, Lima',
        ]);
    }

    private function marcarAceptadoPorSunat(Sale $venta): void
    {
        Sale::where('id', $venta->id)->update([
            'correlativo' => $venta->id,
            'n_operacion' => 'F001-' . str_pad((string) $venta->id, 8, '0', STR_PAD_LEFT),
            'xml' => '<xml>fake</xml>',
            'cdr' => 'fake-cdr-content',
        ]);
    }

    private function html(Sale $venta, string $plantilla): string
    {
        $venta = Sale::with(['client', 'user', 'sale_details.product', 'sale_details.product_categorie', 'sale_payments'])->find($venta->id);

        return view('pdf.' . $plantilla, [
            'venta' => $venta,
            'empresa' => Company::first() ?? new Company(['razon_social' => 'Empresa Test', 'ruc' => '20000000001']),
            'qr' => '',
            'logo' => null,
        ])->render();
    }

    public function test_la_venta_copia_los_datos_del_cliente_al_crearse(): void
    {
        $cliente = $this->cliente();
        $venta = Sale::factory()->create(['client_id' => $cliente->id]);

        $this->assertSame($cliente->type_document, $venta->tipo_doc_cliente);
        $this->assertSame('20111111111', $venta->num_doc_cliente);
        $this->assertSame('Transportes Original SAC', $venta->razon_social_cliente);
        $this->assertSame('Jr. Original 123, Tarapoto', $venta->direccion_cliente);
    }

    public function test_corregir_el_cliente_no_cambia_el_pdf_ni_el_xml_de_un_comprobante_aceptado(): void
    {
        $cliente = $this->cliente();
        $venta = Sale::factory()->create(['client_id' => $cliente->id]);
        $this->marcarAceptadoPorSunat($venta);

        $this->corregirCliente($cliente);

        foreach (['a4', 'ticket80mm'] as $plantilla) {
            $html = $this->html($venta, $plantilla);
            $this->assertStringContainsString('Transportes Original SAC', $html, $plantilla);
            $this->assertStringContainsString('20111111111', $html, $plantilla);
            $this->assertStringNotContainsString('Corregido', $html, $plantilla);
        }
        $this->assertStringContainsString('Jr. Original 123, Tarapoto', $this->html($venta, 'a4'));

        $receptor = app(GreenterService::class)->getCliente($venta->fresh());
        $this->assertSame('20111111111', $receptor->getNumDoc());
        $this->assertSame('Transportes Original SAC', $receptor->getRznSocial());
    }

    public function test_comprobante_anterior_sin_copia_sigue_mostrando_el_cliente(): void
    {
        $cliente = $this->cliente();
        $venta = Sale::factory()->create(['client_id' => $cliente->id]);
        Sale::where('id', $venta->id)->update([
            'tipo_doc_cliente' => null, 'num_doc_cliente' => null,
            'razon_social_cliente' => null, 'direccion_cliente' => null,
        ]);

        $this->corregirCliente($cliente);

        $html = $this->html($venta, 'a4');
        $this->assertStringContainsString('Transportes Corregido SAC', $html);
        $this->assertStringContainsString('Av. Nueva 999, Lima', $html);
    }

    public function test_al_enviar_sin_cdr_se_toman_los_datos_corregidos(): void
    {
        $cliente = $this->cliente();
        $venta = Sale::factory()->create([
            'client_id' => $cliente->id, 'correlativo' => null, 'n_operacion' => null,
            'xml' => null, 'cdr' => null, 'type_payment' => 1, 'retencion_igv' => 0, 'is_exportacion' => 0,
        ]);
        Company::create(['razon_social' => 'Empresa de Prueba SAC', 'razon_social_comercial' => 'Empresa de Prueba', 'n_document' => '20123456789']);

        $this->corregirCliente($cliente);

        // Sin SunatConfig el envío corta con 422 más adelante: la copia ya se rehízo.
        try {
            app(FacturacionElectronicaController::class)->enviarSunat(new Request(['sale_id' => $venta->id]));
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $venta->refresh();
        $this->assertSame('Transportes Corregido SAC', $venta->razon_social_cliente);
        $this->assertSame('20999999999', $venta->num_doc_cliente);
    }

    public function test_un_comprobante_aceptado_no_rehace_la_copia_al_reenviar(): void
    {
        $cliente = $this->cliente();
        $venta = Sale::factory()->create(['client_id' => $cliente->id]);
        $this->marcarAceptadoPorSunat($venta);
        Company::create(['razon_social' => 'Empresa de Prueba SAC', 'razon_social_comercial' => 'Empresa de Prueba', 'n_document' => '20123456789']);

        $this->corregirCliente($cliente);

        $respuesta = app(FacturacionElectronicaController::class)->enviarSunat(new Request(['sale_id' => $venta->id]));

        $this->assertSame(405, $respuesta->getData(true)['code']);
        $this->assertSame('Transportes Original SAC', $venta->fresh()->razon_social_cliente);
    }

    public function test_la_nota_de_credito_lleva_los_datos_de_su_venta(): void
    {
        $branch = Branch::create(['name' => 'Sede T-064', 'is_active' => true]);
        SerieComprobante::create([
            'branch_id' => $branch->id, 'tipo_comprobante_codigo' => '01', 'moneda' => 'PEN',
            'serie' => 'F001', 'correlativo_actual' => 0, 'correlativo_inicial' => 1,
            'fecha_inicio' => now()->format('Y-m-d'), 'activo' => true,
        ]);
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $role = Role::create(['name' => 'rol-t064-' . uniqid(), 'guard_name' => 'api']);
        $user->assignRole($role);
        $user->role_id = $role->id;
        $user->save();
        $caja = CashRegister::create(['branch_id' => $branch->id, 'name' => 'Caja T-064', 'is_active' => true]);
        CashSession::create(['cash_register_id' => $caja->id, 'opened_by' => $user->id, 'opening_amount' => 0, 'opened_at' => now(), 'status' => 'open']);
        Auth::guard('api')->setUser($user->fresh());

        // Un adelanto es una venta real con su detalle: sobre él se puede emitir la NC.
        $cliente = $this->cliente();
        $adelanto = json_decode(app(AdvanceController::class)->store(new Request([
            'client_id' => $cliente->id, 'amount' => 118.00, 'currency' => 'PEN',
            'payment_method' => 'EFECTIVO', 'tip_afe_igv' => '10',
        ]))->getContent(), true);
        $venta = Sale::find($adelanto['sale_id']);
        $this->marcarAceptadoPorSunat($venta);

        $this->corregirCliente($cliente);

        $respuesta = app(NotaElectronicaController::class)->store(new Request([
            'sale_id' => $venta->id,
            'tipo_doc' => '07',
            'tipo_afectacion' => 'total',
            'cod_motivo' => '01',
            'des_motivo' => 'Anulación de la operación',
        ]));
        $this->assertSame(200, $respuesta->getStatusCode(), json_encode($respuesta->getData(true)));

        $nota = Note::find($respuesta->getData(true)['note']['id']);
        $this->assertSame('Transportes Original SAC', $nota->razon_social_cliente);
        $this->assertSame('20111111111', $nota->num_doc_cliente);
        $this->assertSame('Jr. Original 123, Tarapoto', $nota->direccion_cliente);

        $receptor = app(GreenterService::class)->getCliente($nota);
        $this->assertSame('Transportes Original SAC', $receptor->getRznSocial());
    }
}
