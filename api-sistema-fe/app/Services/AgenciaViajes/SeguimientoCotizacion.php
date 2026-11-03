<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\Client\CrmAccion;
use App\Models\Client\CrmActividad;
use App\Services\HoraPeru;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

// T-053 (CRM C6a) — el seguimiento en el listado del Cotizador: la columna «Seguimiento» («Enviada
// hace 3 días · Vence mañana · llamar hoy (Ana)») y el filtro «Por seguir hoy».
//
// Los días se cuentan en días de calendario de Perú, no en horas. fecha_vencimiento es un instante
// UTC y «vigente hasta el 10/11» se guarda como el inicio del 11/11 en Perú (HoraPeru::finDiaUtc,
// límite exclusivo): su día es el de un segundo antes (diaVencimiento()). El filtro SQL sigue el
// mismo criterio.
class SeguimientoCotizacion
{
    private const SEPARADOR = ' · ';

    public function diasAviso(): int
    {
        return (int) (ConfiguracionAgencia::value('dias_aviso_vencimiento_cotizacion') ?? 2);
    }

    // Cotizaciones abiertas con una acción pendiente para hoy o atrasada, o con una alternativa
    // enviada que vence dentro de $diasAviso días (o ya venció). Lo más urgente primero; el
    // controller desempata por id desc.
    public function aplicarFiltroPorSeguir(Builder $query, string $hoy, int $diasAviso): void
    {
        $limiteVencimiento = HoraPeru::finDiaUtc(CarbonImmutable::parse($hoy)->addDays($diasAviso)->toDateString());

        $query->abiertas()
            ->where(function (Builder $q) use ($hoy, $limiteVencimiento) {
                $q->whereExists(fn ($s) => $this->accionesPendientes($s)->where('crm_acciones.fecha', '<=', $hoy))
                    ->orWhereExists(fn ($s) => $this->enviadasConVencimiento($s)->where('alternativas.fecha_vencimiento', '<=', $limiteVencimiento));
            });

        // LEAST ignora los NULL: la fecha de la acción pendiente más vieja o el día Perú del
        // vencimiento más próximo, la que sea menor.
        $accionMasVieja = $this->accionesPendientes($query->getQuery()->newQuery())->selectRaw('MIN(crm_acciones.fecha)');
        $vencimientoMasProximo = $this->enviadasConVencimiento($query->getQuery()->newQuery())
            ->selectRaw("MIN(((alternativas.fecha_vencimiento - interval '1 second') AT TIME ZONE 'UTC' AT TIME ZONE '" . HoraPeru::ZONA . "')::date)");

        $query->orderByRaw(
            "LEAST(({$accionMasVieja->toSql()}), ({$vencimientoMasProximo->toSql()})) ASC",
            array_merge($accionMasVieja->getBindings(), $vencimientoMasProximo->getBindings())
        );
    }

    // Pone `seguimiento` en cada cotización de la página con una sola consulta de acciones (más la
    // de sus responsables). Requiere alternativas y resultadoMotivo precargados.
    public function paraPagina(Collection $cotizaciones, CarbonImmutable $hoy, int $diasAviso): void
    {
        if ($cotizaciones->isEmpty()) {
            return;
        }

        $accionPorCotizacion = CrmAccion::pendientes()
            ->where('referencia_tipo', CrmActividad::REFERENCIA_COTIZACION)
            ->whereIn('referencia_id', $cotizaciones->pluck('id'))
            ->with('responsable:id,name,surname')
            ->orderBy('fecha')
            ->orderBy('id')
            ->get()
            ->groupBy('referencia_id')
            ->map(fn (Collection $acciones) => $acciones->first());

        foreach ($cotizaciones as $cotizacion) {
            $cotizacion->seguimiento = $this->texto($cotizacion, $accionPorCotizacion->get($cotizacion->id), $hoy, $diasAviso);
        }
    }

    /**
     * Texto de la columna y si va en rojo. Pura: usa las relaciones ya cargadas.
     *
     * @return array{texto: string, urgente: bool}
     */
    public function texto(Cotizacion $c, ?CrmAccion $accion, CarbonImmutable $hoy, int $diasAviso): array
    {
        $hoy = $hoy->startOfDay();
        $estado = $c->estadoResumen();

        if ($estado === 'perdida') {
            $motivo = $c->resultadoMotivo?->nombre;

            return ['texto' => $motivo ? "Perdida: {$motivo}" : 'Perdida', 'urgente' => false];
        }
        if (in_array($estado, ['reservada', 'anulada'], true)) {
            return ['texto' => 'Ganada', 'urgente' => false];
        }

        $partes = [];
        $urgente = false;
        $enviadas = $c->alternativas->where('estado', 'enviada');

        $ultimoEnvio = $enviadas->pluck('fecha_envio')->filter()->max();
        if ($ultimoEnvio) {
            $dias = $this->diasDesde($hoy, HoraPeru::deUtc($ultimoEnvio)->startOfDay());
            $partes[] = match ($dias) {
                0 => 'Enviada hoy',
                1 => 'Enviada ayer',
                default => "Enviada hace {$dias} días",
            };
        }

        $diasVencimiento = $enviadas
            ->filter(fn (Alternativa $a) => $a->fecha_vencimiento !== null)
            ->map(fn (Alternativa $a) => [
                'vencida' => $a->fecha_vencimiento->isPast(),
                'dias' => $this->diasEntre($hoy, $this->diaVencimiento($a)),
            ]);
        if ($diasVencimiento->contains(fn ($v) => $v['dias'] <= 0)) {
            $urgente = true;
        }

        if ($estado === 'vencida') {
            $dias = -$diasVencimiento->max('dias');
            $partes[] = match ($dias) {
                0 => 'Vencida hoy',
                1 => 'Vencida ayer',
                default => "Vencida hace {$dias} días",
            };
        } else {
            $proxima = $diasVencimiento->where('vencida', false)->min('dias');
            if ($proxima !== null && $proxima <= $diasAviso) {
                $partes[] = match ($proxima) {
                    0 => 'Vence hoy',
                    1 => 'Vence mañana',
                    default => "Vence en {$proxima} días",
                };
            }
        }

        if ($accion) {
            $partes[] = $this->textoAccion($accion, $hoy);
            if ($this->diasEntre($hoy, CarbonImmutable::parse($accion->fecha->toDateString())) <= 0) {
                $urgente = true;
            }
        } elseif ($estado === 'borrador') {
            $partes[] = 'Sin enviar';
        }

        $texto = implode(self::SEPARADOR, $partes);

        return ['texto' => $texto === '' ? '' : mb_strtoupper(mb_substr($texto, 0, 1)) . mb_substr($texto, 1), 'urgente' => $urgente];
    }

    private function textoAccion(CrmAccion $accion, CarbonImmutable $hoy): string
    {
        $verbo = mb_strtolower($accion->etiquetaTipo());
        $fecha = CarbonImmutable::parse($accion->fecha->toDateString());
        $dias = $this->diasEntre($hoy, $fecha);

        $cuando = match (true) {
            $dias === 0 => "{$verbo} hoy",
            $dias === 1 => "{$verbo} mañana",
            $dias > 1 => "{$verbo} el " . $fecha->format('d/m'),
            $dias === -1 => "{$verbo}, atrasada 1 día",
            default => "{$verbo}, atrasada " . (-$dias) . ' días',
        };

        $nombre = trim(explode(' ', trim((string) $accion->responsable?->name))[0]);

        return $nombre !== '' ? "{$cuando} ({$nombre})" : $cuando;
    }

    // Día de calendario de Perú en que vence: el del último segundo de vigencia.
    private function diaVencimiento(Alternativa $a): CarbonImmutable
    {
        return HoraPeru::deUtc($a->fecha_vencimiento)->subSecond()->startOfDay();
    }

    // Días de calendario de $hoy a $dia (negativo si $dia ya pasó). Se comparan las fechas como
    // texto: los dos lados son días de Perú y no deben pasar por otra zona.
    private function diasEntre(CarbonImmutable $hoy, CarbonImmutable $dia): int
    {
        $desde = CarbonImmutable::parse($hoy->toDateString(), 'UTC');
        $hasta = CarbonImmutable::parse($dia->toDateString(), 'UTC');
        $dias = (int) $desde->diffInDays($hasta, absolute: true);

        return $hasta->lessThan($desde) ? -$dias : $dias;
    }

    private function diasDesde(CarbonImmutable $hoy, CarbonImmutable $dia): int
    {
        return max(0, -$this->diasEntre($hoy, $dia));
    }

    private function accionesPendientes($query)
    {
        return $query->from('crm_acciones')
            ->where('crm_acciones.referencia_tipo', CrmActividad::REFERENCIA_COTIZACION)
            ->whereColumn('crm_acciones.referencia_id', 'cotizaciones.id')
            ->whereNull('crm_acciones.hecha_en');
    }

    private function enviadasConVencimiento($query)
    {
        return $query->from('alternativas')
            ->whereColumn('alternativas.cotizacion_id', 'cotizaciones.id')
            ->where('alternativas.estado', 'enviada')
            ->whereNotNull('alternativas.fecha_vencimiento');
    }
}
