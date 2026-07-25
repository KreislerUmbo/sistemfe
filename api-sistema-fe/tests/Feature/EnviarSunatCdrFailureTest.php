<?php

namespace Tests\Feature;

use App\Http\Controllers\Greenter\GreenterService;
use App\Http\Controllers\Sale\FacturacionElectronicaController;
use App\Models\Company;
use App\Models\Sale\Sale;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Response\BaseResult;
use Greenter\Model\Sale\Invoice;
use Greenter\See;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Cierra el gap de trazabilidad encontrado en la conversación: antes,
// procesarRespuestaSunat() vivía FUERA del try/catch de enviarSunat()
// (líneas 216-237 de FacturacionElectronicaController) — un fallo ahí
// (storage al guardar el CDR, forma de respuesta inesperada) quemaba el
// correlativo ya reservado sin dejar ningún sunat_error_message, a
// diferencia de todos los demás fallos posteriores a reservarCorrelativo(),
// que sí quedan rastreados. Este test fuerza exactamente ese fallo y
// confirma el nuevo try/catch.
//
// Corre contra sistemafe_test_migrations (Postgres real, 76 migraciones).
// Mismo estilo que ReservarCorrelativoTest: transacción por test, revertida
// en tearDown(), cero persistencia real. Nunca toca sv_facturacion ni
// ningún tenant real.
class EnviarSunatCdrFailureTest extends TestCase
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

        // Mismo fixture que ReservarCorrelativoTest/GreenterServiceFormaPagoTest:
        // users.role_id tiene default(1) a nivel de Postgres, esta base recién
        // migrada no trae seeds.
        DB::table('roles')->insert([
            'id' => 1,
            'name' => 'test-role',
            'guard_name' => 'api',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    // getSee()/getInvoice()/procesarRespuestaSunat() completamente
    // controlados: cero red real, cero certificado real, cero dependencia
    // de configuración SUNAT — solo lo mínimo para llegar exactamente al
    // punto que este test quiere ejercitar.
    private function greenterServiceQueFallaAlProcesar(): GreenterService
    {
        return new class extends GreenterService {
            public function getSee(): See
            {
                return new class extends See {
                    public function send(DocumentInterface $document): ?BaseResult
                    {
                        // SUNAT "ya respondió" — el contenido no importa,
                        // procesarRespuestaSunat() abajo nunca llega a leerlo.
                        return null;
                    }
                };
            }

            public function getInvoice(array $datos_comprobante, $empresa, $venta): Invoice
            {
                return new Invoice();
            }

            public function procesarRespuestaSunat($resultado): array
            {
                throw new \RuntimeException('CDR malformado de prueba (storage o parseo falló).');
            }
        };
    }

    public function test_fallo_en_procesar_respuesta_sunat_deja_sunat_error_message_con_prefijo_cdr(): void
    {
        $empresa = Company::create([
            'razon_social' => 'Empresa de Prueba SAC',
            'razon_social_comercial' => 'Empresa de Prueba',
            'n_document' => '20123456789',
        ]);

        $venta = Sale::factory()->create([
            'serie' => 'F001',
            'correlativo' => null,
            'n_operacion' => null,
            'xml' => null,
            'cdr' => null,
            'type_payment' => 1, // contado — evita el guard de crédito
            'retencion_igv' => 0,
            'is_exportacion' => 0,
        ]);

        $this->app->instance(GreenterService::class, $this->greenterServiceQueFallaAlProcesar());

        $controller = app(FacturacionElectronicaController::class);
        $response = $controller->enviarSunat(new Request(['sale_id' => $venta->id]));

        $data = $response->getData(true);
        $this->assertSame(
            'CDR recibido pero no procesado: CDR malformado de prueba (storage o parseo falló).',
            $data['response']['error']['message']
        );

        $fresca = $venta->fresh();

        // El correlativo se reservó y quedó persistido — mismo criterio ya
        // probado en ReservarCorrelativoTest, el hueco es esperado.
        $this->assertNotNull($fresca->correlativo);
        $this->assertNull($fresca->n_operacion);
        $this->assertNull($fresca->xml);
        $this->assertNull($fresca->cdr);

        // Lo que antes de este fix NO quedaba: el motivo, con el prefijo
        // que distingue "CDR recibido pero no procesado" de un rechazo
        // normal de Greenter/SUNAT (try/catch original, sin prefijo).
        $this->assertSame(
            'CDR recibido pero no procesado: CDR malformado de prueba (storage o parseo falló).',
            $fresca->sunat_error_message
        );
        $this->assertNotNull($fresca->sunat_sent_at);
        $this->assertNull($fresca->sunat_error_code);
    }

    // Confirma que el try/catch original (getInvoice/send) sigue
    // funcionando exactamente igual — sin el prefijo nuevo, que es
    // exclusivo del catch agregado para procesarRespuestaSunat().
    //
    // Actualizado en Fase E, Paso 1 (plan-panel-superadmin.md): antes este
    // test simulaba el fallo haciendo que getSee() lanzara — pero getSee()
    // ya no vive dentro de este try/catch (se movió ANTES de
    // reservarCorrelativo(), ver FacturacionElectronicaController::
    // enviarSunat()), así que una falla ahí ahora se propaga como
    // excepción real, sin pasar por este catch en absoluto (ver
    // EnviarSunatValidacionPreCorrelativoTest, que cubre exactamente ese
    // caso). Este test se adapta para seguir ejercitando el try/catch que
    // sí sigue existiendo: ahora falla dentro de getInvoice() en cambio
    // de getSee(), preservando el propósito original del test (distinguir
    // este catch del catch de procesarRespuestaSunat()).
    public function test_fallo_antes_de_procesar_respuesta_sunat_no_lleva_el_prefijo_cdr(): void
    {
        $empresa = Company::create([
            'razon_social' => 'Empresa de Prueba SAC',
            'razon_social_comercial' => 'Empresa de Prueba',
            'n_document' => '20123456789',
        ]);

        $venta = Sale::factory()->create([
            'serie' => 'F001',
            'correlativo' => null,
            'n_operacion' => null,
            'xml' => null,
            'cdr' => null,
            'type_payment' => 1,
            'retencion_igv' => 0,
            'is_exportacion' => 0,
        ]);

        $greenterService = new class extends GreenterService {
            // getSee() ahora corre ANTES del try/catch — tiene que
            // devolver algo utilizable (nunca se llega a $see->send() en
            // este test, getInvoice() falla antes).
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
                throw new \RuntimeException('Fallo simulado dentro del try/catch original (ej. dato de venta inválido).');
            }
        };

        $this->app->instance(GreenterService::class, $greenterService);

        $controller = app(FacturacionElectronicaController::class);
        $response = $controller->enviarSunat(new Request(['sale_id' => $venta->id]));

        $data = $response->getData(true);
        $this->assertSame(
            'Fallo simulado dentro del try/catch original (ej. dato de venta inválido).',
            $data['response']['error']['message']
        );

        // Fase E, Paso 1: RuntimeException no es HttpException — el catch
        // ahora devuelve 500 explícito (antes: 200 siempre, sin importar
        // el tipo de fallo).
        $this->assertSame(500, $response->getStatusCode());

        $fresca = $venta->fresh();
        $this->assertSame(
            'Fallo simulado dentro del try/catch original (ej. dato de venta inválido).',
            $fresca->sunat_error_message
        );
        $this->assertStringNotContainsString('CDR recibido pero no procesado', $fresca->sunat_error_message);

        // getSee() corrió (sin lanzar) ANTES de reservarCorrelativo() —
        // el correlativo sí se reservó, mismo criterio que el resto de
        // fallos post-getSee() (ver ReservarCorrelativoTest).
        $this->assertNotNull($fresca->correlativo);
    }
}
