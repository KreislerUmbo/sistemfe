<?php

namespace Tests\Unit;

use App\Services\Central\BrevoDominiosService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

// Cliente de la API de Brevo para dominios de correo de las agencias
// (plan-modulo-envio-correo-cotizacion.md §3.1). Respuestas simuladas: la forma
// exacta de `dns_records` se confirma con la cuenta real (etapa 0 del plan).
class BrevoDominiosServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.brevo.api_key' => 'clave-de-prueba', 'services.brevo.base_url' => 'https://api.brevo.test/v3']);
    }

    private function dnsRecords(bool $dkimOk = false): array
    {
        return [
            'dkim_record' => ['type' => 'TXT', 'value' => 'k=rsa;p=ABC', 'host_name' => 'mail._domainkey', 'status' => $dkimOk],
            'brevo_code' => ['type' => 'TXT', 'value' => 'brevo-code:123', 'host_name' => '@', 'status' => $dkimOk],
            'sin_valor' => ['type' => 'TXT'],
        ];
    }

    public function test_registrar_envia_la_api_key_y_devuelve_los_registros_dns(): void
    {
        Http::fake(['api.brevo.test/v3/senders/domains' => Http::response(['id' => 1, 'domain_name' => 'agencia.com', 'dns_records' => $this->dnsRecords()], 201)]);

        $resultado = app(BrevoDominiosService::class)->registrar('agencia.com');

        $this->assertCount(3, $resultado['dns_records']);
        Http::assertSent(fn ($request) => $request->hasHeader('api-key', 'clave-de-prueba')
            && $request['name'] === 'agencia.com'
            && $request->method() === 'POST');
    }

    public function test_registrar_un_dominio_que_ya_existe_lee_su_estado_en_vez_de_fallar(): void
    {
        Http::fake([
            'api.brevo.test/v3/senders/domains' => Http::response(['code' => 'duplicate_parameter', 'message' => 'Domain already exists'], 400),
            'api.brevo.test/v3/senders/domains/agencia.com' => Http::response(['domain' => 'agencia.com', 'verified' => true, 'authenticated' => false, 'dns_records' => $this->dnsRecords()], 200),
        ]);

        $resultado = app(BrevoDominiosService::class)->registrar('agencia.com');

        $this->assertCount(3, $resultado['dns_records']);
    }

    public function test_autenticar_informa_si_brevo_ya_encuentra_los_dns(): void
    {
        Http::fake([
            'api.brevo.test/v3/senders/domains/agencia.com/authenticate' => Http::response(['domain_name' => 'agencia.com', 'message' => 'Domain has been authenticated'], 200),
            'api.brevo.test/v3/senders/domains/agencia.com' => Http::response(['verified' => true, 'authenticated' => true, 'dns_records' => $this->dnsRecords(true)], 200),
        ]);

        $resultado = app(BrevoDominiosService::class)->autenticar('agencia.com');

        $this->assertTrue($resultado['autenticado']);
    }

    public function test_autenticar_con_dns_pendientes_no_lanza_error(): void
    {
        Http::fake([
            'api.brevo.test/v3/senders/domains/agencia.com/authenticate' => Http::response(['message' => 'DNS records not found'], 400),
            'api.brevo.test/v3/senders/domains/agencia.com' => Http::response(['verified' => false, 'authenticated' => false, 'dns_records' => $this->dnsRecords()], 200),
        ]);

        $resultado = app(BrevoDominiosService::class)->autenticar('agencia.com');

        $this->assertFalse($resultado['autenticado']);
        $this->assertSame('DNS records not found', $resultado['mensaje']);
    }

    public function test_sin_api_key_falla_con_un_mensaje_claro(): void
    {
        config(['services.brevo.api_key' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BREVO_API_KEY');
        app(BrevoDominiosService::class)->registrar('agencia.com');
    }

    public function test_registros_dns_se_normalizan_y_descartan_los_incompletos(): void
    {
        $registros = BrevoDominiosService::registrosDns($this->dnsRecords(true));

        $this->assertCount(2, $registros);
        $this->assertSame(['clave' => 'dkim_record', 'tipo' => 'TXT', 'nombre' => 'mail._domainkey', 'valor' => 'k=rsa;p=ABC', 'estado' => true], $registros[0]);
        $this->assertSame([], BrevoDominiosService::registrosDns(null));
    }
}
