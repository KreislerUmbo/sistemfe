<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ConsultaController;
use App\Http\Controllers\AgenciaViajes\CotizacionController;
use App\Http\Controllers\Client\ClientController;
use App\Http\Controllers\Client\CrmOrigenContactoController;
use App\Http\Requests\Client\ClienteRequest;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\AgenciaViajes\TipoCambioAgencia;
use App\Models\Client\Client;
use App\Models\Client\CrmAccion;
use App\Models\Client\CrmActividad;
use App\Models\Client\CrmOrigenContacto;
use App\Models\TipoCambio\TipoCambioSunat;
use App\Models\User;
use App\Services\HoraPeru;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// T-059 (CRM C4) — «Nueva consulta», coincidencias por teléfono y nombre, origen de contacto en la
// cotización y en el cliente (el del primer contacto) y el catálogo de orígenes. Postgres real
// (sistemafe_test_migrations), transacción por test revertida; mismo armado que
// AlternativaAutomaticaTest (tipo de cambio) y CotizacionResultadoTest (permisos). Las filas base del
// catálogo las insertó la migración.
class ConsultaOrigenContactoTest extends TestCase
{
    private User $usuario;

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
        // tipo_cambio_sunat vive en 'central': se limpia dentro de su propia transacción.
        DB::connection('central')->beginTransaction();

        // 02:00 UTC del 3-nov = 21:00 del 2-nov en Perú: «hoy» es el 2.
        Carbon::setTestNow(Carbon::parse('2026-11-03 02:00:00', 'UTC'));

        DB::table('tipo_cambio_agencia')->delete();
        TipoCambioSunat::query()->delete();

        $this->usuario = $this->usuario(['cotizaciones.crear', 'cotizaciones.ver_todas', 'register_client']);
        TipoCambioAgencia::create([
            'fecha' => HoraPeru::hoyTexto(), 'origen' => 'dia', 'valor' => 3.75, 'registrado_por' => $this->usuario->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::connection('central')->rollBack();
        DB::rollBack();
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private function usuario(array $permisos): User
    {
        $user = User::factory()->create([
            'email' => 'vendedor-' . uniqid() . '@agencia.test',
            'role_id' => Role::create(['name' => 'rol-t059-' . uniqid(), 'guard_name' => 'api'])->id,
        ]);
        foreach ($permisos as $permiso) {
            $user->givePermissionTo(Permission::firstOrCreate(['guard_name' => 'api', 'name' => $permiso]));
        }
        Auth::guard('api')->setUser($user->fresh());

        return $user;
    }

    private function origen(string $nombre): CrmOrigenContacto
    {
        return CrmOrigenContacto::where('nombre', $nombre)->firstOrFail();
    }

    private function cliente(array $extra = []): Client
    {
        return Client::create(array_merge([
            'type_document' => 'DNI', 'n_document' => (string) random_int(10_000_000, 99_999_999),
            'full_name' => 'Cliente T059', 'type_client' => 1, 'state' => 1,
        ], $extra));
    }

    private function consulta(array $datos)
    {
        return app(ConsultaController::class)->store(new Request($datos + [
            'nombre' => 'Juan Pérez',
            'destino' => 'Tarapoto',
            'pasajeros' => 1,
        ]));
    }

    private function coincidencias(array $query): array
    {
        $respuesta = app(ConsultaController::class)->coincidencias(new Request($query));

        return array_column($respuesta->getData(true)['clientes'], 'full_name');
    }

    private function nuevaCotizacion(int $clienteId, array $extra = [])
    {
        return app(CotizacionController::class)->store(new Request([
            'cliente_id' => $clienteId,
            'destino' => 'Cusco',
            'pasajeros' => [['edad' => 30]],
        ] + $extra));
    }

    private function catalogo(): CrmOrigenContactoController
    {
        return app(CrmOrigenContactoController::class);
    }

    private function codigoHttp(callable $accion): int
    {
        try {
            return $accion()->getStatusCode();
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }

    // ── CA1: consulta nueva ──────────────────────────────────────────

    public function test_consulta_crea_cliente_snd_cotizacion_con_alternativa_nota_y_accion_para_hoy(): void
    {
        $facebook = $this->origen('Facebook');

        $respuesta = $this->consulta([
            'nombre' => '  Juan   Pérez ', 'telefono' => '999 888 777', 'origen_contacto_id' => $facebook->id,
            'destino' => 'Punta Cana', 'fecha_viaje_desde' => '2026-12-10', 'pasajeros' => 2,
            'nota' => 'Quiere todo incluido', 'agendar_envio' => true,
        ]);

        $this->assertSame(200, $respuesta->getStatusCode());
        $datos = $respuesta->getData(true);
        $this->assertTrue($datos['cliente_creado']);

        $cotizacion = Cotizacion::findOrFail($datos['cotizacion']['id']);
        $this->assertSame($cotizacion->codigo, $datos['cotizacion']['codigo']);
        $this->assertSame('Punta Cana', $cotizacion->destino);
        $this->assertSame('2026-12-10', $cotizacion->fecha_viaje_desde->format('Y-m-d'));
        $this->assertSame($facebook->id, $cotizacion->origen_contacto_id);
        $this->assertSame($this->usuario->id, $cotizacion->vendedor_id);

        $cliente = Client::findOrFail($cotizacion->cliente_id);
        $this->assertSame(['SND', '00000000', 'Juan Pérez', '999 888 777', $facebook->id],
            [$cliente->type_document, $cliente->n_document, $cliente->full_name, $cliente->phone, $cliente->origen_contacto_id]);

        $alternativas = Alternativa::where('cotizacion_id', $cotizacion->id)->get();
        $this->assertCount(1, $alternativas);
        $this->assertSame('Alternativa A', $alternativas[0]->nombre);

        $pasajeros = CotizacionPasajero::where('cotizacion_id', $cotizacion->id)->get();
        $this->assertCount(2, $pasajeros);
        foreach ($pasajeros as $pax) {
            $this->assertSame([30, 'adulto', CotizacionPasajero::RESIDENCIA_NACIONAL], [(int) $pax->edad, $pax->tipo_pax, $pax->residencia]);
        }

        $actividad = CrmActividad::where('referencia_tipo', CrmActividad::REFERENCIA_COTIZACION)->where('referencia_id', $cotizacion->id)->sole();
        $this->assertSame([CrmActividad::TIPO_NOTA, 'Quiere todo incluido', $cliente->id, $this->usuario->id],
            [$actividad->tipo, $actividad->nota, $actividad->cliente_id, $actividad->usuario_id]);

        $accion = CrmAccion::where('referencia_tipo', CrmActividad::REFERENCIA_COTIZACION)->where('referencia_id', $cotizacion->id)->sole();
        $this->assertSame([CrmAccion::TIPO_ENVIAR_COTIZACION, '2026-11-02', $this->usuario->id, $this->usuario->id, $cliente->id],
            [$accion->tipo, $accion->fecha->format('Y-m-d'), $accion->responsable_id, $accion->creada_por, $accion->cliente_id]);
        $this->assertNull($accion->hecha_en);
    }

    public function test_consulta_sin_nota_ni_casilla_no_registra_actividad_ni_accion(): void
    {
        $respuesta = $this->consulta(['telefono' => '', 'nota' => '   ']);

        $this->assertSame(200, $respuesta->getStatusCode());
        $id = $respuesta->getData(true)['cotizacion']['id'];
        $this->assertSame(0, CrmActividad::where('referencia_tipo', 'cotizacion')->where('referencia_id', $id)->count());
        $this->assertSame(0, CrmAccion::where('referencia_tipo', 'cotizacion')->where('referencia_id', $id)->count());
        $cotizacion = Cotizacion::findOrFail($id);
        $this->assertNull($cotizacion->origen_contacto_id);
        $this->assertNull(Client::findOrFail($cotizacion->cliente_id)->phone);
    }

    public function test_consulta_valida_nombre_destino_telefono_y_pasajeros(): void
    {
        $antes = Client::count();

        foreach ([
            ['nombre' => ''],
            ['destino' => ''],
            ['telefono' => '999-abc'],
            ['pasajeros' => 0],
            ['pasajeros' => 31],
            ['nota' => str_repeat('x', CrmActividad::NOTA_MAX + 1)],
            ['fecha_viaje_desde' => '2026-12-10', 'fecha_viaje_hasta' => '2026-12-01'],
        ] as $malo) {
            $this->assertSame(422, $this->consulta($malo)->getStatusCode(), json_encode($malo));
        }
        $this->assertSame(422, $this->consulta(['telefono' => 'x'])->getStatusCode());
        $this->assertSame($antes, Client::count());
    }

    // ── CA2: coincidencias y cliente existente ───────────────────────

    public function test_coincidencias_comparan_solo_digitos_del_telefono_y_nombre_desde_tres_letras(): void
    {
        $this->cliente(['full_name' => 'Ana Sin Espacios', 'phone' => '999888777']);
        $this->cliente(['full_name' => 'Beto Con Prefijo', 'phone' => '+51 999888777']);
        $this->cliente(['full_name' => 'Carla Otro Número', 'phone' => '988 777 666']);
        $this->cliente(['full_name' => 'Dora Inactiva', 'phone' => '999888777', 'state' => 2]);

        $this->assertSame(['Ana Sin Espacios', 'Beto Con Prefijo'], $this->coincidencias(['telefono' => '999 888 777']));
        $this->assertSame(['Ana Sin Espacios', 'Beto Con Prefijo'], $this->coincidencias(['telefono' => '+51 (999) 888-777']));
        $this->assertSame(['Carla Otro Número'], $this->coincidencias(['nombre' => 'carla']));
        $this->assertSame([], $this->coincidencias(['nombre' => 'ca', 'telefono' => '99']));
        $this->assertContains('Carla Otro Número', $this->coincidencias(['nombre' => 'Carla', 'telefono' => '999888777']));
        $this->assertNotContains('Dora Inactiva', $this->coincidencias(['nombre' => 'Dora']));

        $datos = app(ConsultaController::class)->coincidencias(new Request(['telefono' => '999888777']))->getData(true)['clientes'][0];
        $this->assertSame(['id', 'full_name', 'phone', 'type_document'], array_keys($datos));
    }

    public function test_consulta_con_cliente_elegido_no_crea_otro_y_usa_ese(): void
    {
        $existente = $this->cliente(['full_name' => 'Ana Sin Espacios', 'phone' => '999888777']);
        $antes = Client::count();

        $respuesta = $this->consulta(['cliente_id' => $existente->id, 'nombre' => 'Ana Sin Espacios', 'telefono' => '999 888 777']);

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertFalse($respuesta->getData(true)['cliente_creado']);
        $this->assertSame($antes, Client::count());
        $this->assertSame($existente->id, Cotizacion::findOrFail($respuesta->getData(true)['cotizacion']['id'])->cliente_id);

        $this->assertSame(422, $this->consulta(['cliente_id' => 999_999_999])->getStatusCode());
    }

    // ── CA3: origen del primer contacto ──────────────────────────────

    public function test_el_cliente_conserva_el_origen_del_primer_contacto_y_cada_cotizacion_guarda_el_suyo(): void
    {
        $facebook = $this->origen('Facebook');
        $recomendacion = $this->origen('Recomendación');

        $primera = $this->consulta(['origen_contacto_id' => $facebook->id])->getData(true)['cotizacion']['id'];
        $clienteId = Cotizacion::findOrFail($primera)->cliente_id;

        $segunda = $this->nuevaCotizacion($clienteId, ['origen_contacto_id' => $recomendacion->id]);
        $this->assertSame(200, $segunda->getStatusCode());
        $this->assertSame($recomendacion->id, Cotizacion::findOrFail($segunda->getData(true)['cotizacion']['id'])->origen_contacto_id);
        $this->assertSame($facebook->id, Client::findOrFail($clienteId)->origen_contacto_id);

        $sinOrigen = $this->cliente();
        $this->nuevaCotizacion($sinOrigen->id);
        $this->assertNull($sinOrigen->fresh()->origen_contacto_id);
        $this->nuevaCotizacion($sinOrigen->id, ['origen_contacto_id' => $recomendacion->id]);
        $this->assertSame($recomendacion->id, $sinOrigen->fresh()->origen_contacto_id);
    }

    public function test_la_ficha_corrige_el_origen_aun_desactivado_si_existe(): void
    {
        $cliente = $this->cliente(['type_document' => 'SND', 'n_document' => '00000000', 'full_name' => 'Para Corregir']);
        $instagram = $this->origen('Instagram');
        $instagram->update(['activo' => false]);

        $editar = function (array $datos) use ($cliente) {
            $request = ClienteRequest::create("/api/clients/{$cliente->id}", 'PUT', [
                'type_document' => 'SND', 'full_name' => 'Para Corregir', 'type_client' => 1, 'cod_tipo_doc_sunat' => '0', ...$datos,
            ]);
            $request->setContainer($this->app)->setRedirector($this->app['redirect']);
            $request->validateResolved();

            return app(ClientController::class)->update($request, (string) $cliente->id)->getData(true);
        };

        $respuesta = $editar(['origen_contacto_id' => $instagram->id]);
        $this->assertSame(200, $respuesta['code']);
        $this->assertSame($instagram->id, $respuesta['client']['origen_contacto_id']);
        $this->assertSame($instagram->id, $cliente->fresh()->origen_contacto_id);

        $editar(['origen_contacto_id' => null]);
        $this->assertNull($cliente->fresh()->origen_contacto_id);

        // Una pantalla que no manda el campo (modal rápido de Ventas) no lo borra.
        $cliente->update(['origen_contacto_id' => $instagram->id]);
        $editar(['phone' => '987654321']);
        $this->assertSame($instagram->id, $cliente->fresh()->origen_contacto_id);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $editar(['origen_contacto_id' => 999_999]);
    }

    // ── CA4: catálogo ────────────────────────────────────────────────

    public function test_desactivar_un_origen_en_uso_deja_de_ofrecerse_y_no_se_acepta_pero_los_datos_quedan(): void
    {
        $tiktok = $this->origen('TikTok');
        $usada = $this->consulta(['origen_contacto_id' => $tiktok->id])->getData(true)['cotizacion']['id'];

        $this->usuario(['agencia.configuracion', 'cotizaciones.crear', 'cotizaciones.ver_todas', 'register_client']);
        $this->assertSame(200, $this->catalogo()->update(new Request(['activo' => false]), (string) $tiktok->id)->getStatusCode());

        $activos = array_column($this->catalogo()->index(new Request(['solo_activos' => 1]))->getData(true)['origenes'], 'nombre');
        $this->assertNotContains('TikTok', $activos);
        $this->assertContains('TikTok', array_column($this->catalogo()->index(new Request())->getData(true)['origenes'], 'nombre'));

        $this->assertSame(422, $this->consulta(['origen_contacto_id' => $tiktok->id])->getStatusCode());
        $this->assertSame(422, $this->nuevaCotizacion($this->cliente()->id, ['origen_contacto_id' => $tiktok->id])->getStatusCode());

        $this->assertSame($tiktok->id, Cotizacion::findOrFail($usada)->origen_contacto_id);
        $this->assertSame($tiktok->id, Client::findOrFail(Cotizacion::findOrFail($usada)->cliente_id)->origen_contacto_id);
    }

    public function test_catalogo_agrega_al_final_sin_repetir_nombre_y_renombra(): void
    {
        $this->usuario(['agencia.configuracion']);

        $respuesta = $this->catalogo()->store(new Request(['nombre' => '  Feria de turismo ']));
        $this->assertSame(200, $respuesta->getStatusCode());
        $nombres = array_column($this->catalogo()->index(new Request())->getData(true)['origenes'], 'nombre');
        $this->assertSame('Feria de turismo', end($nombres));
        $this->assertSame('Otro', $nombres[count($nombres) - 2]);

        $this->assertSame(422, $this->catalogo()->store(new Request(['nombre' => 'whatsapp']))->getStatusCode());
        $this->assertSame(422, $this->catalogo()->store(new Request(['nombre' => '']))->getStatusCode());

        $llamada = $this->origen('Llamada');
        $this->assertSame(422, $this->catalogo()->update(new Request(['nombre' => 'FACEBOOK']), (string) $llamada->id)->getStatusCode());
        $this->assertSame(200, $this->catalogo()->update(new Request(['nombre' => 'Llamada telefónica', 'orden' => 1]), (string) $llamada->id)->getStatusCode());
        $this->assertSame(['Llamada telefónica', 1], [$llamada->fresh()->nombre, $llamada->fresh()->orden]);
        // «Otro» no tiene trato especial: se desactiva como cualquiera.
        $this->assertSame(200, $this->catalogo()->update(new Request(['activo' => false]), (string) $this->origen('Otro')->id)->getStatusCode());
    }

    public function test_index_devuelve_el_ultimo_origen_activo_que_uso_el_usuario(): void
    {
        $this->assertNull($this->catalogo()->index(new Request())->getData(true)['ultimo_del_usuario']);

        $web = $this->origen('Página web');
        $llamada = $this->origen('Llamada');
        $this->consulta(['origen_contacto_id' => $llamada->id]);
        $this->consulta(['origen_contacto_id' => $web->id]);
        $this->consulta([]);

        $this->assertSame($web->id, $this->catalogo()->index(new Request())->getData(true)['ultimo_del_usuario']);

        $web->update(['activo' => false]);
        $this->assertNull($this->catalogo()->index(new Request())->getData(true)['ultimo_del_usuario']);

        $this->usuario(['cotizaciones.crear']);
        $this->assertNull($this->catalogo()->index(new Request())->getData(true)['ultimo_del_usuario']);
    }

    // ── CA5: permisos y transacción ──────────────────────────────────

    public function test_sin_register_client_solo_con_cliente_elegido(): void
    {
        $existente = $this->cliente();
        $this->usuario(['cotizaciones.crear']);
        $antes = Client::count();

        $this->assertSame(403, $this->codigoHttp(fn () => $this->consulta([])));
        $this->assertSame($antes, Client::count());
        $this->assertSame(200, $this->codigoHttp(fn () => $this->consulta(['cliente_id' => $existente->id])));
    }

    public function test_rutas_con_sus_permisos_y_sin_delete(): void
    {
        $rutas = collect(Route::getRoutes()->getRoutes());
        $permiso = function (string $metodo, string $uri) use ($rutas) {
            $ruta = $rutas->first(fn ($r) => in_array($metodo, $r->methods(), true) && $r->uri() === "api/{$uri}");
            $this->assertNotNull($ruta, "{$metodo} {$uri}");

            return collect($ruta->gatherMiddleware())->first(fn ($m) => str_starts_with($m, 'permission:'));
        };

        $this->assertFalse($rutas->contains(fn ($r) => str_contains($r->uri(), 'crm-origenes-contacto') && in_array('DELETE', $r->methods(), true)));
        $this->assertSame('permission:agencia.configuracion|cotizaciones.crear', $permiso('GET', 'crm-origenes-contacto'));
        $this->assertSame('permission:agencia.configuracion', $permiso('POST', 'crm-origenes-contacto'));
        $this->assertSame('permission:agencia.configuracion', $permiso('PUT', 'crm-origenes-contacto/{id}'));
        $this->assertSame('permission:cotizaciones.crear', $permiso('GET', 'crm/consultas/coincidencias'));
        $this->assertSame('permission:cotizaciones.crear', $permiso('POST', 'crm/consultas'));
    }

    public function test_sin_tipo_de_cambio_no_queda_cliente_y_reenviando_con_valor_se_crea_todo(): void
    {
        DB::table('tipo_cambio_agencia')->delete();
        $clientes = Client::count();
        $cotizaciones = Cotizacion::count();
        $datos = ['origen_contacto_id' => $this->origen('WhatsApp')->id, 'nota' => 'Llamó', 'agendar_envio' => true];

        $respuesta = $this->consulta($datos);

        $this->assertSame(422, $respuesta->getStatusCode());
        $this->assertTrue($respuesta->getData(true)['requiere_tipo_cambio']);
        $this->assertSame([$clientes, $cotizaciones], [Client::count(), Cotizacion::count()]);
        $this->assertSame(0, CrmActividad::where('nota', 'Llamó')->count());

        $respuesta = $this->consulta($datos + ['tipo_cambio_valor' => 3.77]);
        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame([$clientes + 1, $cotizaciones + 1], [Client::count(), Cotizacion::count()]);
        $alternativa = Alternativa::where('cotizacion_id', $respuesta->getData(true)['cotizacion']['id'])->sole();
        $this->assertEquals(3.77, (float) $alternativa->tipo_cambio_aplicado);
    }

    // ── CA6: listado con origen ──────────────────────────────────────

    public function test_listado_trae_el_origen_y_filtra_por_el(): void
    {
        $whatsapp = $this->origen('WhatsApp');
        $conOrigen = $this->consulta(['origen_contacto_id' => $whatsapp->id])->getData(true)['cotizacion']['id'];
        $sinOrigen = $this->consulta([])->getData(true)['cotizacion']['id'];

        $filas = collect(app(CotizacionController::class)->index(new Request(['per_page' => 100]))->getData(true)['cotizaciones'])->keyBy('id');
        $this->assertSame(['id' => $whatsapp->id, 'nombre' => 'WhatsApp'], $filas[$conOrigen]['origen_contacto']);
        $this->assertNull($filas[$sinOrigen]['origen_contacto']);

        $filtradas = app(CotizacionController::class)->index(new Request(['origen_contacto_id' => $whatsapp->id, 'per_page' => 100]))->getData(true)['cotizaciones'];
        $this->assertSame([$conOrigen], array_column($filtradas, 'id'));
    }
}
