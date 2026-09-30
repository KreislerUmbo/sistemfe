<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\CreditoEstado;
use App\Models\Creditos\Credito;
use App\Models\User;
use App\Services\Creditos\Dto\DatosCredito;
use App\Services\Creditos\Motor\Dto\Cronograma;
use App\Services\Creditos\Motor\Dto\Frecuencia;
use App\Services\Creditos\Motor\Fecha;
use App\Services\Creditos\Motor\GeneradorCronograma;
use App\Services\Creditos\Motor\Tasa;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Borradores (00 1.9: edición libre). El borrador no guarda cuotas: el cronograma se genera
 * en vivo para el preview y se persiste recién al activar, así editar nunca deja cuotas
 * huérfanas ni hay nada que borrar.
 */
class CreditoBorradorService
{
    public function __construct(
        private readonly GeneradorCronograma $generador,
        private readonly CargadorCredito $cargador,
    ) {
    }

    public function preview(DatosCredito $datos): Cronograma
    {
        return $this->generador->generar($this->cargador->condiciones($datos));
    }

    public function crear(DatosCredito $datos, User $usuario): Credito
    {
        $cronograma = $this->preview($datos);

        // refresh(): el modelo recién creado no trae los defaults de la BD (origen_registro, versión…).
        return Credito::create([
            ...$this->atributos($datos, $cronograma),
            'estado' => CreditoEstado::Borrador,
            'registrado_por' => $usuario->id,
        ])->refresh();
    }

    public function actualizar(Credito $credito, DatosCredito $datos): Credito
    {
        if ($credito->estado !== CreditoEstado::Borrador) {
            throw new HttpException(422, 'Solo se puede editar un crédito en borrador. Para uno activo usa "Corregir".');
        }
        $credito->update($this->atributos($datos, $this->preview($datos)));

        return $credito->refresh();
    }

    /** Columnas de condiciones de `creditos` a partir de los datos validados (también para corregir, renovar y migrar). */
    public function atributos(DatosCredito $d, Cronograma $cronograma): array
    {
        return [
            'cliente_id' => $d->clienteId,
            'monto_capital' => Dinero::aSoles($d->montoCapital),
            'tasa_interes' => $d->tasa->aTexto(),
            'unidad_tasa' => $d->unidadTasa,
            'interes_total' => Dinero::aSoles($cronograma->interesTotal),
            'frecuencia_unidad' => $d->frecuencia->unidad,
            'frecuencia_intervalo' => $d->frecuencia->intervalo,
            'dias_quincena' => $d->frecuencia->diasQuincena,
            'dias_no_laborables' => $d->diasNoLaborables,
            'saltar_feriados' => $d->saltarFeriados,
            'regla_no_laborable' => $d->reglaNoLaborable,
            'mora_cuenta_no_laborables' => $d->moraCuentaNoLaborables,
            'numero_cuotas' => $d->numeroCuotas,
            'fecha_desembolso' => $d->fechaDesembolso->aTexto(),
            // El pedido (null = automático), no la fecha ajustada: es el ancla al regenerar.
            'fecha_primer_vencimiento' => $d->fechaPrimerVencimiento?->aTexto(),
            'payment_method_id' => $d->paymentMethodId,
            'tasa_interes_minimo' => $d->tasaInteresMinimo->aTexto(),
            'dias_gracia' => $d->diasGracia,
            'paso_redondeo' => Dinero::aSoles($d->pasoRedondeo),
            'tope_mora_tipo' => $d->topeMoraTipo,
            'tope_mora_valor' => $d->topeMoraValor,
        ];
    }

    /** Datos guardados de un crédito, para regenerar su cronograma (activar). */
    public function datosDe(Credito $c): DatosCredito
    {
        return new DatosCredito(
            $c->cliente_id,
            Dinero::aCentavos($c->monto_capital),
            Tasa::desdeTexto($c->tasa_interes),
            $c->unidad_tasa,
            new Frecuencia($c->frecuencia_unidad, $c->frecuencia_intervalo, $c->dias_quincena),
            $c->numero_cuotas,
            Fecha::desdeTexto($c->fecha_desembolso->format('Y-m-d')),
            $c->fecha_primer_vencimiento === null ? null : Fecha::desdeTexto($c->fecha_primer_vencimiento->format('Y-m-d')),
            $c->dias_no_laborables,
            $c->saltar_feriados,
            $c->regla_no_laborable,
            $c->mora_cuenta_no_laborables,
            Tasa::desdeTexto($c->tasa_interes_minimo),
            $c->dias_gracia,
            $c->tope_mora_tipo,
            $c->tope_mora_valor,
            Dinero::aCentavos($c->paso_redondeo),
            $c->payment_method_id,
        );
    }
}
