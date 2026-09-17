<?php

namespace Tests\Feature;

use App\Http\Controllers\Greenter\GreenterService;
use App\Http\Controllers\Sale\FacturacionElectronicaController;
use App\Models\Company;
use App\Models\Sale\Sale;
use App\Models\TipoCambio\TipoCambioSunat;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Response\BaseResult;
use Greenter\Model\Sale\Invoice;
use Greenter\See;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Fase 4 del plan de Tipo de Cambio SUNAT — snapshot de
// sales.tipo_cambio_sunat_aplicado. Mismo mock que EnviarSunatCdrFailureTest
// (getInvoice() falla antes de llegar a SUNAT) porque simular un envío
// ACEPTADO completo requiere más infraestructura (XML real, hash, etc.)
// que no aporta nada nuevo acá — lo que este test necesita confirmar es
// que el snapshot se resuelve y persiste ANTES de intentar el envío, sin
// importar si SUNAT lo acepta o no. Decisión del usuario (09-sep-2026):
// nunca bloquea el envío, aunque no haya tipo de cambio disponible.
//
// Corre contra sistemafe_test_migrations (pgsql, ventas) + 'central'
// (tipo_cambio_sunat) — dos transacciones, una por conexión.
class TipoCambioSunatAplicadoSaleTest extends TestCase
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
        DB::connection('central')->beginTransaction();

        // El sync diario real (Módulo Tipo de Cambio, no relacionado con
        // este test) puede haber dejado filas reales en la BD central
        // compartida de dev — se limpian acá DENTRO de la transacción de
        // este test (tearDown() la revierte), así el dato real vuelve
        // intacto al terminar. Sin esto, cualquier test de este archivo que
        // asuma la tabla vacía depende de que ningún sync real haya corrido
        // todavía, lo que rompe en cualquier entorno donde sí corrió.
        TipoCambioSunat::query()->delete();

        DB::table('roles')->insert([
            'id' => 1, 'name' => 'test-role', 'guard_name' => 'api',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    private function greenterServiceQueFallaAntesDeEnviar(): GreenterService
    {
        return new class extends GreenterService {
            public function getSee(): See
            {
                return new class extends See {
                    public function send(DocumentInterface $document): ?BaseResult
                    {
                        throw new \LogicException('No debería llegar a send() en este test.');
                    }
                };
            }

            public function getInvoice(array $datos_comprobante, $empresa, $venta): Invoice
            {
                throw new \RuntimeException('Fallo simulado — no relevante para este test.');
            }
        };
    }

    private function crearEmpresaYMock(): void
    {
        Company::create([
            'razon_social' => 'Empresa de Prueba SAC',
            'razon_social_comercial' => 'Empresa de Prueba',
            'n_document' => '20123456789',
        ]);
        $this->app->instance(GreenterService::class, $this->greenterServiceQueFallaAntesDeEnviar());
    }

    public function test_venta_en_pen_no_setea_tipo_cambio_sunat_aplicado(): void
    {
        $this->crearEmpresaYMock();

        $venta = Sale::factory()->create([
            'serie' => 'F001', 'correlativo' => null, 'n_operacion' => null, 'xml' => null, 'cdr' => null,
            'type_payment' => 1, 'retencion_igv' => 0, 'is_exportacion' => 0, 'currency' => 'PEN',
        ]);

        app(FacturacionElectronicaController::class)->enviarSunat(new Request(['sale_id' => $venta->id]));

        $this->assertNull($venta->fresh()->tipo_cambio_sunat_aplicado);
    }

    public function test_venta_en_usd_con_dato_disponible_setea_el_snapshot(): void
    {
        DB::connection('central')->table('tipo_cambio_sunat')->insert([
            'fecha' => now()->toDateString(), 'compra' => 3.70, 'venta' => 3.705,
            'fuente' => 'decolecta', 'consultado_en' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->crearEmpresaYMock();

        $venta = Sale::factory()->create([
            'serie' => 'F001', 'correlativo' => null, 'n_operacion' => null, 'xml' => null, 'cdr' => null,
            'type_payment' => 1, 'retencion_igv' => 0, 'is_exportacion' => 0, 'currency' => 'USD',
        ]);

        app(FacturacionElectronicaController::class)->enviarSunat(new Request(['sale_id' => $venta->id]));

        $this->assertEquals(3.705, (float) $venta->fresh()->tipo_cambio_sunat_aplicado);
    }

    public function test_venta_en_usd_sin_dato_disponible_no_bloquea_el_envio(): void
    {
        $this->assertSame(0, TipoCambioSunat::count());
        $this->crearEmpresaYMock();

        $venta = Sale::factory()->create([
            'serie' => 'F001', 'correlativo' => null, 'n_operacion' => null, 'xml' => null, 'cdr' => null,
            'type_payment' => 1, 'retencion_igv' => 0, 'is_exportacion' => 0, 'currency' => 'USD',
        ]);

        $response = app(FacturacionElectronicaController::class)->enviarSunat(new Request(['sale_id' => $venta->id]));

        // El mismo fallo simulado de siempre (getInvoice() lanza) — nada
        // relacionado con la falta de tipo de cambio bloqueó el intento.
        $this->assertSame(500, $response->getStatusCode());
        $this->assertNull($venta->fresh()->tipo_cambio_sunat_aplicado);
    }
}
