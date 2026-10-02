<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\CondonacionEstado;
use App\Enums\Creditos\ConceptoCondonacion;
use App\Enums\Creditos\CreditoEstado;
use App\Enums\Creditos\OrigenRegistro;
use App\Enums\Creditos\PagoEstado;
use App\Enums\Creditos\TipoCorrelativo;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoCondonacion;
use App\Models\Creditos\CreditoPago;
use App\Models\User;
use App\Services\Creditos\Dto\DatosCredito;
use App\Services\Creditos\Dto\PagoHistorico;
use App\Services\Creditos\Motor\AplicadorPagos;
use App\Services\Creditos\Motor\Dto\Cronograma;
use App\Services\Creditos\Motor\Dto\ResultadoAplicacion;
use App\Services\Creditos\Motor\Enums\OrigenPago;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * "Registrar crédito existente" (00 1.12): préstamos vivos anteriores al sistema. Sin caja
 * (el dinero se movió antes), pagos históricos con origen saldo_inicial y el motor calcula la
 * mora real, que el admin puede condonar en el mismo paso.
 */
class MigracionService
{
    private const HORA_PAGO_HISTORICO = '12:00:00';

    public function __construct(
        private readonly CreditoBorradorService $borradores,
        private readonly EscritorCronograma $escritor,
        private readonly CargadorCredito $cargador,
        private readonly PersistidorReparto $persistidor,
        private readonly AplicadorPagos $aplicador,
        private readonly CorrelativoCreditoService $correlativos,
        private readonly AuditoriaCredito $auditoria,
        private readonly Reloj $reloj,
    ) {
    }

    /**
     * @param int|null $cuotasATiempo modo rápido: cuotas 1..N pagadas en su vencimiento
     * @param list<PagoHistorico> $pagosDetallados modo detallado: fecha + monto
     */
    public function migrar(DatosCredito $datos, ?int $cuotasATiempo, array $pagosDetallados, bool $condonarMora, string $clave, User $usuario): Credito
    {
        return DB::transaction(function () use ($datos, $cuotasATiempo, $pagosDetallados, $condonarMora, $clave, $usuario): Credito {
            $hoy = $this->reloj->hoy();
            if (! $datos->fechaDesembolso->esAnteriorA($hoy)) {
                throw new HttpException(422, 'Un crédito existente debe tener fecha de desembolso pasada. Para uno nuevo usa "Nuevo crédito".');
            }

            $cronograma = $this->borradores->preview($datos);
            $credito = Credito::create([
                ...$this->borradores->atributos($datos, $cronograma),
                'numero_credito' => $this->correlativos->siguiente(TipoCorrelativo::Credito),
                'estado' => CreditoEstado::Activo,
                'origen_registro' => OrigenRegistro::Migracion,
                'version_cronograma_actual' => 1,
                'registrado_por' => $usuario->id,
                'asesor_id' => $this->borradores->asesorPara($datos->clienteId, $usuario),
            ]);
            $this->escritor->guardar($credito, $cronograma, 1);

            $historicos = $cuotasATiempo !== null ? $this->aTiempo($cronograma, $cuotasATiempo) : $pagosDetallados;
            foreach ($historicos as $i => $historico) {
                if ($historico->fecha->esPosteriorA($hoy) || $historico->fecha->esAnteriorA($datos->fechaDesembolso)) {
                    throw new HttpException(422, 'Los pagos históricos deben estar entre el desembolso y hoy.');
                }
                $this->registrarHistorico($credito, $historico, "{$clave}-h{$i}", $usuario);
            }

            $carga = $this->cargador->cargar($credito);
            $resultado = $this->aplicador->aplicar($carga->estado, $carga->pagos, $hoy);
            $this->persistidor->persistir($credito, $carga, $resultado);

            if ($condonarMora) {
                $this->condonarMoraHistorica($credito, $resultado, $usuario);
            }
            $this->auditoria->registrar('credito.migrar', $credito, $credito->id, null, [
                'pagos_historicos' => count($historicos), 'mora_condonada' => $condonarMora,
            ], null, $usuario);

            return $credito->refresh();
        });
    }

    /** @return list<PagoHistorico> */
    private function aTiempo(Cronograma $cronograma, int $cuotas): array
    {
        if ($cuotas < 0 || $cuotas > count($cronograma->cuotas)) {
            throw new HttpException(422, 'La cantidad de cuotas pagadas no coincide con el cronograma.');
        }

        return array_map(
            static fn ($cuota): PagoHistorico => new PagoHistorico($cuota->fechaVencimiento, $cuota->montoTotal),
            array_slice($cronograma->cuotas, 0, $cuotas),
        );
    }

    private function registrarHistorico(Credito $credito, PagoHistorico $historico, string $clave, User $usuario): void
    {
        CreditoPago::create([
            'credito_id' => $credito->id,
            'numero_recibo' => $this->correlativos->siguiente(TipoCorrelativo::Recibo),
            'monto_recibido' => Dinero::aSoles($historico->monto),
            // Se reaplica completo; si supera la deuda, el persistidor lo rechaza.
            'monto_aplicado' => Dinero::aSoles($historico->monto),
            'fecha_pago' => $historico->fecha->aTexto() . ' ' . self::HORA_PAGO_HISTORICO,
            'origen' => OrigenPago::SaldoInicial,
            'pagado_por_cliente_id' => $credito->cliente_id,
            'observaciones' => 'Pago anterior al sistema',
            'clave_idempotencia' => $clave,
            'estado' => PagoEstado::Valido,
            'registrado_por' => $usuario->id,
        ]);
    }

    private function condonarMoraHistorica(Credito $credito, ResultadoAplicacion $resultado, User $usuario): void
    {
        $carga = $this->cargador->cargar($credito);
        foreach ($resultado->moraAFecha as $mora) {
            if ($mora->moraPendiente <= 0) {
                continue;
            }
            CreditoCondonacion::create([
                'credito_id' => $credito->id,
                'cuota_id' => $carga->cuotasPorNumero[$mora->numeroCuota]->id,
                'concepto' => ConceptoCondonacion::Mora,
                'monto' => Dinero::aSoles($mora->moraPendiente),
                'motivo' => 'Mora histórica condonada al registrar el crédito existente',
                'estado' => CondonacionEstado::Vigente,
                'registrado_por' => $usuario->id,
            ]);
        }

        $carga = $this->cargador->cargar($credito);
        $this->persistidor->persistir($credito, $carga, $this->aplicador->aplicar($carga->estado, $carga->pagos, $this->reloj->hoy()));
    }
}
