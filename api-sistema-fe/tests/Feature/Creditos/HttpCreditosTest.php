<?php

declare(strict_types=1);

namespace Tests\Feature\Creditos;

use App\Http\Controllers\Creditos\CreditoCicloController;
use App\Http\Controllers\Creditos\CreditoController;
use App\Http\Controllers\Creditos\CreditoPagoController;
use App\Http\Requests\Creditos\ClaveRequest;
use App\Http\Requests\Creditos\CobrarRequest;
use App\Http\Requests\Creditos\CrearCreditoRequest;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoConfiguracion;
use App\Models\Creditos\CreditoPago;
use App\Models\User;
use App\Services\Creditos\Idempotencia;
use App\Services\Creditos\LimitesExcedidos;
use App\Services\Creditos\Motor\Dto\Infraccion;
use App\Services\Creditos\Motor\Enums\ReglaLimite;
use App\Services\Creditos\Motor\Excepciones\CondicionesInvalidas;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** 03-api: rutas protegidas, idempotencia de punta a punta, validación y errores sin datos internos. */
class HttpCreditosTest extends CreditosTestCase
{
    private const PIPELINE_TENANT = ['tenant', 'tenant.active', 'tenant.subscription', 'tenant.token', 'auth:api'];

    /** @return array<string, array{string, string, string}> */
    public static function rutas(): array
    {
        $filas = [
            ['GET', 'api/creditos', 'creditos.ver'],
            ['POST', 'api/creditos/preview', 'creditos.crear|creditos.migrar'],
            ['POST', 'api/creditos', 'creditos.crear'],
            ['POST', 'api/creditos/migrar', 'creditos.migrar'],
            ['GET', 'api/creditos/cobranza-del-dia', 'creditos.cobrar'],
            ['GET', 'api/creditos/cartera/usuarios', 'creditos.cartera.asignar'],
            ['PUT', 'api/creditos/configuracion', 'creditos.configurar'],
            ['POST', 'api/creditos/feriados', 'creditos.configurar'],
            ['GET', 'api/creditos/{credito}', 'creditos.ver'],
            ['PUT', 'api/creditos/{credito}', 'creditos.crear'],
            ['GET', 'api/creditos/{credito}/estado-cuenta', 'creditos.ver'],
            ['POST', 'api/creditos/{credito}/activar', 'creditos.crear'],
            ['POST', 'api/creditos/{credito}/corregir', 'creditos.corregir'],
            ['POST', 'api/creditos/{credito}/anular', 'creditos.corregir'],
            ['POST', 'api/creditos/{credito}/castigar', 'creditos.castigar'],
            ['POST', 'api/creditos/{credito}/revertir-castigo', 'creditos.castigar'],
            ['POST', 'api/creditos/{credito}/autorizaciones', 'creditos.autorizar_excepcion'],
            ['POST', 'api/creditos/{credito}/cambiar-asesor', 'creditos.cartera.asignar'],
            ['POST', 'api/creditos/{credito}/pagos/cotizar', 'creditos.cobrar'],
            ['POST', 'api/creditos/{credito}/pagos', 'creditos.cobrar'],
            ['PATCH', 'api/creditos/{credito}/pagos/{pago}', 'creditos.cobrar'],
            ['POST', 'api/creditos/{credito}/pagos/{pago}/anular', 'creditos.cobrar'],
            ['GET', 'api/creditos/{credito}/liquidacion', 'creditos.cobrar'],
            ['POST', 'api/creditos/{credito}/liquidar', 'creditos.cobrar'],
            ['POST', 'api/creditos/{credito}/reprogramar/preview', 'creditos.reprogramar'],
            ['POST', 'api/creditos/{credito}/reprogramar', 'creditos.reprogramar'],
            ['POST', 'api/creditos/{credito}/condonar-mora', 'creditos.condonar_mora'],
            ['POST', 'api/creditos/{credito}/renovar/preview', 'creditos.crear'],
            ['POST', 'api/creditos/{credito}/renovar', 'creditos.crear'],
            ['GET', 'api/creditos/{credito}/documentos', 'creditos.ver'],
            ['GET', 'api/creditos/{credito}/documentos/url', 'creditos.ver'],
            ['GET', 'api/creditos/{credito}/documentos/{documento}/url', 'creditos.ver'],
            ['POST', 'api/creditos/{credito}/documentos/contrato-firmado', 'creditos.crear'],
            ['GET', 'api/creditos/{credito}/pagos/{pago}/recibo-url', 'creditos.ver'],
            ['GET', 'api/creditos/plantillas/contrato', 'creditos.configurar'],
            ['PUT', 'api/creditos/plantillas/contrato', 'creditos.configurar'],
            ['POST', 'api/creditos/plantillas/contrato/vista-previa', 'creditos.configurar'],
            ['GET', 'api/clientes/{cliente}/resumen-credito', 'creditos.crear|creditos.ver'],
            ['GET', 'api/clientes/{cliente}/ficha-credito', 'creditos.ver'],
            ['PUT', 'api/clientes/{cliente}/ficha-credito', 'creditos.crear'],
            ['POST', 'api/clientes/{cliente}/ficha-credito/archivos', 'creditos.crear'],
            ['PUT', 'api/clientes/{cliente}/cartera', 'creditos.cartera.asignar'],
            ['PUT', 'api/clientes/{cliente}/limites-credito', 'creditos.configurar'],
            ['POST', 'api/clientes/{cliente}/saldo-a-favor/{movimiento}/anular', 'creditos.cobrar'],
        ];

        return array_combine(array_map(static fn (array $f): string => "{$f[0]} {$f[1]}", $filas), $filas);
    }

    #[DataProvider('rutas')]
    public function test_cada_ruta_exige_tenant_autenticacion_y_su_permiso(string $metodo, string $uri, string $permiso): void
    {
        $ruta = collect(Route::getRoutes()->getRoutes())->first(
            fn ($r) => $r->uri() === $uri && in_array($metodo, $r->methods(), true),
        );

        $this->assertNotNull($ruta, "No existe la ruta {$metodo} {$uri}");
        $middleware = $ruta->gatherMiddleware();
        foreach (self::PIPELINE_TENANT as $m) {
            $this->assertContains($m, $middleware, "{$metodo} {$uri} sin {$m}");
        }
        $this->assertContains("permission:{$permiso}", $middleware);
    }

    public function test_ninguna_ruta_del_modulo_queda_sin_permiso(): void
    {
        $sinPermiso = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/creditos') || preg_match('#^api/clientes/\{cliente\}/(ficha-credito|resumen-credito|cartera|limites-credito)#', $r->uri()))
            ->reject(fn ($r) => collect($r->gatherMiddleware())->contains(fn ($m) => str_starts_with($m, 'permission:creditos.')))
            // PDFs por URL firmada: sin token a propósito; los cubre el test de abajo.
            ->reject(fn ($r) => in_array('signed', $r->gatherMiddleware(), true))
            ->map(fn ($r) => $r->uri())
            ->values()->all();

        $this->assertSame([], $sinPermiso);
    }

    public function test_los_pdf_del_modulo_solo_se_sirven_con_url_firmada_y_tenant(): void
    {
        foreach (['creditos.pdf', 'creditos.recibo.pdf', 'creditos.archivo'] as $nombre) {
            $middleware = Route::getRoutes()->getByName($nombre)?->gatherMiddleware() ?? [];
            foreach (['tenant', 'tenant.active', 'tenant.token', 'signed'] as $m) {
                $this->assertContains($m, $middleware, "{$nombre} sin {$m}");
            }
        }
    }

    // ---- Idempotencia ----

    /** @template T of FormRequest @param class-string<T> $clase @return T */
    private function peticion(string $clase, array $datos, User $usuario): FormRequest
    {
        $request = $clase::create('/api/test', 'POST', $datos);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->setUserResolver(fn () => $usuario);
        $request->validateResolved();

        return $request;
    }

    private function datosCredito(int $clienteId, string $clave): array
    {
        return [
            'cliente_id' => $clienteId, 'monto_capital' => '5000', 'tasa_interes' => '20', 'unidad_tasa' => 'total',
            'frecuencia_unidad' => 'dia', 'frecuencia_intervalo' => 30, 'numero_cuotas' => 10,
            'fecha_desembolso' => '2026-01-01', 'payment_method_id' => $this->efectivo->id, 'clave_idempotencia' => $clave,
        ];
    }

    public function test_cobrar_mora_viene_de_la_configuracion_y_se_puede_cambiar_por_credito(): void
    {
        $usuario = $this->usuario();
        $controller = app(CreditoController::class);
        $crear = fn (array $extra, string $clave) => $controller->store(
            $this->peticion(CrearCreditoRequest::class, $this->datosCredito($this->cliente()->id, $clave) + $extra, $usuario),
        )->getData(true)['credito'];

        $this->assertTrue($crear([], 'mora-0001')['cobra_mora']);
        $this->assertFalse($crear(['cobra_mora' => false], 'mora-0002')['cobra_mora']);

        CreditoConfiguracion::actual()->update(['cobra_mora' => false]);
        $this->assertFalse($crear([], 'mora-0003')['cobra_mora']);
        $this->assertTrue($crear(['cobra_mora' => true], 'mora-0004')['cobra_mora']);
    }

    public function test_doble_envio_de_crear_y_de_cobrar_no_duplica(): void
    {
        $usuario = $this->usuario();
        $this->abrirCaja($usuario);
        $cliente = $this->cliente();
        $controller = app(CreditoController::class);

        $a = $controller->store($this->peticion(CrearCreditoRequest::class, $this->datosCredito($cliente->id, 'crear-0001'), $usuario))->getData(true);
        $b = $controller->store($this->peticion(CrearCreditoRequest::class, $this->datosCredito($cliente->id, 'crear-0001'), $usuario))->getData(true);
        $this->assertSame($a, $b);
        $this->assertSame(1, Credito::where('cliente_id', $cliente->id)->count());

        $creditoId = $a['credito']['id'];
        app(CreditoCicloController::class)->activar($this->peticion(ClaveRequest::class, ['clave_idempotencia' => 'activar-0001'], $usuario), $creditoId);
        $this->hoy('2026-01-31');
        $cobro = ['monto_recibido' => '600', 'payment_method_id' => $this->efectivo->id, 'clave_idempotencia' => 'cobro-0001'];
        app(CreditoPagoController::class)->store($this->peticion(CobrarRequest::class, $cobro, $usuario), $creditoId);
        app(CreditoPagoController::class)->store($this->peticion(CobrarRequest::class, $cobro, $usuario), $creditoId);

        $this->assertSame(1, CreditoPago::where('credito_id', $creditoId)->count());
    }

    public function test_misma_clave_con_otro_contenido_u_otro_usuario_es_409(): void
    {
        $usuario = $this->usuario();
        $otro = $this->usuario();
        $idempotencia = app(Idempotencia::class);
        $ejecuciones = 0;
        $accion = function () use (&$ejecuciones): array {
            $ejecuciones++;

            return [['ok' => true], null];
        };

        $idempotencia->ejecutar('clave-409-0001', 'prueba', ['monto' => 100], $usuario, $accion);
        $idempotencia->ejecutar('clave-409-0001', 'prueba', ['monto' => 100], $usuario, $accion);
        $this->assertSame(1, $ejecuciones);

        foreach ([[['monto' => 200], $usuario], [['monto' => 100], $otro]] as [$contenido, $quien]) {
            try {
                $idempotencia->ejecutar('clave-409-0001', 'prueba', $contenido, $quien, $accion);
                $this->fail('Debía responder 409.');
            } catch (HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
        }
    }

    public function test_si_la_operacion_falla_la_clave_se_puede_reintentar(): void
    {
        $usuario = $this->usuario();
        $idempotencia = app(Idempotencia::class);

        try {
            $idempotencia->ejecutar('clave-falla-0001', 'prueba', [], $usuario, fn (): array => throw new HttpException(422, 'falla'));
        } catch (HttpException) {
        }

        $this->assertSame(['ok' => 1], $idempotencia->ejecutar('clave-falla-0001', 'prueba', [], $usuario, fn (): array => [['ok' => 1], null]));
    }

    // ---- Validación y errores ----

    public function test_capital_no_multiplo_de_10_centimos_se_rechaza(): void
    {
        $this->expectException(ValidationException::class);
        $this->peticion(CrearCreditoRequest::class, [...$this->datosCredito($this->cliente()->id, 'val-0001'), 'monto_capital' => '1000.05'], $this->usuario());
    }

    public function test_escritura_sin_clave_de_idempotencia_se_rechaza(): void
    {
        $datos = $this->datosCredito($this->cliente()->id, 'x');
        unset($datos['clave_idempotencia']);

        $this->expectException(ValidationException::class);
        $this->peticion(CrearCreditoRequest::class, $datos, $this->usuario());
    }

    public function test_errores_del_motor_y_de_limites_salen_como_422_sin_datos_internos(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        $request = Request::create('/api/creditos', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);

        $motor = $handler->render($request, new CondicionesInvalidas('El capital debe ser mayor a cero.'));
        $this->assertSame(422, $motor->getStatusCode());
        $this->assertSame(['message' => 'El capital debe ser mayor a cero.'], $motor->getData(true));

        $limites = $handler->render($request, new LimitesExcedidos([new Infraccion(ReglaLimite::Moroso, true, true, ['dias_atraso' => 9])]));
        $this->assertSame(422, $limites->getStatusCode());
        $this->assertSame('moroso', $limites->getData(true)['bloqueos'][0]['regla']);
        $this->assertArrayNotHasKey('trace', $limites->getData(true));
    }
}
