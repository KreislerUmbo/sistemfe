<?php

declare(strict_types=1);

namespace Tests\Feature\Creditos;

use App\Enums\Creditos\FuncionCartera;
use App\Enums\Creditos\TipoArchivoCliente;
use App\Http\Controllers\Client\ClientController;
use App\Http\Controllers\Creditos\ClienteCreditoController;
use App\Http\Requests\Client\ClienteRequest;
use App\Http\Requests\Creditos\FichaCreditoRequest;
use App\Http\Requests\Creditos\LimitesClienteRequest;
use App\Models\Client\Client;
use App\Models\Creditos\CarteraAsignacion;
use App\Models\Creditos\CreditoClienteArchivo;
use App\Models\Creditos\CreditoClienteFicha;
use App\Models\User;
use App\Services\Creditos\ActivacionService;
use App\Services\Creditos\ClienteCreditoService;
use App\Services\Creditos\CreditoBorradorService;
use App\Services\Creditos\LimitesExcedidos;
use App\Services\Creditos\LimitesService;
use App\Services\Creditos\Motor\Enums\ReglaLimite;
use App\Services\Tenancy\GiroActual;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fase 4c: reglas de alta de clientes (todos los giros), cartera por asesor/cobrador y ficha
 * exigida para prestar.
 */
final class ClientesYCarteraTest extends CreditosTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->usuario();
        $this->abrirCaja($this->admin);
    }

    // ---- Documento y nombre (todos los giros) ----

    public function test_el_numero_de_documento_no_se_repite_aunque_se_escriba_con_espacios(): void
    {
        $this->assertSame(200, $this->registrar(['n_document' => '45678912'])['code']);

        $repetido = $this->registrar(['n_document' => ' 4567 8912 ', 'name' => 'Otra', 'surname' => 'Persona', 'full_name' => 'Otra Persona']);

        $this->assertSame(405, $repetido['code']);
        $this->assertStringContainsString('Ana Torres', $repetido['message']);
        $this->assertSame(1, Client::where('n_document', '45678912')->count());
    }

    public function test_homonimos_con_documento_distinto_se_registran(): void
    {
        $this->assertSame(200, $this->registrar(['n_document' => '11111111'])['code']);
        $this->assertSame(200, $this->registrar(['n_document' => '22222222'])['code']);
    }

    public function test_sin_documento_el_nombre_repetido_pide_confirmacion(): void
    {
        $snd = ['type_document' => 'SND', 'n_document' => null, 'full_name' => 'Manuel recomienda'];
        $this->assertSame(200, $this->registrar($snd)['code']);

        $segundo = $this->registrar([...$snd, 'full_name' => 'MANUEL RECOMIENDA']);
        $this->assertSame(409, $segundo['code']);
        $this->assertTrue($segundo['requiere_confirmacion']);

        $this->assertSame(200, $this->registrar([...$snd, 'confirmar_nombre_repetido' => true])['code']);
        $this->assertSame(2, Client::where('type_document', 'SND')->whereRaw('lower(full_name) = ?', ['manuel recomienda'])->count());
    }

    public function test_documento_de_un_cliente_eliminado_ofrece_restaurarlo(): void
    {
        $id = $this->registrar(['n_document' => '33333333'])['client']['id'];
        $this->controlador()->destroy((string) $id);

        $otra = $this->registrar(['n_document' => '33333333']);
        $this->assertSame(410, $otra['code']);
        $this->assertSame($id, $otra['cliente_eliminado']['id']);

        $this->assertSame(200, $this->controlador()->restore((string) $id)->getData(true)['code']);
        $this->assertNull(Client::find($id)->deleted_at);
    }

    public function test_valida_el_formato_segun_el_tipo_de_documento(): void
    {
        foreach ([['DNI', '123'], ['RUC', '30123456789'], ['CE', 'ab']] as [$tipo, $numero]) {
            try {
                $this->peticion(ClienteRequest::class, $this->datosCliente(['type_document' => $tipo, 'n_document' => $numero]));
                $this->fail("{$tipo} {$numero} debía rechazarse");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('n_document', $e->errors());
            }
        }
        $this->assertSame(200, $this->registrar(['type_document' => 'RUC', 'n_document' => '20123456789', 'full_name' => 'Empresa SAC'])['code']);
    }

    public function test_un_cliente_antiguo_con_documento_mal_formado_se_edita_sin_tocar_el_documento(): void
    {
        $viejo = Client::create(['type_document' => 'DNI', 'n_document' => '1236544', 'full_name' => 'Cliente Antiguo', 'type_client' => 1]);
        $editar = function (array $datos) use ($viejo): FormRequest {
            $request = ClienteRequest::create("/api/clients/{$viejo->id}", 'PUT', $this->datosCliente($datos));
            $request->setContainer($this->app)->setRedirector($this->app['redirect']);
            $ruta = (new \Illuminate\Routing\Route('PUT', 'api/clients/{client}', []))->bind($request);
            $ruta->setParameter('client', (string) $viejo->id);
            $request->setRouteResolver(fn () => $ruta);
            $request->validateResolved();

            return $request;
        };

        Auth::guard('api')->setUser($this->admin);
        $respuesta = $this->controlador()->update($editar(['n_document' => '1236544', 'phone' => '999111222']), (string) $viejo->id)->getData(true);
        $this->assertSame(200, $respuesta['code']);
        $this->assertSame('999111222', $viejo->fresh()->phone);

        $this->expectException(ValidationException::class);
        $editar(['n_document' => '1236545']);
    }

    public function test_el_indice_unico_frena_dos_altas_simultaneas(): void
    {
        $fila = ['full_name' => 'Doble', 'type_document' => 'DNI', 'n_document' => '44444444', 'created_at' => now(), 'updated_at' => now()];
        DB::table('clients')->insert($fila);

        $this->expectException(QueryException::class);
        DB::table('clients')->insert($fila);
    }

    public function test_no_se_elimina_un_cliente_con_creditos(): void
    {
        $credito = $this->activo($this->admin);

        $respuesta = $this->controlador()->destroy((string) $credito->cliente_id)->getData(true);

        $this->assertSame(405, $respuesta['code']);
        $this->assertNotNull(Client::find($credito->cliente_id));
    }

    // ---- Cartera (giro Créditos) ----

    public function test_en_creditos_el_cliente_queda_en_la_cartera_de_quien_lo_registra(): void
    {
        $this->giroCreditos();
        $asesor = $this->usuario(['creditos.ver', 'creditos.crear', 'creditos.cobrar']);
        $otro = $this->usuario(['creditos.ver', 'creditos.crear', 'creditos.cobrar']);

        $id = $this->registrar(['n_document' => '55555555'], $asesor)['client']['id'];

        $this->assertSame($asesor->id, CarteraAsignacion::usuarioVigente($id, FuncionCartera::Asesor));
        $this->assertSame($asesor->id, CarteraAsignacion::usuarioVigente($id, FuncionCartera::Cobrador));
        $this->assertContains($id, $this->listado($asesor));
        $this->assertNotContains($id, $this->listado($otro));
        $this->assertContains($id, $this->listado($this->admin));   // ver_todos

        // Fuera de su cartera el cliente "no existe" y el duplicado no revela el nombre.
        Auth::guard('api')->setUser($otro);
        try {
            $this->controlador()->show((string) $id);
            $this->fail('Debía responder 404 fuera de la cartera.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        }
        $duplicado = $this->registrar(['n_document' => '55555555'], $otro);
        $this->assertSame(405, $duplicado['code']);
        $this->assertStringNotContainsString('Ana Torres', $duplicado['message']);
        $this->assertNull($duplicado['cliente_existente']);
    }

    public function test_quien_puede_asignar_elige_el_asesor_al_registrar(): void
    {
        $this->giroCreditos();
        $asesor = $this->usuario(['creditos.ver', 'creditos.crear', 'creditos.cobrar']);

        $id = $this->registrar(['n_document' => '66666666', 'asesor_id' => $asesor->id], $this->admin)['client']['id'];

        $this->assertSame($asesor->id, CarteraAsignacion::usuarioVigente($id, FuncionCartera::Asesor));
    }

    public function test_fuera_de_creditos_el_listado_no_se_filtra_por_cartera(): void
    {
        $vendedor = $this->usuario(['creditos.ver']);   // sin ver_todos, pero el giro no es créditos
        $id = $this->registrar(['n_document' => '77777777'])['client']['id'];

        $this->assertContains($id, $this->listado($vendedor));
        $this->assertSame(0, CarteraAsignacion::where('referencia_id', $id)->count());
    }

    public function test_la_ficha_de_otro_asesor_no_se_puede_editar(): void
    {
        $cliente = $this->cliente();
        $ajeno = $this->usuario(['creditos.ver', 'creditos.crear', 'creditos.cobrar']);
        Auth::guard('api')->setUser($ajeno);

        $this->expectException(HttpException::class);
        app(ClienteCreditoController::class)->guardarFicha(
            $this->peticion(FichaCreditoRequest::class, ['direccion_cobro' => 'Jr. Ajeno 1'], $ajeno), $cliente->id,
        );
    }

    public function test_el_credito_guarda_al_asesor_y_lo_fija_al_activar(): void
    {
        $cliente = $this->cliente();
        $primero = $this->usuario(['creditos.crear', 'creditos.cobrar']);
        $segundo = $this->usuario(['creditos.crear', 'creditos.cobrar']);
        app(ClienteCreditoService::class)->asignarCartera($cliente, $primero, null, $this->admin);

        $borrador = app(CreditoBorradorService::class)->crear($this->datos($cliente->id), $this->admin);
        $this->assertSame($primero->id, $borrador->asesor_id);

        // Se reasigna antes de activar: el crédito lo coloca el asesor vigente al activar.
        app(ClienteCreditoService::class)->asignarCartera($cliente, $segundo, null, $this->admin);
        $activo = app(ActivacionService::class)->activar($borrador, $this->admin);
        $this->assertSame($segundo->id, $activo->asesor_id);

        app(ClienteCreditoService::class)->asignarCartera($cliente, $primero, null, $this->admin);
        $this->assertSame($segundo->id, $activo->fresh()->asesor_id);
    }

    // ---- Ficha exigida para prestar ----

    public function test_la_ficha_incompleta_bloquea_la_activacion_y_se_puede_autorizar(): void
    {
        DB::table('credito_configuracion')->update(['requisitos_ficha' => json_encode(['dni_anverso', 'direccion_cobro', 'ubicacion'])]);
        $cliente = $this->cliente();
        $borrador = app(CreditoBorradorService::class)->crear($this->datos($cliente->id), $this->admin);

        try {
            app(ActivacionService::class)->activar($borrador, $this->admin);
            $this->fail('Debía bloquear por ficha incompleta.');
        } catch (LimitesExcedidos $e) {
            $this->assertSame([[
                'regla' => 'ficha_incompleta', 'autorizable' => true,
                'detalle' => ['faltan' => ['dni_anverso', 'direccion_cobro', 'ubicacion']],
            ]], $e->detalle());
        }

        app(LimitesService::class)->autorizar($borrador, ReglaLimite::FichaIncompleta, 'Cliente conocido, trae el DNI mañana', $this->admin);
        $this->assertSame('activo', app(ActivacionService::class)->activar($borrador->fresh(), $this->admin)->estado->value);
    }

    public function test_con_la_ficha_completa_activa_sin_autorizacion(): void
    {
        DB::table('credito_configuracion')->update(['requisitos_ficha' => json_encode(['dni_anverso', 'direccion_cobro', 'ubicacion'])]);
        $cliente = $this->cliente();
        CreditoClienteFicha::create(['cliente_id' => $cliente->id, 'direccion_cobro' => 'Av. Grau 123', 'latitud' => '-12.0464', 'longitud' => '-77.0428']);
        CreditoClienteArchivo::create(['cliente_id' => $cliente->id, 'tipo' => TipoArchivoCliente::DniAnverso, 'ruta_archivo' => 'x.jpg', 'registrado_por' => $this->admin->id]);

        $this->assertSame([], app(ClienteCreditoService::class)->fichaFaltante($cliente->id));
        $borrador = app(CreditoBorradorService::class)->crear($this->datos($cliente->id), $this->admin);
        $this->assertSame('activo', app(ActivacionService::class)->activar($borrador, $this->admin)->estado->value);
    }

    public function test_en_migracion_la_ficha_incompleta_solo_advierte(): void
    {
        DB::table('credito_configuracion')->update(['requisitos_ficha' => json_encode(['foto_cliente'])]);
        $cliente = $this->cliente();

        $resultado = app(LimitesService::class)->evaluar($cliente->id, 100_000, null, true);

        $this->assertSame([], $resultado->bloqueos());
        $this->assertFalse($resultado->infraccion(ReglaLimite::FichaIncompleta)?->bloquea);
    }

    // ---- Límites propios del cliente ----

    public function test_bloqueo_manual_exige_motivo_y_bloquea_nuevos_creditos(): void
    {
        $cliente = $this->cliente();
        try {
            $this->peticion(LimitesClienteRequest::class, ['bloqueado' => true]);
            $this->fail('Debía exigir el motivo.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('motivo_bloqueo', $e->errors());
        }

        app(ClienteCreditoController::class)->guardarLimites(
            $this->peticion(LimitesClienteRequest::class, ['bloqueado' => true, 'motivo_bloqueo' => 'Datos falsos', 'max_creditos_activos' => 1]),
            $cliente->id,
        );

        $resultado = app(LimitesService::class)->evaluar($cliente->id, 100_000);
        $this->assertTrue($resultado->infraccion(ReglaLimite::Bloqueado)?->bloquea);
        $this->assertSame(1, DB::table('credito_auditoria')->where('accion', 'cliente.limites')->where('auditable_id', $cliente->id)->count());
    }

    // ---- Ayudas ----

    private function giroCreditos(): void
    {
        $this->app->instance(GiroActual::class, new GiroActual(GiroActual::CREDITOS));
    }

    private function controlador(): ClientController
    {
        return app(ClientController::class);
    }

    /** @return array<string, mixed> */
    private function registrar(array $datos, ?User $usuario = null): array
    {
        $usuario ??= $this->admin;
        Auth::guard('api')->setUser($usuario);

        return $this->controlador()->store($this->peticion(ClienteRequest::class, $this->datosCliente($datos), $usuario))->getData(true);
    }

    /** @return list<int> */
    private function listado(User $usuario): array
    {
        Auth::guard('api')->setUser($usuario);
        $datos = $this->controlador()->index(new Request(['per_page' => 100]))->getData(true);

        return array_column($datos['clients']['data'] ?? $datos['clients'], 'id');
    }

    private function datosCliente(array $datos): array
    {
        return [
            'type_document' => 'DNI', 'n_document' => '12345678', 'name' => 'Ana', 'surname' => 'Torres',
            'full_name' => 'Ana Torres', 'type_client' => 1, 'cod_tipo_doc_sunat' => '1', ...$datos,
        ];
    }

    /** @template T of FormRequest @param class-string<T> $clase @return T */
    private function peticion(string $clase, array $datos, ?User $usuario = null): FormRequest
    {
        $request = $clase::create('/api/test', 'POST', $datos);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->setUserResolver(fn () => $usuario ?? $this->admin);
        $request->validateResolved();

        return $request;
    }
}
