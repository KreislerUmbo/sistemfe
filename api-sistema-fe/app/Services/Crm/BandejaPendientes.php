<?php

namespace App\Services\Crm;

use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\Reserva;
use App\Models\Client\CrmAccion;
use App\Models\Client\CrmActividad;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * T-051 (CRM C2) — la bandeja única de pendientes: "Mis pendientes de hoy" en Inicio y la
 * pantalla Seguimiento. Decidido el 27-oct-2026 (obs. 19): es LA bandeja de pendientes que prevé
 * el plan de tours (fila 11f), con una primera fuente, las acciones del CRM. Por eso cada fila
 * lleva `fuente` ('crm' | 'operacion'): un pendiente de operación (salida bajo el mínimo, pago a
 * proveedor por vencer…) no se marca hecho, se resuelve en su pantalla (`enlace`) y desaparece
 * solo. 11f suma su fuente en filas() sin otra pantalla.
 *
 * Bloques por fecha de negocio (hora Perú): atrasadas (< hoy), hoy y próximos (hasta `hasta`).
 */
class BandejaPendientes
{
    public const FUENTE_CRM = 'crm';
    public const FUENTE_OPERACION = 'operacion';

    public const DIAS_PROXIMOS = 7;

    public function __construct(private readonly AccionesCrm $acciones)
    {
    }

    /**
     * @param  array{responsable_id?: int|null, tipo?: string|null}  $filtros  sin responsable_id = de todos
     * @return list<array<string, mixed>> ordenadas por fecha: las atrasadas primero
     */
    public function filas(User $usuario, CarbonImmutable $hoy, CarbonImmutable $hasta, array $filtros = []): array
    {
        return $this->deCrm($usuario, $hoy, $hasta, $filtros)
            ->sortBy([['fecha', 'asc'], ['id', 'asc']])
            ->values()
            ->all();
    }

    private function deCrm(User $usuario, CarbonImmutable $hoy, CarbonImmutable $hasta, array $filtros): Collection
    {
        $acciones = CrmAccion::pendientes()
            ->with(['responsable:id,name,surname', 'cliente:id,full_name'])
            ->where('fecha', '<=', $hasta->toDateString())
            ->when($filtros['responsable_id'] ?? null, fn ($q, $id) => $q->where('responsable_id', $id))
            ->when($filtros['tipo'] ?? null, fn ($q, $tipo) => $q->where('tipo', $tipo))
            ->orderBy('fecha')
            ->orderBy('id')
            ->get();

        $codigos = $this->codigosDeReferencias($acciones);
        $hoyTexto = $hoy->toDateString();

        return $acciones->map(function (CrmAccion $a) use ($usuario, $codigos, $hoyTexto) {
            $fecha = $a->fecha->toDateString();

            return [
                'id' => self::FUENTE_CRM . '-' . $a->id,
                'fuente' => self::FUENTE_CRM,
                'bloque' => $fecha < $hoyTexto ? 'atrasadas' : ($fecha === $hoyTexto ? 'hoy' : 'proximos'),
                'fecha' => $fecha,
                'titulo' => $a->etiquetaTipo(),
                'descripcion' => $a->descripcion,
                'cliente' => $a->cliente ? ['id' => $a->cliente->id, 'nombre' => $a->cliente->full_name] : null,
                'referencia' => $a->referencia_tipo ? [
                    'tipo' => $a->referencia_tipo,
                    'id' => $a->referencia_id,
                    'codigo' => $codigos[$a->referencia_tipo][$a->referencia_id] ?? null,
                ] : null,
                'responsable' => ['id' => $a->responsable_id, 'nombre' => AccionesCrm::nombreUsuario($a->responsable)],
                // Lo que se hace desde la fila: una acción del CRM se marca hecha o se reprograma.
                'accion' => $this->acciones->presentar($a, $usuario),
                'enlace' => null,
            ];
        });
    }

    /** @return array<string, array<int, string|null>> códigos de las cotizaciones y reservas citadas */
    private function codigosDeReferencias(Collection $acciones): array
    {
        $ids = fn (string $tipo) => $acciones->where('referencia_tipo', $tipo)->pluck('referencia_id')->unique()->values();

        $cotizaciones = $ids(CrmActividad::REFERENCIA_COTIZACION);
        $reservas = $ids(CrmActividad::REFERENCIA_RESERVA);

        return [
            CrmActividad::REFERENCIA_COTIZACION => $cotizaciones->isEmpty() ? [] : Cotizacion::whereIn('id', $cotizaciones)->pluck('codigo', 'id')->all(),
            CrmActividad::REFERENCIA_RESERVA => $reservas->isEmpty() ? [] : Reserva::whereIn('id', $reservas)->pluck('codigo', 'id')->all(),
        ];
    }
}
