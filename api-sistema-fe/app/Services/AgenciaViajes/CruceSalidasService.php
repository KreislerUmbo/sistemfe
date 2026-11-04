<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\Guia;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Models\AgenciaViajes\SalidaVehiculo;
use Illuminate\Support\Collection;

// T-044 (H38) — avisa cuando un guía o un chofer ya está en otra salida del
// mismo día. Es solo un aviso: a veces es a propósito (tour de mañana y otro
// de tarde). El criterio es el día; no hay duración confiable del tour.
// Solo cuentan las salidas en curso (programada o confirmada) y nunca la
// misma salida.
class CruceSalidasService
{
    /** Teléfono comparable: solo dígitos y sin el prefijo 51 de Perú. */
    public static function normalizarTelefono(?string $telefono): ?string
    {
        $digitos = preg_replace('/\D+/', '', (string) $telefono);
        if (strlen($digitos) > 9 && str_starts_with($digitos, '51')) {
            $digitos = substr($digitos, 2);
        }

        return $digitos === '' ? null : $digitos;
    }

    /**
     * Una salida cancelada o realizada no avisa ni se marca en el tablero: ya
     * no compite por el guía ni por el chofer (tampoco cuenta como "la otra").
     */
    private function enCurso(SalidaOperativa $salida): bool
    {
        return in_array($salida->estado, SalidaOperativa::ESTADOS_EN_CURSO, true);
    }

    /**
     * Salidas en curso ya leídas, por fecha. El listado del tablero pregunta
     * por muchas salidas del mismo día: así la base se lee una vez por fecha
     * y no una vez por fila y por guía. Vive lo que vive esta instancia (un
     * request): quien quiera aprovecharlo usa la misma instancia en todo el
     * listado.
     *
     * @var array<string, Collection<int, SalidaOperativa>>
     */
    private array $salidasPorFecha = [];

    /** @var array<int, string|null> nombre de cada guía ya leído */
    private array $nombresGuia = [];

    /** @return Collection<int, SalidaOperativa> otras salidas en curso del mismo día */
    private function otrasDelDia(SalidaOperativa $salida): Collection
    {
        $fecha = $salida->fecha->toDateString();
        $this->salidasPorFecha[$fecha] ??= SalidaOperativa::enCurso()
            ->where('fecha', $fecha)
            ->with(['tourOrigen', 'vehiculos'])
            ->orderBy('hora')
            ->get();

        return $this->salidasPorFecha[$fecha]->where('id', '!=', $salida->id)->values();
    }

    private function nombreGuia(int $guiaId): string
    {
        if (! array_key_exists($guiaId, $this->nombresGuia)) {
            $this->nombresGuia[$guiaId] = Guia::whereKey($guiaId)->value('nombre');
        }

        return $this->nombresGuia[$guiaId] ?? 'El guía';
    }

    // Arma el texto con lo que haya: el tour y la hora van cada uno si existe.
    private function donde(string $quien, SalidaOperativa $otra): string
    {
        $hora = $otra->hora ? substr((string) $otra->hora, 0, 5) : null;
        $tour = $otra->tourOrigen?->nombre;

        return match (true) {
            $tour && $hora => "{$quien} ya está en la salida de {$tour} de las {$hora} de ese día.",
            (bool) $tour => "{$quien} ya está en la salida de {$tour} de ese día.",
            (bool) $hora => "{$quien} ya tiene otra salida a las {$hora} ese día.",
            default => "{$quien} ya tiene otra salida ese día.",
        };
    }

    /** @return array<int, string> */
    public function avisosGuia(SalidaOperativa $salida, ?int $guiaId): array
    {
        if (! $guiaId || ! $this->enCurso($salida)) {
            return [];
        }
        $otra = $this->otrasDelDia($salida)->first(
            fn (SalidaOperativa $o) => $o->guia_id === $guiaId || $o->vehiculos->contains('guia_id', $guiaId)
        );
        if (! $otra) {
            return [];
        }
        return [$this->donde($this->nombreGuia($guiaId), $otra)];
    }

    /** @return array<int, string> */
    public function avisosChofer(SalidaOperativa $salida, ?string $telefono, ?string $nombre): array
    {
        $buscado = self::normalizarTelefono($telefono);
        if ($buscado === null || ! $this->enCurso($salida)) {
            return [];
        }
        $otra = $this->otrasDelDia($salida)->first(
            fn (SalidaOperativa $o) => $o->vehiculos->contains(
                fn (SalidaVehiculo $v) => self::normalizarTelefono($v->chofer_telefono) === $buscado
            )
        );
        if (! $otra) {
            return [];
        }

        return [$this->donde($nombre ?: "El chofer {$telefono}", $otra)];
    }

    /**
     * Para el tablero: el guía de la salida o de alguno de sus vehículos que ya
     * está en otra salida del día (texto del aviso), o null.
     */
    public function avisoGuiaRepetido(SalidaOperativa $salida): ?string
    {
        $ids = collect([$salida->guia_id])
            ->merge($salida->vehiculos->pluck('guia_id'))
            ->filter()->unique();
        foreach ($ids as $guiaId) {
            $aviso = $this->avisosGuia($salida, (int) $guiaId)[0] ?? null;
            if ($aviso) {
                return $aviso;
            }
        }

        return null;
    }
}
