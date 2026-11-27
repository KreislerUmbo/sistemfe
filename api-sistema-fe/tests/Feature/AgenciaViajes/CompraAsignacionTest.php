<?php

namespace Tests\Feature\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\CompraAsignacionController;
use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Http\Controllers\AgenciaViajes\ReservaItemController;
use App\Http\Controllers\AgenciaViajes\SalidaOperativaController;
use App\Http\Controllers\Compras\CompraController;
use App\Models\AgenciaViajes\CompraAsignacion;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Models\Compras\CompraPeriodo;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\AgenciaViajes\CompraAsignacionService;
use App\Services\AgenciaViajes\LimpiarOperacionPrueba;
use App\Services\Compras\CompraException;
use App\Services\HoraPeru;
use App\Services\Tenancy\GiroActual;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\AgenciaViajes\Concerns\SalidasFixtures;
use Tests\TestCase;

// T-152 — asignar a mano una compra a reservas, salidas o «general». Contra Postgres real;
// reservas y salidas de Concerns\SalidasFixtures.
class CompraAsignacionTest extends TestCase
{
    use SalidasFixtures {
        setUp as setUpSalidas;
    }

    private Proveedor $emisor;

    private int $correlativo = 0;

    protected function setUp(): void
    {
        $this->setUpSalidas();
        // El nombre del tipo de comprobante sale del catálogo central.
        config(['database.connections.central.database' => 'sistemafe_test_migrations']);
        DB::purge('central');
        $this->comoGiro('agencia_viajes');

        $this->emisor = Proveedor::create([
            'razon_social' => 'T152 Restaurante SAC', 'tipo_documento' => 'RUC', 'numero_documento' => '20152000001',
        ]);
    }

    private function comoGiro(string $giro): void
    {
        app()->instance(GiroActual::class, new GiroActual($giro));
    }

    private function datosCompra(string $total, array $cambios = []): array
    {
        return array_merge([
            'proveedor_id' => $this->emisor->id,
            'tipo_comprobante_codigo' => '01',
            'serie' => 'F152',
            'numero' => (string) ++$this->correlativo,
            'fecha_emision' => '2026-03-10',
            'moneda' => 'PEN',
            'base_gravada' => $total, 'igv' => '0', 'exonerado' => '0', 'inafecto' => '0', 'otros' => '0', 'total' => $total,
            'condicion' => 'contado',
        ], $cambios);
    }

    private function compra(string $total = '1000.00', array $cambios = []): int
    {
        $request = Request::create('/api/compras', 'POST', $this->datosCompra($total, $cambios));

        return app(CompraController::class)->store($request)->getData(true)['compra']['id'];
    }

    private function responder(callable $accion): JsonResponse
    {
        try {
            return $accion();
        } catch (CompraException $e) {
            return $e->render();
        }
    }

    private function asignar(int $compraId, mixed $filas): JsonResponse
    {
        $request = Request::create("/api/compras/{$compraId}/asignaciones", 'PUT', ['filas' => $filas]);

        return $this->responder(fn () => app(CompraAsignacionController::class)->guardar($request, (string) $compraId));
    }

    private function asignada(int $compraId, array $filas): array
    {
        $respuesta = $this->asignar($compraId, $filas);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());

        return $respuesta->getData(true)['asignaciones'];
    }

    private function aReserva(Reserva|int $reserva, string $monto): array
    {
        return ['destino' => 'reserva', 'reserva_id' => $reserva instanceof Reserva ? $reserva->id : $reserva, 'monto' => $monto];
    }

    private function aSalida(SalidaOperativa|int $salida, string $monto): array
    {
        return ['destino' => 'salida', 'salida_operativa_id' => $salida instanceof SalidaOperativa ? $salida->id : $salida, 'monto' => $monto];
    }

    private function aGeneral(string $monto): array
    {
        return ['destino' => 'general', 'monto' => $monto];
    }

    /** Reparto guardado, como «destino:id» => monto. */
    private function reparto(int $compraId): array
    {
        return CompraAsignacion::where('compra_id', $compraId)->orderBy('id')->get()
            ->mapWithKeys(fn (CompraAsignacion $a) => [$a->claveDestino() => $a->monto])->all();
    }

    private function detalle(int $compraId): array
    {
        return app(CompraController::class)->show((string) $compraId)->getData(true)['compra'];
    }

    private function ficha(string $metodo, int $id): array
    {
        return app(CompraAsignacionController::class)->{$metodo}((string) $id)->getData(true)['compras'];
    }

    private function reserva(string $desde = '2026-11-15'): Reserva
    {
        return $this->reservaConTour($this->crearTour(), [['adulto', 40]], desde: $desde);
    }

    private function darPermisosDeFicha(): void
    {
        Auth::guard('api')->user()->givePermissionTo(Permission::firstOrCreate(['name' => 'reservas.ver', 'guard_name' => 'api']));
    }

    // CA1
    public function test_reparte_entre_salidas_y_reserva(): void
    {
        $una = $this->reserva();
        $otra = $this->reserva();
        $compra = $this->compra();

        $this->asignada($compra, [
            $this->aSalida($this->salidaDe($una), '350.00'),
            $this->aSalida($this->salidaDe($otra), '250'),
            $this->aReserva($una, '400.00'),
        ]);

        $filas = $this->detalle($compra)['asignaciones'];
        $this->assertSame(['salida', 'salida', 'reserva'], array_column($filas, 'destino'));
        $this->assertSame(['350.00', '250.00', '400.00'], array_column($filas, 'monto'));
        $this->assertStringContainsString('Cliente Test Salidas', $filas[2]['titulo']);
        $this->assertStringContainsString('Laguna Azul Full Day', $filas[0]['titulo']);
        $this->assertSame([$una->id => '400.00'], app(CompraAsignacionService::class)->reservasDeCompra($compra));
    }

    // CA2
    public function test_la_suma_debe_ser_el_total(): void
    {
        $reserva = $this->reserva();
        $compra = $this->compra();
        $this->asignada($compra, [$this->aReserva($reserva, '1000')]);

        foreach (['999.99', '1000.01'] as $suma) {
            $respuesta = $this->asignar($compra, [$this->aGeneral($suma)]);

            $this->assertSame(422, $respuesta->getStatusCode());
            $this->assertStringContainsString('1000.00', $respuesta->getData(true)['message']);
            $this->assertStringContainsString($suma, $respuesta->getData(true)['message']);
        }
        $this->assertSame(["reserva:{$reserva->id}" => '1000.00'], $this->reparto($compra));
    }

    // CA3
    public function test_rechaza_filas_invalidas(): void
    {
        $reserva = $this->reserva();
        $compra = $this->compra();
        $casos = [
            'monto cero' => [$this->aReserva($reserva, '0'), $this->aGeneral('1000')],
            'monto negativo' => [$this->aReserva($reserva, '-1'), $this->aGeneral('1001')],
            'tres decimales' => [$this->aReserva($reserva, '0.001'), $this->aGeneral('999.999')],
            'destino repetido' => [$this->aReserva($reserva, '500'), $this->aReserva($reserva, '500')],
            'general repetido' => [$this->aGeneral('500'), $this->aGeneral('500')],
            'reserva sin id' => [['destino' => 'reserva', 'monto' => '1000']],
            'salida sin id' => [['destino' => 'salida', 'monto' => '1000']],
            'general con id' => [['destino' => 'general', 'reserva_id' => $reserva->id, 'monto' => '1000']],
            'dos ids' => [$this->aReserva($reserva, '1000') + ['salida_operativa_id' => $this->salidaDe($reserva)->id]],
            'destino desconocido' => [['destino' => 'otro', 'monto' => '1000']],
            'reserva inexistente' => [$this->aReserva(999999999, '1000')],
            'salida inexistente' => [$this->aSalida(999999999, '1000')],
            'reserva id cero' => [['destino' => 'reserva', 'reserva_id' => 0, 'monto' => '1000']],
            'salida id cero' => [['destino' => 'salida', 'salida_operativa_id' => 0, 'monto' => '1000']],
            'sin lista' => null,
            'más de 100 filas' => array_fill(0, 101, $this->aGeneral('1')),
        ];

        foreach ($casos as $caso => $filas) {
            $this->assertSame(422, $this->asignar($compra, $filas)->getStatusCode(), $caso);
        }
        $this->assertSame([], $this->reparto($compra));
    }

    // CA4
    public function test_sin_asignar_y_general(): void
    {
        $compra = $this->compra();
        $this->assertSame([], $this->detalle($compra)['asignaciones']);

        $this->asignada($compra, [$this->aGeneral('1000.00')]);
        $this->assertSame(['general:' => '1000.00'], $this->reparto($compra));
        $this->assertSame('General', $this->detalle($compra)['asignaciones'][0]['titulo']);

        $this->assertSame([], $this->asignada($compra, []));
        $this->assertSame([], $this->reparto($compra));
    }

    // CA5
    public function test_asignacion_en_dolares(): void
    {
        $this->darPermisosDeFicha();
        $reserva = $this->reserva();
        $compra = $this->compra('200.00', ['moneda' => 'USD', 'tipo_cambio' => '3.7500']);

        $this->asignada($compra, [$this->aReserva($reserva, '120.00'), $this->aSalida($this->salidaDe($reserva), '80.00')]);

        $fichaReserva = $this->ficha('deReserva', $reserva->id);
        $fichaSalida = $this->ficha('deSalida', $this->salidaDe($reserva)->id);
        $this->assertSame(['USD', '120.00', '200.00'], [$fichaReserva[0]['moneda'], $fichaReserva[0]['monto'], $fichaReserva[0]['total']]);
        $this->assertSame(['USD', '80.00'], [$fichaSalida[0]['moneda'], $fichaSalida[0]['monto']]);
    }

    // CA6 y CA7
    public function test_fichas_muestran_solo_su_parte(): void
    {
        $this->darPermisosDeFicha();
        $reserva = $this->reserva();
        $otra = $this->reserva();
        $salida = $this->salidaDe($reserva);
        $primera = $this->compra('1000.00', ['fecha_emision' => '2026-03-01']);
        $segunda = $this->compra('500.00', ['fecha_emision' => '2026-03-05']);
        $this->asignada($primera, [$this->aReserva($reserva, '300'), $this->aReserva($otra, '100'), $this->aSalida($salida, '600')]);
        $this->asignada($segunda, [$this->aReserva($reserva, '500')]);

        $filas = $this->ficha('deReserva', $reserva->id);

        $this->assertSame([$segunda, $primera], array_column($filas, 'compra_id'));
        $this->assertSame(['500.00', '300.00'], array_column($filas, 'monto'));
        $this->assertSame('T152 Restaurante SAC', $filas[0]['proveedor']['razon_social']);
        $this->assertNotSame('01', $filas[0]['tipo_nombre']);
        $this->assertSame(['600.00'], array_column($this->ficha('deSalida', $salida->id), 'monto'));
        $this->assertCount(2, app(CompraAsignacionService::class)->comprasDeReserva($reserva->id));
    }

    // CA7
    public function test_anulada(): void
    {
        $this->darPermisosDeFicha();
        $reserva = $this->reserva();
        $compra = $this->compra();
        $this->asignada($compra, [$this->aReserva($reserva, '1000')]);

        $anular = app(CompraController::class)->anular(new Request(['motivo' => 'Duplicada']), (string) $compra);

        $this->assertSame(200, $anular->getStatusCode());
        $this->assertSame(["reserva:{$reserva->id}" => '1000.00'], $this->reparto($compra));
        $this->assertCount(1, $this->detalle($compra)['asignaciones']);
        $this->assertSame([], $this->ficha('deReserva', $reserva->id));
        $this->assertCount(0, app(CompraAsignacionService::class)->comprasDeReserva($reserva->id));
        $this->assertSame(422, $this->asignar($compra, [])->getStatusCode());
    }

    // CA8
    public function test_periodo_cerrado_permite_reasignar(): void
    {
        $compra = $this->compra();
        CompraPeriodo::where('periodo', '2026-03')->update(['estado' => CompraPeriodo::CERRADO]);

        $this->asignada($compra, [$this->aGeneral('1000')]);

        $this->assertSame(['general:' => '1000.00'], $this->reparto($compra));
    }

    // CA9
    public function test_no_cambia_el_total_con_reparto(): void
    {
        $compra = $this->compra('1000.00', ['numero' => '900']);
        $this->asignada($compra, [$this->aGeneral('1000')]);
        $editar = fn (string $total, array $cambios = []) => $this->responder(fn () => app(CompraController::class)->update(
            Request::create("/api/compras/{$compra}", 'POST', $this->datosCompra($total, $cambios + ['numero' => '900'])),
            (string) $compra,
        ));

        foreach ([$editar('900.00'), $editar('1000.00', ['moneda' => 'USD', 'tipo_cambio' => '3.7'])] as $respuesta) {
            $this->assertSame(422, $respuesta->getStatusCode(), $respuesta->getContent());
            $this->assertStringContainsString('quita el reparto antes', $respuesta->getData(true)['message']);
        }

        $respuesta = $editar('1000.00', ['serie' => 'F999', 'observaciones' => 'Almuerzos de marzo']);
        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
        $this->assertSame('F999', $respuesta->getData(true)['compra']['serie']);

        $this->asignada($compra, []);
        $this->assertSame(200, $editar('900.00')->getStatusCode());
    }

    // CA10
    public function test_nota_de_credito_entre_reservas(): void
    {
        $una = $this->reserva();
        $otra = $this->reserva();
        $factura = $this->compra();
        $nota = $this->compra('150.00', ['tipo_comprobante_codigo' => '07', 'serie' => 'FC01', 'compra_referencia_id' => $factura]);

        $this->asignada($nota, [$this->aReserva($una, '100'), $this->aReserva($otra, '50')]);

        $this->assertSame(
            [$una->id => '100.00', $otra->id => '50.00'],
            app(CompraAsignacionService::class)->reservasDeCompra($nota),
        );
    }

    // CA11
    public function test_destinos_cancelados_o_realizados(): void
    {
        $cancelada = $this->reserva();
        $salidaCancelada = $this->salidaDe($cancelada);
        $realizada = $this->salidaDe($this->reserva());
        $cancelada->update(['estado' => 'cancelada']);
        $salidaCancelada->update(['estado' => SalidaOperativa::ESTADO_CANCELADA]);
        $realizada->update(['estado' => SalidaOperativa::ESTADO_REALIZADA]);

        $this->asignada($this->compra(), [
            $this->aReserva($cancelada, '100'), $this->aSalida($salidaCancelada, '400'), $this->aSalida($realizada, '500'),
        ]);

        $titulos = array_column(app(CompraAsignacionService::class)->destinos('salida', 'Laguna'), 'titulo', 'id');
        $this->assertStringContainsString('cancelada', $titulos[$salidaCancelada->id]);
        $this->assertStringContainsString('realizada', $titulos[$realizada->id]);
    }

    // CA12
    public function test_salida_con_compras_no_se_borra(): void
    {
        $quitar = function (Reserva $reserva) {
            $item = ReservaItem::where('reserva_id', $reserva->id)->firstOrFail();
            $respuesta = app(SalidaOperativaController::class)->detachReservaItem((string) $item->salida_operativa_id, (string) $item->id);
            $this->assertSame(200, $respuesta->getStatusCode());
        };
        $conCompra = $this->reserva();
        $sinCompra = $this->reserva('2026-11-20');
        $salidaConCompra = $this->salidaDe($conCompra);
        $salidaSinCompra = $this->salidaDe($sinCompra);
        $compra = $this->compra();
        $this->asignada($compra, [$this->aSalida($salidaConCompra, '1000')]);
        // También las de una compra anulada la sostienen (Q9).
        app(CompraController::class)->anular(new Request(['motivo' => 'Error']), (string) $compra);

        $quitar($conCompra);
        $quitar($sinCompra);

        $this->assertNotNull(SalidaOperativa::find($salidaConCompra->id));
        $this->assertNull(SalidaOperativa::find($salidaSinCompra->id));
    }

    // CA13
    public function test_reprogramar_salida_lleva_las_compras(): void
    {
        $en = fn (int $dias) => HoraPeru::hoy()->addDays($dias)->toDateString();
        $tour = $this->crearTour();
        $vieja = $this->salidaDe($this->reservaConTour($tour, [['adulto', 40]], desde: $en(7)));
        $destino = $this->salidaDe($this->reservaConTour($tour, [['adulto', 30]], desde: $en(14)));
        $ambas = $this->compra();
        $soloVieja = $this->compra();
        $this->asignada($ambas, [$this->aSalida($vieja, '600'), $this->aSalida($destino, '400')]);
        $this->asignada($soloVieja, [$this->aSalida($vieja, '1000')]);

        $reprogramar = fn (array $datos, int $id): JsonResponse => app()->call(
            [app(SalidaOperativaController::class), 'reprogramar'], ['request' => new Request($datos), 'id' => (string) $id],
        );

        $respuesta = $reprogramar(['fecha_nueva' => $en(14), 'motivo' => 'Lluvia'], $vieja->id);

        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
        $this->assertSame($destino->id, $respuesta->getData(true)['salida_nueva_id']);
        $this->assertSame(["salida:{$destino->id}" => '1000.00'], $this->reparto($ambas));
        $this->assertSame(["salida:{$destino->id}" => '1000.00'], $this->reparto($soloVieja));
        $this->assertNull(SalidaOperativa::find($vieja->id));

        // Sin ninguna reserva por mover (se cancela la única), las compras se quedan.
        $sola = $this->salidaDe($this->reservaConTour($this->crearTour(), [['adulto', 40]], desde: $en(7)));
        $quieta = $this->compra();
        $this->asignada($quieta, [$this->aSalida($sola, '1000')]);
        $respuesta = $reprogramar([
            'fecha_nueva' => $en(21), 'motivo' => 'Lluvia', 'motivo_cancelacion' => 'clima',
            'cancelar_reserva_ids' => $sola->reservaItems()->pluck('reserva_id')->all(),
        ], $sola->id);

        $this->assertSame(200, $respuesta->getStatusCode(), $respuesta->getContent());
        $this->assertSame(["salida:{$sola->id}" => '1000.00'], $this->reparto($quieta));
        $this->assertNotNull(SalidaOperativa::find($sola->id));
    }

    // CA14
    public function test_permisos_y_modulo(): void
    {
        $esperado = [
            'PUT api/compras/{id}/asignaciones' => 'compras.registrar',
            'GET api/compras/destinos' => 'compras.registrar',
            'GET api/reservas/{id}/compras' => 'compras.ver',
            'GET api/salidas-operativas/{id}/compras' => 'compras.ver',
        ];

        $reales = [];
        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            $clave = $ruta->methods()[0].' '.$ruta->uri();
            if (! isset($esperado[$clave])) {
                continue;
            }
            $middleware = $ruta->gatherMiddleware();
            $this->assertContains('tenant', $middleware, $clave);
            $this->assertContains('modulo:compras', $middleware, $clave);
            $reales[$clave] = implode(',', array_map(
                fn (string $m) => substr($m, strlen('permission:')),
                array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'permission:')),
            ));
        }

        $this->assertEquals($esperado, $reales);

        // Con compras.ver pero sin permiso de reservas, las fichas responden 403.
        $reserva = $this->reserva();
        $this->actuarComoVendedorSinVerTodas([]);
        foreach (['deReserva' => $reserva->id, 'deSalida' => $this->salidaDe($reserva)->id] as $metodo => $id) {
            try {
                $this->ficha($metodo, $id);
                $this->fail("{$metodo} debió responder 403");
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }

    // CA15 (Q12)
    public function test_reparte_reservas_de_otro_vendedor(): void
    {
        $ajena = $this->reserva();
        $compra = $this->compra();
        $this->actuarComoVendedorSinVerTodas(['reservas.ver']);

        $titulos = array_column(app(CompraAsignacionService::class)->destinos('reserva', 'Cliente Test Salidas'), 'titulo', 'id');
        $this->assertStringContainsString('Cliente Test Salidas', $titulos[$ajena->id]);

        $filas = $this->asignada($compra, [$this->aReserva($ajena, '1000')]);
        $this->assertStringContainsString('Cliente Test Salidas', $filas[0]['titulo']);

        $this->expectException(AuthorizationException::class);
        $this->ficha('deReserva', $ajena->id);
    }

    // CA15b (Q12)
    public function test_guarda_quien_ingreso_cada_fila(): void
    {
        $una = $this->reserva();
        $otra = $this->reserva();
        $compra = $this->compra();
        $primero = (int) auth('api')->id();
        $this->asignada($compra, [$this->aReserva($una, '600'), $this->aReserva($otra, '300'), $this->aGeneral('100')]);

        $segundo = $this->actuarComoVendedorSinVerTodas([]);
        $filas = $this->asignada($compra, [$this->aReserva($una, '600'), $this->aReserva($otra, '250'), $this->aSalida($this->salidaDe($una), '150')]);

        $usuarios = CompraAsignacion::where('compra_id', $compra)->get()
            ->mapWithKeys(fn (CompraAsignacion $a) => [$a->claveDestino() => $a->user_id])->all();
        $this->assertSame([
            "reserva:{$una->id}" => $primero,
            "reserva:{$otra->id}" => $segundo->id,
            "salida:{$this->salidaDe($una)->id}" => $segundo->id,
        ], $usuarios);
        $this->assertNotNull($filas[0]['usuario']);
    }

    // CA16
    public function test_otro_giro(): void
    {
        $this->comoGiro('retail');
        $compra = $this->compra();

        $rutas = [
            fn () => $this->asignar($compra, [$this->aGeneral('1000')]),
            fn () => app(CompraAsignacionController::class)->destinos(new Request(['tipo' => 'reserva'])),
            fn () => $this->ficha('deReserva', 1),
            fn () => $this->ficha('deSalida', 1),
        ];
        foreach ($rutas as $ruta) {
            try {
                $ruta();
                $this->fail('Debió responder 404');
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }

        // Registrar, editar y anular siguen igual, sin la clave del reparto.
        $this->assertArrayNotHasKey('asignaciones', $this->detalle($compra));
        $editar = app(CompraController::class)->update(
            Request::create("/api/compras/{$compra}", 'POST', $this->datosCompra('900.00')), (string) $compra,
        );
        $this->assertSame(200, $editar->getStatusCode());
        $this->assertSame(200, app(CompraController::class)->anular(new Request(['motivo' => 'x']), (string) $compra)->getStatusCode());
        $this->assertFalse(app(CompraController::class)->index(new Request)->getData(true)['permite_asignar']);
    }

    // CA17
    public function test_limpieza_clasifica_la_tabla(): void
    {
        $this->assertNotContains('compra_asignaciones', app(LimpiarOperacionPrueba::class)->tablasSinClasificar());
        $borrar = LimpiarOperacionPrueba::TABLAS_A_BORRAR;
        $this->assertLessThan(array_search('reserva', $borrar, true), array_search('compra_asignaciones', $borrar, true));
        $this->assertLessThan(array_search('salidas_operativas', $borrar, true), array_search('compra_asignaciones', $borrar, true));
    }

    private function actuarComoVendedorSinVerTodas(array $permisos): User
    {
        $usuario = User::factory()->create([
            'role_id' => Role::create(['name' => 'rol-vendedor-'.uniqid(), 'guard_name' => 'api'])->id,
        ]);
        foreach ($permisos as $permiso) {
            $usuario->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'api']));
        }
        Auth::guard('api')->setUser($usuario->fresh());

        return $usuario;
    }
}
