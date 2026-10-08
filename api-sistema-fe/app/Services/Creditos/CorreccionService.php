<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\CargoEstado;
use App\Enums\Creditos\CondonacionEstado;
use App\Enums\Creditos\CreditoEstado;
use App\Enums\Creditos\OrigenRegistro;
use App\Enums\Creditos\PagoEstado;
use App\Models\Cash\CashMovement;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoAutorizacion;
use App\Models\Creditos\CreditoCargo;
use App\Models\Creditos\CreditoCondonacion;
use App\Models\Creditos\CreditoConfiguracion;
use App\Models\Creditos\CreditoPago;
use App\Models\Creditos\CreditoReprogramacion;
use App\Models\User;
use App\Services\Creditos\Dto\DatosCredito;
use App\Services\Creditos\Motor\AplicadorPagos;
use App\Services\Creditos\Motor\Dto\Infraccion;
use App\Services\Creditos\Motor\Enums\OrigenPago;
use App\Services\Creditos\Motor\Fecha;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Corregir y anular un crédito (00 1.9). Solo sin pagos válidos: con pagos, primero se anulan.
 * Corregir tampoco procede con reprogramaciones, condonaciones o cargos (quedarían colgados de
 * cuotas anuladas), valida los límites si sube el capital y solo adelanta la fecha de desembolso
 * unos días (revisión 03-oct-2026).
 * Anular un crédito de renovación deshace la renovación: revierte la salida neta y reabre el
 * crédito anterior (03-api decisión 2).
 */
class CorreccionService
{
    private const CONDICIONES_AUDITADAS = [
        'monto_capital', 'tasa_interes', 'unidad_tasa', 'interes_total', 'frecuencia_unidad', 'frecuencia_intervalo',
        'numero_cuotas', 'fecha_desembolso', 'fecha_primer_vencimiento', 'tasa_interes_minimo', 'dias_gracia', 'cobra_mora',
    ];

    private const PERMISO_AUTORIZAR = 'creditos.autorizar_excepcion';
    private const PERMISO_FECHA_ANTERIOR = 'creditos.pago_fecha_anterior';

    public function __construct(
        private readonly CreditoBorradorService $borradores,
        private readonly LimitesService $limites,
        private readonly EscritorCronograma $escritor,
        private readonly CargadorCredito $cargador,
        private readonly PersistidorReparto $persistidor,
        private readonly AplicadorPagos $aplicador,
        private readonly CajaCredito $caja,
        private readonly AuditoriaCredito $auditoria,
        private readonly Reloj $reloj,
    ) {
    }

    public function corregir(Credito $credito, DatosCredito $datos, string $motivo, User $usuario): Credito
    {
        return DB::transaction(function () use ($credito, $datos, $motivo, $usuario): Credito {
            $credito = $this->bloquearSinPagos($credito, [CreditoEstado::Activo]);
            if ($credito->origen_registro !== OrigenRegistro::Normal) {
                throw new HttpException(422, 'Un crédito migrado o de renovación no se corrige: anúlalo y regístralo otra vez.');
            }
            $this->asegurarSinAjustes($credito);
            $antes = $credito->only(self::CONDICIONES_AUDITADAS);
            $datos = $datos->conCliente($credito->cliente_id);
            $this->validarFechaDesembolso($credito, $datos, $usuario);
            $capitalAnterior = Dinero::aCentavos($credito->monto_capital);
            $metodoAnterior = $credito->payment_method_id;
            $metodo = $datos->paymentMethodId ?? $metodoAnterior;
            // Subir el capital es otorgar más: mismos límites que al activar (bajarlo no los toca).
            $autorizar = $datos->montoCapital > $capitalAnterior ? $this->bloqueosAAutorizar($credito, $datos, $usuario) : [];
            $cronograma = $this->borradores->preview($datos);

            $version = $credito->version_cronograma_actual + 1;
            $this->escritor->anularVersion($credito, $credito->version_cronograma_actual);
            $this->escritor->guardar($credito, $cronograma, $version);
            $credito->update([
                ...$this->borradores->atributos($datos, $cronograma),
                'payment_method_id' => $metodo,
                'version_cronograma_actual' => $version,
            ]);
            foreach ($autorizar as $infraccion) {
                CreditoAutorizacion::create([
                    'credito_id' => $credito->id, 'regla' => $infraccion->regla, 'detalle' => $infraccion->detalle,
                    'motivo' => "Corrección: {$motivo}", 'autorizado_por' => $usuario->id,
                ]);
            }

            // CashCorrectionService solo revierte completo: reverso + nuevo desembolso (03-api decisión 3).
            // También si cambia el método: la caja cuadra por método de pago.
            if ($datos->montoCapital !== $capitalAnterior || $metodo !== $metodoAnterior) {
                $this->caja->revertirReferencia(CajaCredito::DESEMBOLSO, $credito->id, $usuario);
                $movimiento = $this->caja->salida(
                    $this->caja->sesionAbierta($usuario), $usuario, CajaCredito::DESEMBOLSO, $credito->id,
                    $datos->montoCapital, $metodo, $credito->cliente, 'Desembolso corregido',
                );
                $credito->update(['cash_movement_id' => $movimiento->id]);
            }

            $this->auditoria->registrar('credito.corregir', $credito, $credito->id, $antes, $credito->only(self::CONDICIONES_AUDITADAS), $motivo, $usuario);

            return $credito->refresh();
        });
    }

    public function anular(Credito $credito, string $motivo, User $usuario): Credito
    {
        return DB::transaction(function () use ($credito, $motivo, $usuario): Credito {
            $credito = $this->bloquearSinPagos($credito, [CreditoEstado::Borrador, CreditoEstado::Activo]);
            $estabaActivo = $credito->estado === CreditoEstado::Activo;

            if ($estabaActivo) {
                $this->caja->revertirReferencia(CajaCredito::DESEMBOLSO, $credito->id, $usuario);
                $this->escritor->anularVersion($credito, $credito->version_cronograma_actual);
            }
            $credito->update([
                'estado' => CreditoEstado::Anulado,
                'motivo_anulacion' => $motivo,
                'anulado_por' => $usuario->id,
                'anulado_en' => now(),
            ]);
            if ($estabaActivo && $credito->origen_registro === OrigenRegistro::Renovacion) {
                $this->reabrirRenovado($credito, $motivo, $usuario);
            }

            $this->auditoria->registrar('credito.anular', $credito, $credito->id, ['estado' => $estabaActivo ? 'activo' : 'borrador'], ['estado' => 'anulado'], $motivo, $usuario);

            return $credito->refresh();
        });
    }

    /** El pago "renovación" del crédito anterior (sin caja) se anula y el anterior vuelve a quedar abierto. */
    private function reabrirRenovado(Credito $nuevo, string $motivo, User $usuario): void
    {
        $anterior = Credito::whereKey($nuevo->credito_renovado_id)->lockForUpdate()->firstOrFail();
        $pago = CreditoPago::where('credito_id', $anterior->id)
            ->where('origen', OrigenPago::Renovacion)
            ->where('estado', PagoEstado::Valido)
            ->firstOrFail();
        $pago->update(['estado' => PagoEstado::Anulado, 'motivo_anulacion' => "Renovación anulada: {$motivo}", 'anulado_por' => $usuario->id, 'anulado_en' => now()]);
        // Si al renovar el cliente pagó la diferencia (1.21 ampliada), esa entrada de caja se revierte.
        $this->caja->revertirReferencia(CajaCredito::PAGO, $pago->id, $usuario);

        $carga = $this->cargador->cargar($anterior);
        $this->persistidor->persistir($anterior, $carga, $this->aplicador->aplicar($carga->estado, $carga->pagos, $this->reloj->hoy()));
    }

    /**
     * Reprogramaciones, condonaciones y cargos cuelgan de las cuotas de la versión vigente: al
     * regenerar el cronograma quedarían sin efecto en silencio (y la condonación seguiría sumando
     * en los reportes). Con alguno de ellos, el camino es anular y registrar de nuevo.
     */
    private function asegurarSinAjustes(Credito $credito): void
    {
        $ajustes = array_keys(array_filter([
            'reprogramaciones' => CreditoReprogramacion::where('credito_id', $credito->id)->exists(),
            'condonaciones de mora' => CreditoCondonacion::where('credito_id', $credito->id)->where('estado', CondonacionEstado::Vigente)->exists(),
            'cargos' => CreditoCargo::where('credito_id', $credito->id)->where('estado', CargoEstado::Vigente)->exists(),
        ]));
        if ($ajustes !== []) {
            throw new HttpException(422, 'El crédito tiene ' . implode(', ', $ajustes) . ' registrados y corregirlo los dejaría sin efecto. '
                . 'Anula el crédito y regístralo de nuevo con las condiciones correctas.');
        }
    }

    /**
     * El dinero salió el día de la activación: la fecha de desembolso solo puede adelantarse hasta
     * dias_max_pago_retroactivo días (entrega registrada tarde), nunca pasar de ese día. Más atrás,
     * el préstamo es anterior al sistema: anular y "Registrar crédito existente".
     */
    private function validarFechaDesembolso(Credito $credito, DatosCredito $datos, User $usuario): void
    {
        $nueva = $datos->fechaDesembolso;
        if ($nueva->esIgualA(CargadorCredito::fecha($credito->fecha_desembolso))) {
            return;
        }
        $entrega = $this->diaDeEntrega($credito);
        if ($nueva->esPosteriorA($entrega)) {
            throw new HttpException(422, 'La fecha de desembolso no puede ser posterior al día en que se entregó el dinero (' . self::fechaTexto($entrega) . ').');
        }
        $maximo = (int) CreditoConfiguracion::actual()->dias_max_pago_retroactivo;
        if ($nueva->diasHasta($entrega) > $maximo) {
            throw new HttpException(422, "Solo se puede adelantar la fecha de desembolso hasta {$maximo} día(s) antes de la entrega registrada ("
                . self::fechaTexto($entrega) . '). Si el préstamo es anterior, anula el crédito y regístralo con "Registrar crédito existente".');
        }
        if ($nueva->esAnteriorA($entrega) && ! $usuario->can(self::PERMISO_FECHA_ANTERIOR)) {
            throw new HttpException(403, 'Adelantar la fecha de desembolso requiere el permiso creditos.pago_fecha_anterior.');
        }
    }

    /** Día (Lima) del primer movimiento de desembolso del crédito; sin caja, su fecha actual. */
    private function diaDeEntrega(Credito $credito): Fecha
    {
        $primero = CashMovement::where('reference_type', CajaCredito::DESEMBOLSO)->where('reference_id', $credito->id)
            ->where('type', CajaCredito::DESEMBOLSO)->orderBy('created_at')->value('created_at');

        return $primero === null
            ? CargadorCredito::fecha($credito->fecha_desembolso)
            : Fecha::desdeTexto(\Carbon\CarbonImmutable::parse((string) $primero, 'UTC')->setTimezone(Reloj::ZONA)->format('Y-m-d'));
    }

    /**
     * Mismo criterio que la renovación: el propio crédito se excluye de la deuda y del conteo, y
     * quien tiene el permiso autoriza la excepción en el mismo acto con el motivo de la corrección.
     *
     * @return list<Infraccion>
     */
    private function bloqueosAAutorizar(Credito $credito, DatosCredito $datos, User $usuario): array
    {
        $bloqueos = $this->limites->evaluarParaOtorgar($credito->cliente_id, $datos->montoCapital, $credito->id)->bloqueos();
        if ($bloqueos === []) {
            return [];
        }
        $autorizables = array_filter($bloqueos, static fn (Infraccion $i): bool => $i->autorizable);
        if (count($autorizables) !== count($bloqueos) || ! $usuario->can(self::PERMISO_AUTORIZAR)) {
            throw new LimitesExcedidos($bloqueos);
        }

        return $bloqueos;
    }

    private static function fechaTexto(Fecha $fecha): string
    {
        [$anio, $mes, $dia] = explode('-', $fecha->aTexto());

        return "{$dia}/{$mes}/{$anio}";
    }

    /** @param list<CreditoEstado> $estados */
    private function bloquearSinPagos(Credito $credito, array $estados): Credito
    {
        $credito = Credito::whereKey($credito->id)->lockForUpdate()->firstOrFail();
        if (! in_array($credito->estado, $estados, true)) {
            throw new HttpException(422, 'Esta acción no está disponible para el estado actual del crédito.');
        }
        if ($credito->pagos()->where('estado', PagoEstado::Valido)->exists()) {
            throw new HttpException(422, 'El crédito tiene pagos válidos. Anúlalos primero.');
        }

        return $credito;
    }
}
