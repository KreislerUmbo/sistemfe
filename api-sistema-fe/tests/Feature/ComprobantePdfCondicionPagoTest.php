<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Credit\Installment;
use App\Models\Sale\Sale;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Condición de pago en el PDF (24-oct-2026, todos los giros): "Contado" o
// "Crédito" con sus cuotas, igual que el XML. Se dibujan las plantillas reales
// (A4 y ticket) a HTML. También protege de plantillas rotas: al escribir esto,
// un @php(...) con paréntesis anidados dejó el ticket mostrando código.
class ComprobantePdfCondicionPagoTest extends TestCase
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

        // FK de users.role_id (el factory de Sale crea un usuario).
        DB::table('roles')->insertOrIgnore(['id' => 1, 'name' => 'rol-pdf-condicion', 'guard_name' => 'api', 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function html(Sale $venta, string $plantilla): string
    {
        $venta = Sale::with(['client', 'user', 'sale_details.product', 'sale_details.product_categorie', 'sale_payments'])->find($venta->id);

        return view('pdf.'.$plantilla, [
            'venta' => $venta,
            'empresa' => Company::first() ?? new Company(['razon_social' => 'Empresa Test', 'ruc' => '20000000001']),
            'qr' => '',
            'logo' => null,
        ])->render();
    }

    public function test_al_contado_dice_contado_en_a4_y_ticket(): void
    {
        $venta = Sale::factory()->create(['type_payment' => 1, 'condicion_pago' => 'contado']);

        foreach (['a4', 'ticket80mm'] as $plantilla) {
            $html = $this->html($venta, $plantilla);
            $this->assertMatchesRegularExpression('/CONTADO|Contado/', $html, $plantilla);
            $this->assertStringNotContainsString('@php', $html, "$plantilla muestra código sin interpretar");
            $this->assertStringNotContainsString('$venta', $html, "$plantilla muestra código sin interpretar");
        }
    }

    public function test_a_credito_muestra_las_cuotas_y_el_ultimo_vencimiento(): void
    {
        $venta = Sale::factory()->create(['type_payment' => 2, 'condicion_pago' => 'credito', 'credit_type' => 'cuotas_fijas']);
        Installment::create(['sale_id' => $venta->id, 'numero_cuota' => 1, 'monto_programado' => 200, 'fecha_vencimiento' => '2026-11-15', 'estado' => 'pendiente']);
        Installment::create(['sale_id' => $venta->id, 'numero_cuota' => 2, 'monto_programado' => 248, 'fecha_vencimiento' => '2026-12-15', 'estado' => 'pendiente']);
        // Una cuota anulada no se imprime (tampoco va en el XML).
        Installment::create(['sale_id' => $venta->id, 'numero_cuota' => 3, 'monto_programado' => 99, 'fecha_vencimiento' => '2027-01-15', 'estado' => 'anulada']);

        $a4 = $this->html($venta, 'a4');
        $this->assertStringContainsString('CRÉDITO', $a4);
        $this->assertStringContainsString('15/12/2026', $a4, 'Fecha de vencimiento = la última cuota vigente.');
        $this->assertStringContainsString('248.00', $a4);
        $this->assertStringNotContainsString('15/01/2027', $a4, 'La cuota anulada no sale.');

        $ticket = $this->html($venta, 'ticket80mm');
        $this->assertStringContainsString('Crédito', $ticket);
        $this->assertStringContainsString('Cuota 2', $ticket);
        $this->assertStringNotContainsString('Cuota 3', $ticket);
        $this->assertStringNotContainsString('@php', $ticket);
    }
}
