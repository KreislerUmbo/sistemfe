<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\CrmMotivoResultado;
use App\Models\Client\CrmActividad;
use App\Models\User;
use App\Services\Crm\AccionesCrm;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * T-052 (CRM C3) — «Cerrar como perdida» y «Reabrir» una cotización. Las dos bloquean la fila de
 * la cotización (mismo orden que ReservaController::aceptar(), que también la bloquea) y vuelven a
 * revisar el estado con el lock tomado: dos pestañas no la cierran dos veces ni la cierran mientras
 * otra la acepta.
 *
 * Al cerrar, las alternativas en borrador o enviada pasan a descartada y guardan su estado en
 * estado_antes_de_perdida; al reabrir vuelven a ese estado (decisión del usuario, 02-nov-2026). Las
 * que ya estaban descartadas no se tocan. Las fechas de envío y vencimiento nunca se borran.
 */
class ResultadoCotizacionService
{
    private const ESTADOS_QUE_SE_DESCARTAN = ['borrador', 'enviada'];

    public function __construct(private readonly AccionesCrm $acciones)
    {
    }

    /** Motivo válido para guardar: existe, está activo y es del tipo pedido. */
    public static function reglaMotivo(string $tipo): Exists
    {
        return Rule::exists('crm_motivos_resultado', 'id')->where('tipo', $tipo)->where('activo', true);
    }

    public function cerrarComoPerdida(Cotizacion $cotizacion, User $usuario, CrmMotivoResultado $motivo, ?string $detalle): Cotizacion
    {
        return DB::transaction(function () use ($cotizacion, $usuario, $motivo, $detalle) {
            $bloqueada = Cotizacion::whereKey($cotizacion->id)->lockForUpdate()->firstOrFail();

            if ($bloqueada->estaPerdida()) {
                throw new HttpException(422, 'Esta cotización ya está cerrada como perdida.');
            }
            if ($bloqueada->esModificacion()) {
                throw new HttpException(422, 'Una modificación de reserva no se cierra como perdida: se descarta desde la reserva.');
            }
            $vendida = Alternativa::where('cotizacion_id', $bloqueada->id)
                ->whereIn('estado', ['aceptada', Alternativa::ESTADO_REEMPLAZADA])
                ->exists();
            if ($vendida) {
                throw new HttpException(422, 'Esta cotización ya tiene una alternativa aceptada: no se puede cerrar como perdida.');
            }

            // Postgres lee 'estado' con su valor anterior en todo el SET: se guarda y se cambia de una vez.
            Alternativa::where('cotizacion_id', $bloqueada->id)
                ->whereIn('estado', self::ESTADOS_QUE_SE_DESCARTAN)
                ->update(['estado_antes_de_perdida' => DB::raw('estado'), 'estado' => 'descartada']);

            $bloqueada->update([
                'resultado_motivo_id' => $motivo->id,
                'resultado_detalle' => $detalle,
                'perdida_en' => now(),
                'perdida_por' => $usuario->id,
            ]);

            $this->registrarActividad($bloqueada, $usuario, 'Cotización perdida: ' . $motivo->nombre . ($detalle ? " — {$detalle}" : ''));
            $this->acciones->cerrarPendientesDeCotizacion($bloqueada->id, 'Cotización perdida');

            return $bloqueada;
        });
    }

    public function reabrir(Cotizacion $cotizacion, User $usuario): Cotizacion
    {
        return DB::transaction(function () use ($cotizacion, $usuario) {
            $bloqueada = Cotizacion::whereKey($cotizacion->id)->lockForUpdate()->firstOrFail();

            if (! $bloqueada->estaPerdida()) {
                throw new HttpException(422, 'Esta cotización no está cerrada como perdida.');
            }

            Alternativa::where('cotizacion_id', $bloqueada->id)
                ->whereNotNull('estado_antes_de_perdida')
                ->update(['estado' => DB::raw('estado_antes_de_perdida'), 'estado_antes_de_perdida' => null]);

            $bloqueada->update([
                'resultado_motivo_id' => null,
                'resultado_detalle' => null,
                'perdida_en' => null,
                'perdida_por' => null,
            ]);

            $this->registrarActividad($bloqueada, $usuario, 'Cotización reabierta');

            return $bloqueada;
        });
    }

    private function registrarActividad(Cotizacion $cotizacion, User $usuario, string $nota): void
    {
        CrmActividad::create([
            'cliente_id' => $cotizacion->cliente_id,
            'referencia_tipo' => CrmActividad::REFERENCIA_COTIZACION,
            'referencia_id' => $cotizacion->id,
            'tipo' => CrmActividad::TIPO_ESTADO,
            'nota' => $nota,
            'usuario_id' => $usuario->id,
        ]);
    }
}
