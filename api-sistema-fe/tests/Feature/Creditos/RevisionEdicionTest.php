<?php

declare(strict_types=1);

namespace Tests\Feature\Creditos;

use App\Enums\Creditos\AccionMoraReprogramacion;
use App\Enums\Creditos\CreditoEstado;
use App\Models\Cash\CashMovement;
use App\Models\Cash\PaymentMethod;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoAutorizacion;
use App\Models\User;
use App\Services\Creditos\CobroService;
use App\Services\Creditos\CondonacionService;
use App\Services\Creditos\CorreccionService;
use App\Services\Creditos\Dto\DatosCredito;
use App\Services\Creditos\Dto\SolicitudCobro;
use App\Services\Creditos\Dto\SolicitudReprogramacion;
use App\Services\Creditos\LimitesExcedidos;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use App\Services\Creditos\Motor\Fecha;
use App\Services\Creditos\ReprogramacionService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Revisión 03-oct-2026 de los flujos que cambian un crédito: cada caso reprodujo un problema
 * real (Corregir borraba reprogramaciones/condonaciones, saltaba límites, descuadraba la caja por
 * método y aceptaba cualquier fecha de desembolso; el cobro retroactivo aceptaba fechas antes del
 * desembolso o de una reprogramación).
 */
final class RevisionEdicionTest extends CreditosTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->usuario();
        $this->abrirCaja($this->admin);
    }

    private function corregir(Credito $credito, DatosCredito $datos, ?User $usuario = null): Credito
    {
        return app(CorreccionService::class)->corregir($credito, $datos, 'Revisión', $usuario ?? $this->admin);
    }

    private function esperarError(callable $accion, string $mensaje, int $estado = 422): void
    {
        try {
            $accion();
            $this->fail("Se esperaba un error que contenga: {$mensaje}");
        } catch (HttpException $e) {
            $this->assertSame($estado, $e->getStatusCode());
            $this->assertStringContainsString($mensaje, $e->getMessage());
        }
    }

    /** Mismos datos de ejemplo con otro método de pago. */
    private function conMetodo(DatosCredito $d, int $metodo): DatosCredito
    {
        $args = [];
        foreach ((new \ReflectionClass($d))->getConstructor()->getParameters() as $p) {
            $args[$p->getName()] = $p->getName() === 'paymentMethodId' ? $metodo : $d->{$p->getName()};
        }

        return new DatosCredito(...$args);
    }

    // ── 1-2: Corregir no borra reprogramaciones, condonaciones ni cargos ──

    public function test_no_corrige_un_credito_reprogramado_pero_si_se_puede_anular(): void
    {
        $credito = $this->activo($this->admin);
        $this->hoy('2026-01-10');
        app(ReprogramacionService::class)->reprogramar($credito, new SolicitudReprogramacion(1, 15, [], AccionMoraReprogramacion::NoAplica, 2_000, 'Viaje', 'rev-rep-1'), $this->admin);

        $this->esperarError(fn () => $this->corregir($credito, $this->datos($credito->cliente_id, tasa: '25')), 'reprogramaciones, cargos registrados');
        $this->assertSame('2026-02-15', $credito->fresh()->cuotasVigentes()->first()->fecha_vencimiento->format('Y-m-d'));   // intacta

        $this->assertSame(CreditoEstado::Anulado, app(CorreccionService::class)->anular($credito, 'Rehacer', $this->admin)->estado);
    }

    public function test_no_corrige_un_credito_con_mora_condonada(): void
    {
        $credito = $this->activo($this->admin);
        $this->hoy('2026-02-15');
        app(CondonacionService::class)->condonar($credito, $credito->cuotasVigentes()->first(), 1_000, 'Buen cliente', $this->admin);

        $this->esperarError(fn () => $this->corregir($credito, $this->datos($credito->cliente_id, tasa: '25')), 'condonaciones de mora');
        $this->assertSame(1, $credito->fresh()->version_cronograma_actual);
    }

    // ── 3: límites al subir el capital ──

    public function test_subir_el_capital_respeta_los_limites_y_autoriza_con_permiso(): void
    {
        DB::table('credito_configuracion')->update(['deuda_maxima_cliente' => '6000.00']);
        $credito = $this->activo($this->admin);

        $sinPermiso = $this->usuario(array_values(array_diff(self::TODOS_LOS_PERMISOS, ['creditos.autorizar_excepcion'])));
        $this->abrirCaja($sinPermiso);
        try {
            $this->corregir($credito, $this->datos($credito->cliente_id, capital: 5_000_000), $sinPermiso);
            $this->fail('Subir el capital por encima del tope debía exigir autorización.');
        } catch (LimitesExcedidos $e) {
            $this->assertSame(['deuda_maxima'], array_column($e->detalle(), 'regla'));
        }
        $this->assertSame('5000.00', (string) $credito->fresh()->monto_capital);

        // Con el permiso se autoriza en el mismo acto, con el motivo de la corrección.
        $corregido = $this->corregir($credito, $this->datos($credito->cliente_id, capital: 5_000_000));
        $this->assertSame('50000.00', (string) $corregido->monto_capital);
        $autorizacion = CreditoAutorizacion::where('credito_id', $credito->id)->sole();
        $this->assertSame(['deuda_maxima', 'Corrección: Revisión'], [$autorizacion->regla->value, $autorizacion->motivo]);
    }

    public function test_bajar_el_capital_no_pide_autorizacion_aunque_el_cliente_exceda_limites(): void
    {
        $credito = $this->activo($this->admin);
        DB::table('credito_configuracion')->update(['deuda_maxima_cliente' => '1000.00']);   // el tope bajó después

        $this->assertSame('4000.00', (string) $this->corregir($credito, $this->datos($credito->cliente_id, capital: 400_000))->monto_capital);
        $this->assertSame(0, CreditoAutorizacion::where('credito_id', $credito->id)->count());
    }

    // ── 4: caja por método de pago ──

    public function test_cambiar_solo_el_metodo_de_pago_rehace_el_desembolso_en_caja(): void
    {
        $credito = $this->activo($this->admin);
        $yape = PaymentMethod::firstOrCreate(['code' => 'YAPE'], ['name' => 'Yape', 'is_active' => true, 'sort_order' => 2, 'affects_cash_count' => true]);

        $this->corregir($credito, $this->conMetodo($this->datos($credito->cliente_id), $yape->id));

        $vigente = CashMovement::where('reference_type', 'credito_desembolso')->where('reference_id', $credito->id)->where('type', 'credito_desembolso')->whereNull('corrected_by')->sole();
        $this->assertSame([$yape->id, '5000.00'], [$vigente->payment_method_id, (string) $vigente->amount]);
        $this->assertSame($yape->id, $credito->fresh()->payment_method_id);
    }

    // ── 5: fecha de desembolso ──

    public function test_fecha_de_desembolso_solo_se_adelanta_unos_dias_con_permiso(): void
    {
        $this->hoy('2026-01-05');
        $credito = $this->activo($this->admin, null, $this->datos($this->cliente()->id, desembolso: '2026-01-05'));
        $datos = fn (string $fecha) => $this->datos($credito->cliente_id, desembolso: $fecha);

        $this->esperarError(fn () => $this->corregir($credito, $datos('2026-01-06')), 'no puede ser posterior al día en que se entregó el dinero (05/01/2026)');
        $this->esperarError(fn () => $this->corregir($credito, $datos('2026-01-01')), 'hasta 3 día(s) antes');   // 4 días: más que el máximo
        $sinPermiso = $this->usuario(array_values(array_diff(self::TODOS_LOS_PERMISOS, ['creditos.pago_fecha_anterior'])));
        $this->esperarError(fn () => $this->corregir($credito, $datos('2026-01-03'), $sinPermiso), 'creditos.pago_fecha_anterior', 403);

        $corregido = $this->corregir($credito, $datos('2026-01-02'));   // entregado el viernes, registrado el lunes
        $this->assertSame('2026-01-02', $corregido->fecha_desembolso->format('Y-m-d'));
        // La caja no se mueve: el desembolso sigue siendo el original (no cambió monto ni método).
        $this->assertSame(1, CashMovement::where('reference_type', 'credito_desembolso')->where('reference_id', $credito->id)->count());

        // El límite se mide desde la entrega real, no desde la fecha ya corregida.
        $this->esperarError(fn () => $this->corregir($credito, $datos('2026-01-01')), 'hasta 3 día(s) antes');
    }

    // ── 6-7: cobro retroactivo ──

    public function test_pago_retroactivo_no_puede_ser_anterior_al_desembolso(): void
    {
        $this->hoy('2026-01-05');
        $credito = $this->activo($this->admin, null, $this->datos($this->cliente()->id, desembolso: '2026-01-05'));

        $this->esperarError(fn () => app(CobroService::class)->cobrar($credito, new SolicitudCobro(
            60_000, DestinoExcedente::Devolver, $this->efectivo->id, 'rev-retro-1', fechaPago: Fecha::desdeTexto('2026-01-03'), motivoRetroactivo: 'Llegó tarde',
        ), $this->admin), 'anterior al desembolso del crédito (05/01/2026)');
    }

    public function test_pago_retroactivo_no_puede_ser_anterior_a_una_reprogramacion(): void
    {
        $credito = $this->activo($this->admin);
        $this->hoy('2026-02-05');
        app(ReprogramacionService::class)->reprogramar($credito, new SolicitudReprogramacion(1, 20, [], AccionMoraReprogramacion::Mantener, 0, 'Reprog', 'rev-rep-2'), $this->admin);
        $cobrar = fn (string $fecha, string $clave) => app(CobroService::class)->cobrar($credito, new SolicitudCobro(
            60_000, DestinoExcedente::Devolver, $this->efectivo->id, $clave, fechaPago: Fecha::desdeTexto($fecha), motivoRetroactivo: 'Se cobró antes',
        ), $this->admin);

        $this->hoy('2026-02-07');
        $this->esperarError(fn () => $cobrar('2026-02-04', 'rev-retro-2'), 'anterior a la reprogramación del 05/02/2026');
        $this->assertSame('600.00', (string) $cobrar('2026-02-05', 'rev-retro-3')->monto_aplicado);   // el mismo día sí
    }
}
