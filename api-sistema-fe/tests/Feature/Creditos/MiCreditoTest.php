<?php

declare(strict_types=1);

namespace Tests\Feature\Creditos;

use App\Http\Controllers\Creditos\MiCreditoController;
use App\Models\Client\Client;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoEnlaceCliente;
use App\Models\User;
use App\Services\Creditos\CobroService;
use App\Services\Creditos\Dto\SolicitudCobro;
use App\Services\Creditos\MiCredito\EnlaceClienteService;
use App\Services\Creditos\MiCredito\SinDocumentoException;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fase 5b — "Mi crédito" (05b-mi-credito-cliente.md). Dataset de CreditosTestCase: 5,000 al 20 %
 * en 10 cuotas de 600 cada 30 días desde el 01/01/2026 (cuota 1 vence el 31/01).
 */
final class MiCreditoTest extends CreditosTestCase
{
    private User $admin;
    private Client $cliente;
    private Credito $credito;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->usuario();
        $this->abrirCaja($this->admin);
        $this->cliente = $this->cliente();
        $this->cliente->update(['n_document' => '45678912']);
        $this->credito = $this->activo($this->admin, $this->cliente);
    }

    private function servicio(): EnlaceClienteService
    {
        return app(EnlaceClienteService::class);
    }

    /** El token en claro se recupera del cifrado (es lo que va en el QR). */
    private function token(CreditoEnlaceCliente $enlace): string
    {
        return $enlace->fresh()->token;
    }

    private function verificar(string $token, string $ultimos4): JsonResponse
    {
        return app(MiCreditoController::class)->verificar(new Request(['ultimos4' => $ultimos4]), $token);
    }

    private function ver(string $token, ?string $credencial): JsonResponse
    {
        $request = new Request();
        if ($credencial !== null) {
            $request->headers->set(MiCreditoController::CABECERA, $credencial);
        }

        return app(MiCreditoController::class)->show($request, $token);
    }

    public function test_el_cliente_ve_su_cuota_cuanto_le_falta_y_la_proxima_fecha(): void
    {
        $this->hoy('2026-01-20');
        $token = $this->token($this->servicio()->paraCliente($this->cliente, $this->admin));

        $sinCredencial = $this->ver($token, null);
        $this->assertSame(401, $sinCredencial->getStatusCode());

        $credencial = $this->verificar($token, '8912')->getData(true)['credencial'];
        $datos = $this->ver($token, $credencial)->getData(true);

        $c = $datos['creditos'][0];
        $this->assertSame('al_dia', $c['estado']);
        $this->assertSame(['numero_cuota' => 1, 'fecha' => '2026-01-31', 'monto' => '600.00'], $c['proxima']);
        $this->assertSame(10, $c['cuotas_total']);
        $this->assertSame(10, $c['cuotas_restantes']);
        $this->assertSame('6000.00', $c['saldo_por_pagar']);
        $this->assertNull($c['vencido']);
        $this->assertSame('Cliente Préstamo', $datos['cliente']['nombre']);
        $this->assertSame(1, CreditoEnlaceCliente::first()->accesos);   // solo cuenta la consulta que pasó
    }

    public function test_atrasado_muestra_lo_vencido_con_su_mora_y_los_pagos_validos(): void
    {
        $this->hoy('2026-02-05');   // cuota 1 vencida hace 5 días
        $token = $this->token($this->servicio()->paraCliente($this->cliente));
        $credencial = $this->verificar($token, '8912')->getData(true)['credencial'];

        $c = $this->ver($token, $credencial)->getData(true)['creditos'][0];
        $this->assertSame('atrasado', $c['estado']);
        $this->assertSame(1, $c['vencido']['cuotas']);
        $this->assertSame('600.00', $c['vencido']['monto']);
        $this->assertSame('100.00', $c['vencido']['mora']);
        $this->assertSame('700.00', $c['vencido']['total']);
        $this->assertSame(2, $c['proxima']['numero_cuota']);   // la próxima es la que todavía no vence

        app(CobroService::class)->cobrar($this->credito, new SolicitudCobro(70_000, DestinoExcedente::Devolver, $this->efectivo->id, 'mi-credito-1'), $this->admin);
        $datos = $this->ver($token, $credencial)->getData(true);
        $this->assertSame('al_dia', $datos['creditos'][0]['estado']);
        $this->assertSame(1, $datos['creditos'][0]['cuotas_pagadas']);
        $this->assertSame(9, $datos['creditos'][0]['cuotas_restantes']);
        $this->assertCount(1, $datos['pagos']);
        $this->assertSame('700.00', $datos['pagos'][0]['monto']);
    }

    public function test_cinco_fallos_bloquean_el_link_quince_minutos(): void
    {
        $this->hoy('2026-01-20');
        $token = $this->token($this->servicio()->paraCliente($this->cliente));

        foreach ([4, 3, 2, 1] as $restantes) {
            $r = $this->verificar($token, '0000');
            $this->assertSame(422, $r->getStatusCode());
            $this->assertSame($restantes, $r->getData(true)['intentos_restantes']);
        }
        $this->assertSame(423, $this->verificar($token, '0000')->getStatusCode());
        // Bloqueado: ni el documento correcto entra hasta que pase el tiempo.
        $this->assertSame(423, $this->verificar($token, '8912')->getStatusCode());

        $this->hoy('2026-01-20', '10:16:00');
        $this->assertSame(200, $this->verificar($token, '8912')->getStatusCode());
    }

    public function test_regenerar_invalida_el_link_y_los_celulares_recordados(): void
    {
        $this->hoy('2026-01-20');
        $enlace = $this->servicio()->paraCliente($this->cliente);
        $viejo = $this->token($enlace);
        $credencial = $this->verificar($viejo, '8912')->getData(true)['credencial'];

        $nuevo = $this->token($this->servicio()->regenerar($this->cliente, $this->admin));

        $this->assertNotSame($viejo, $nuevo);
        try {
            $this->ver($viejo, $credencial);
            $this->fail('El link viejo debía dar 404.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertSame(401, $this->ver($nuevo, $credencial)->getStatusCode());   // versión vieja
        $this->assertSame(200, $this->ver($nuevo, $this->verificar($nuevo, '8912')->getData(true)['credencial'])->getStatusCode());
    }

    public function test_la_credencial_vence_a_los_90_dias_y_no_sirve_para_otro_cliente(): void
    {
        $this->hoy('2026-01-20');
        $token = $this->token($this->servicio()->paraCliente($this->cliente));
        $credencial = $this->verificar($token, '8912')->getData(true)['credencial'];

        $otro = $this->cliente();
        $tokenOtro = $this->token($this->servicio()->paraCliente($otro));
        $this->assertSame(401, $this->ver($tokenOtro, $credencial)->getStatusCode());

        $this->hoy('2026-04-19');
        $this->assertSame(200, $this->ver($token, $credencial)->getStatusCode());
        $this->hoy('2026-04-21');
        $this->assertSame(401, $this->ver($token, $credencial)->getStatusCode());

        // Una credencial alterada no pasa la firma.
        [$datos, $firma] = explode('.', $credencial);
        $this->assertSame(401, $this->ver($token, $datos . '.' . strrev($firma))->getStatusCode());
    }

    public function test_documentos_con_letras_y_cliente_sin_documento(): void
    {
        $this->cliente->update(['n_document' => '20-6012345678 9k']);   // espacios, guiones y minúscula
        $this->assertSame('789K', EnlaceClienteService::ultimos4($this->cliente->fresh()));

        $sinDocumento = $this->cliente();
        $sinDocumento->update(['n_document' => '']);
        $this->expectException(SinDocumentoException::class);
        $this->servicio()->paraCliente($sinDocumento);
    }

    public function test_el_recibo_lleva_el_qr_y_crea_el_link_la_primera_vez(): void
    {
        $this->hoy('2026-01-31');
        $pago = app(CobroService::class)->cobrar($this->credito, new SolicitudCobro(60_000, DestinoExcedente::Devolver, $this->efectivo->id, 'mc-recibo'), $this->admin);
        $documentos = app(\App\Services\Creditos\Documentos\DocumentoCreditoService::class);

        foreach (['ticket80mm', 'a4'] as $formato) {
            $pdf = $documentos->recibo($pago, $formato, false, $this->admin);
            $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
        }
        // Un solo link aunque se imprima varias veces, y el QR apunta a él.
        $enlace = CreditoEnlaceCliente::sole();
        $qr = app(\App\Services\Creditos\MiCredito\QrMiCredito::class)->paraCliente($this->cliente);
        $this->assertSame($this->servicio()->urlDe($enlace), $qr['url']);
        $this->assertStringStartsWith('data:image/png;base64,', $qr['qr']);
        $this->assertStringEndsWith('/c/' . $enlace->token, $qr['texto']);
        $this->assertStringNotContainsString('://', $qr['texto']);

        $html = view('pdf.creditos._mi_credito_qr', ['miCredito' => $qr, 'formato' => 'ticket'])->render();
        $this->assertStringContainsString('Consulta tu crédito', $html);
        $this->assertStringContainsString(e($qr['texto']), $html);
    }

    public function test_sin_documento_el_recibo_sale_igual_sin_qr(): void
    {
        $this->cliente->update(['n_document' => '']);
        $this->hoy('2026-01-31');
        $pago = app(CobroService::class)->cobrar($this->credito, new SolicitudCobro(60_000, DestinoExcedente::Devolver, $this->efectivo->id, 'mc-sin-doc'), $this->admin);

        $pdf = app(\App\Services\Creditos\Documentos\DocumentoCreditoService::class)->recibo($pago, 'ticket80mm', false, $this->admin);

        $this->assertSame(200, $pdf->getStatusCode());
        $this->assertSame(0, CreditoEnlaceCliente::count());
        $this->assertNull(app(\App\Services\Creditos\MiCredito\QrMiCredito::class)->paraCliente($this->cliente->fresh()));
    }

    public function test_la_vista_previa_del_contrato_no_crea_links(): void
    {
        $pdf = app(\App\Services\Creditos\Documentos\DocumentoCreditoService::class)->vistaPreviaContrato('<p>Contrato</p>', $this->credito);

        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
        $this->assertSame(0, CreditoEnlaceCliente::count());
    }

    public function test_un_link_inexistente_responde_404_sin_dar_pistas(): void
    {
        try {
            $this->verificar('NoExisteEsteToken', '8912');
            $this->fail('Debía dar 404.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertSame(0, CreditoEnlaceCliente::count());
    }
}
