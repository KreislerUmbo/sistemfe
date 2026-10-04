<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\AccionMoraReprogramacion;
use App\Enums\Creditos\CargoEstado;
use App\Enums\Creditos\CondonacionEstado;
use App\Enums\Creditos\ConceptoCondonacion;
use App\Enums\Creditos\CreditoEstado;
use App\Enums\Creditos\TipoCargo;
use App\Models\Creditos\Credito;
use App\Models\Creditos\CreditoCargo;
use App\Models\Creditos\CreditoCondonacion;
use App\Models\Creditos\CreditoConfiguracion;
use App\Models\Creditos\CreditoReprogramacion;
use App\Models\Creditos\CreditoReprogramacionCuota;
use App\Models\User;
use App\Services\Creditos\Dto\CambioFecha;
use App\Services\Creditos\Dto\PreviewReprogramacion;
use App\Services\Creditos\Dto\SolicitudReprogramacion;
use App\Services\Creditos\Motor\AplicadorPagos;
use App\Services\Creditos\Motor\CalculadoraInteres;
use App\Services\Creditos\Motor\CalendarioLaborable;
use App\Services\Creditos\Motor\Dto\CuotaVigente;
use App\Services\Creditos\Motor\Enums\EstadoCuota;
use App\Services\Creditos\Motor\GeneradorCronograma;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Reprogramar fechas sin cambiar montos (00 1.9). La fecha cambia en la misma cuota
 * (historial aparte); la mora ya generada de cuotas vencidas se congela o se condona; el
 * cargo opcional se suma a la primera cuota reprogramada.
 */
class ReprogramacionService
{
    public function __construct(
        private readonly CargadorCredito $cargador,
        private readonly PersistidorReparto $persistidor,
        private readonly AplicadorPagos $aplicador,
        private readonly GeneradorCronograma $generador,
        private readonly CalculadoraInteres $interes,
        private readonly AlcanceCartera $alcance,
        private readonly AuditoriaCredito $auditoria,
        private readonly Reloj $reloj,
    ) {
    }

    public function preview(Credito $credito, SolicitudReprogramacion $s, User $usuario): PreviewReprogramacion
    {
        $this->alcance->asegurar($credito, $usuario);
        if ($credito->estado !== CreditoEstado::Activo) {
            throw new HttpException(422, 'Solo se reprograma un crédito activo.');
        }

        $hoy = $this->reloj->hoy();
        $carga = $this->cargador->cargar($credito);
        $estado = $carga->estado;
        $resultado = $this->aplicador->aplicar($estado, $carga->pagos, $hoy);
        $pagadas = array_values(array_map(
            static fn ($c): int => $c->numero,
            array_filter($resultado->cuotas, static fn ($c): bool => $c->estado === EstadoCuota::Pagada),
        ));

        $nuevas = $s->esDesplazamiento()
            ? $this->generador->desplazarFechas($estado->cuotas, $s->desdeCuota, (int) $s->dias, $hoy, $pagadas)
            : $this->generador->aplicarFechas($estado->cuotas, $s->fechas, $hoy, $pagadas);

        $calendario = new CalendarioLaborable($estado->calendario);
        $cambios = [];
        foreach ($estado->cuotas as $i => $anterior) {
            $nueva = $nuevas[$i]->fechaVencimiento;
            if ($nueva->esIgualA($anterior->fechaVencimiento)) {
                continue;
            }
            $vencida = $anterior->fechaVencimiento->esAnteriorA($hoy);
            $cambios[] = new CambioFecha(
                $anterior->numero,
                $anterior->fechaVencimiento,
                $nueva,
                $vencida ? $resultado->mora($anterior->numero)->moraGenerada : 0,
                ! $calendario->esLaborable($nueva),
            );
        }
        if ($cambios === []) {
            throw new HttpException(422, 'La reprogramación no cambia ninguna fecha.');
        }

        $moraAcumulada = array_sum(array_map(static fn (CambioFecha $c): int => $c->moraCongelada, $cambios));
        $config = CreditoConfiguracion::actual();
        $ultima = $estado->cuotas[array_key_last($estado->cuotas)];
        $sugerido = $this->interes->cargoReprogramacion(
            $config->cargo_reprogramacion_tipo,
            Dinero::aCentavos($config->cargo_reprogramacion_monto),
            $estado->interesTotal,
            CargadorCredito::fecha($credito->fecha_desembolso)->diasHasta($ultima->fechaVencimientoOriginal),
            max(0, $ultima->fechaVencimiento->diasHasta($nuevas[array_key_last($nuevas)]->fechaVencimiento)),
            $estado->pasoRedondeo,
        );

        return new PreviewReprogramacion(
            $cambios,
            $moraAcumulada,
            $moraAcumulada === 0 ? AccionMoraReprogramacion::NoAplica : $s->accionMora,
            $config->cargo_reprogramacion_tipo,
            $sugerido,
            max(0, $s->cargo ?? $sugerido),
        );
    }

    public function reprogramar(Credito $credito, SolicitudReprogramacion $s, User $usuario): CreditoReprogramacion
    {
        return DB::transaction(function () use ($credito, $s, $usuario): CreditoReprogramacion {
            $credito = Credito::whereKey($credito->id)->lockForUpdate()->firstOrFail();
            $preview = $this->preview($credito, $s, $usuario);
            $cuotas = $credito->cuotasVigentes()->get()->keyBy('numero_cuota');

            $reprogramacion = CreditoReprogramacion::create([
                'credito_id' => $credito->id,
                'motivo' => $s->motivo,
                'cargo_tipo' => $preview->cargoTipo,
                'cargo_monto' => Dinero::aSoles($preview->cargo),
                'accion_mora' => $preview->accionMora,
                'registrado_por' => $usuario->id,
            ]);

            foreach ($preview->cambios as $cambio) {
                $cuota = $cuotas[$cambio->numeroCuota];
                CreditoReprogramacionCuota::create([
                    'reprogramacion_id' => $reprogramacion->id,
                    'cuota_id' => $cuota->id,
                    'fecha_anterior' => $cambio->fechaAnterior->aTexto(),
                    'fecha_nueva' => $cambio->fechaNueva->aTexto(),
                    'mora_congelada' => Dinero::aSoles($cambio->moraCongelada),
                ]);
                $cuota->update([
                    'fecha_vencimiento' => $cambio->fechaNueva->aTexto(),
                    'mora_congelada' => Dinero::aSoles(Dinero::aCentavos($cuota->mora_congelada) + $cambio->moraCongelada),
                ]);
                // Condonar = congelar y condonar lo mismo: queda en el historial y la deuda neta es 0.
                if ($preview->accionMora === AccionMoraReprogramacion::Condonar && $cambio->moraCongelada > 0) {
                    CreditoCondonacion::create([
                        'credito_id' => $credito->id, 'cuota_id' => $cuota->id, 'concepto' => ConceptoCondonacion::Mora,
                        'monto' => Dinero::aSoles($cambio->moraCongelada), 'motivo' => "Reprogramación: {$s->motivo}",
                        'estado' => CondonacionEstado::Vigente, 'registrado_por' => $usuario->id,
                    ]);
                }
            }

            if ($preview->cargo > 0) {
                $primera = $cuotas[$preview->cambios[0]->numeroCuota];
                CreditoCargo::create([
                    'credito_id' => $credito->id, 'cuota_id' => $primera->id, 'tipo' => TipoCargo::Reprogramacion,
                    'monto' => Dinero::aSoles($preview->cargo), 'reprogramacion_id' => $reprogramacion->id,
                    'estado' => CargoEstado::Vigente, 'registrado_por' => $usuario->id,
                ]);
                $primera->update(['cargo_monto' => Dinero::aSoles(Dinero::aCentavos($primera->cargo_monto) + $preview->cargo)]);
            }

            $carga = $this->cargador->cargar($credito);
            $this->persistidor->persistir($credito, $carga, $this->aplicador->aplicar($carga->estado, $carga->pagos, $this->reloj->hoy()));
            $this->auditoria->registrar('credito.reprogramar', $reprogramacion, $credito->id, null, [
                'cuotas' => array_map(static fn (CambioFecha $c): array => [$c->numeroCuota, $c->fechaAnterior->aTexto(), $c->fechaNueva->aTexto()], $preview->cambios),
                'cargo' => Dinero::aSoles($preview->cargo),
                'accion_mora' => $preview->accionMora->value,
            ], $s->motivo, $usuario);

            return $reprogramacion;
        });
    }
}
