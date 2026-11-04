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

    /** @return Collection<int, SalidaOperativa> otras salidas en curso del mismo día */
    private function otrasDelDia(SalidaOperativa $salida): Collection
    {
        return SalidaOperativa::enCurso()
            ->where('fecha', $salida->fecha->toDateString())
            ->where('id', '!=', $salida->id)
            ->with(['tourOrigen', 'vehiculos'])
            ->orderBy('hora')
            ->get();
    }

    private function donde(string $quien, ?SalidaOperativa $otra): string
    {
        if (! $otra) {
            return '';
        }
        $hora = $otra->hora ? substr((string) $otra->hora, 0, 5) : null;
        $tour = $otra->tourOrigen?->nombre;
        if (! $hora || ! $tour) {
            return "{$quien} ya tiene otra salida ese día.";
        }

        return "{$quien} ya está en la salida de {$tour} de las {$hora} de ese día.";
    }

    /** @return array<int, string> */
    public function avisosGuia(SalidaOperativa $salida, ?int $guiaId): array
    {
        if (! $guiaId) {
            return [];
        }
        $otra = $this->otrasDelDia($salida)->first(
            fn (SalidaOperativa $o) => $o->guia_id === $guiaId || $o->vehiculos->contains('guia_id', $guiaId)
        );
        if (! $otra) {
            return [];
        }
        $nombre = Guia::whereKey($guiaId)->value('nombre') ?? 'El guía';

        return [$this->donde($nombre, $otra)];
    }

    /** @return array<int, string> */
    public function avisosChofer(SalidaOperativa $salida, ?string $telefono, ?string $nombre): array
    {
        $buscado = self::normalizarTelefono($telefono);
        if ($buscado === null) {
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
