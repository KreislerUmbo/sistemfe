<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\CompraAsignacion;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\SalidaOperativa;
use App\Models\Compras\Compra;
use App\Models\Sale\TipoComprobante;
use App\Services\BusquedaLike;
use App\Services\Compras\CompraException;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * T-152 — reparto manual del total de una compra entre reservas, salidas y «general». Se guarda
 * siempre completo (reemplaza el anterior). No mira el periodo (Q6) ni filtra por vendedor (Q12).
 * Todo rechazo sale como CompraException.
 */
class CompraAsignacionService
{
    private const MAX_FILAS = 100;

    private const MAX_RESULTADOS = 20;

    /** Lista vacía = «sin asignar»; con filas, suman el total exacto (Q2, Q3). */
    public function guardar(Compra $compra, mixed $filas, int $userId): void
    {
        $filas = $this->validar($filas);

        DB::transaction(function () use ($compra, $filas, $userId) {
            // Se bloquea la compra: serializa con editar() y anular() de CompraService.
            $actual = Compra::whereKey($compra->id)->lockForUpdate()->firstOrFail();
            if ($actual->estaAnulada()) {
                throw new CompraException('Una compra anulada no se puede repartir.');
            }

            $this->exigirDestinosExistentes($filas);

            if ($filas !== []) {
                $suma = array_reduce(
                    $filas,
                    fn (BigDecimal $total, array $fila) => $total->plus($fila['monto']),
                    BigDecimal::of('0.00'),
                );
                if (! $suma->isEqualTo($actual->total)) {
                    throw new CompraException("El reparto debe sumar el total de la compra ({$actual->total}); suma {$suma->toScale(2)}.");
                }
            }

            $anteriores = CompraAsignacion::where('compra_id', $actual->id)->get()
                ->keyBy(fn (CompraAsignacion $a) => $a->claveDestino());

            // toBase(): en una colección de Eloquent, except() mira el id del modelo, no la clave.
            $anteriores->toBase()->except(array_keys($filas))->each->delete();

            foreach ($filas as $clave => $fila) {
                $anterior = $anteriores->get($clave);
                if (! $anterior) {
                    CompraAsignacion::create($fila + ['compra_id' => $actual->id, 'user_id' => $userId]);
                } elseif (! BigDecimal::of($anterior->monto)->isEqualTo($fila['monto'])) {
                    // Q12: una fila sin cambios conserva a quien la ingresó.
                    $anterior->update(['monto' => $fila['monto'], 'user_id' => $userId]);
                }
            }
        });
    }

    /** El reparto de una compra, con el nombre de cada destino, para su detalle. */
    public function detalle(Compra $compra): array
    {
        return CompraAsignacion::with(['usuario', 'salida.tourOrigen'] + $this->relacionesReserva('reserva.'))
            ->where('compra_id', $compra->id)
            ->orderBy('id')
            ->get()
            ->map(fn (CompraAsignacion $a) => [
                'destino' => $a->destino,
                'reserva_id' => $a->reserva_id,
                'salida_operativa_id' => $a->salida_operativa_id,
                'monto' => $a->monto,
                'titulo' => match ($a->destino) {
                    CompraAsignacion::DESTINO_RESERVA => $this->tituloReserva($a->reserva),
                    CompraAsignacion::DESTINO_SALIDA => $this->tituloSalida($a->salida),
                    default => 'General',
                },
                'usuario' => $a->usuario ? trim("{$a->usuario->name} {$a->usuario->surname}") : null,
            ])
            ->all();
    }

    /** Buscador del modal «Asignar»: reservas (código, cliente) o salidas (tour, fecha). */
    public function destinos(string $tipo, string $texto): array
    {
        $patron = $texto === '' ? null : BusquedaLike::contiene($texto);

        if ($tipo === CompraAsignacion::DESTINO_SALIDA) {
            return SalidaOperativa::with('tourOrigen')
                ->when($patron, fn (Builder $q) => $q->where(fn (Builder $o) => $o
                    ->whereHas('tourOrigen', fn (Builder $t) => $t->where('nombre', 'ilike', $patron))
                    ->orWhereRaw("to_char(fecha, 'DD/MM/YYYY') ilike ?", [$patron])))
                ->orderByDesc('fecha')->orderByDesc('id')
                ->limit(self::MAX_RESULTADOS)
                ->get()
                ->map(fn (SalidaOperativa $s) => ['id' => $s->id, 'titulo' => $this->tituloSalida($s)])
                ->all();
        }

        return Reserva::with($this->relacionesReserva())
            ->when($patron, fn (Builder $q) => $q->where(fn (Builder $o) => $o
                ->where('codigo', 'ilike', $patron)
                ->orWhereHas('alternativa', fn (Builder $a) => $a->whereHas('cotizacion', fn (Builder $c) => $c
                    ->withoutGlobalScope('vendedor')
                    ->where(fn (Builder $d) => $d->where('codigo', 'ilike', $patron)
                        ->orWhereHas('cliente', fn (Builder $p) => $p->where('full_name', 'ilike', $patron)))))))
            ->orderByDesc('id')
            ->limit(self::MAX_RESULTADOS)
            ->get()
            ->map(fn (Reserva $r) => ['id' => $r->id, 'titulo' => $this->tituloReserva($r)])
            ->all();
    }

    /** Bloque «Compras» de una ficha: solo vigentes, con el monto cargado a ese destino. */
    public function comprasDeFicha(string $destino, int $id): array
    {
        $asignaciones = $destino === CompraAsignacion::DESTINO_RESERVA
            ? $this->comprasDeReserva($id)
            : $this->vigentesDe('salida_operativa_id', $id);
        $tipos = TipoComprobante::pluck('nombre', 'codigo');

        return $asignaciones
            ->sortByDesc(fn (CompraAsignacion $a) => [$a->compra->fecha_emision->toDateString(), $a->compra_id])
            ->map(fn (CompraAsignacion $a) => [
                'compra_id' => $a->compra_id,
                'fecha_emision' => $a->compra->fecha_emision->toDateString(),
                'tipo_comprobante_codigo' => $a->compra->tipo_comprobante_codigo,
                'tipo_nombre' => $tipos[$a->compra->tipo_comprobante_codigo] ?? $a->compra->tipo_comprobante_codigo,
                'serie' => $a->compra->serie,
                'numero' => $a->compra->numero,
                'proveedor' => $a->compra->proveedor?->only(['razon_social', 'ciudad']),
                'moneda' => $a->compra->moneda,
                'monto' => $a->monto,
                'total' => $a->compra->total,
            ])
            ->values()
            ->all();
    }

    /**
     * Lectura para T-143 y T-145: las reservas a las que está cargada una compra, con su monto.
     *
     * @return array<int, string> reserva_id => monto
     */
    public function reservasDeCompra(int $compraId): array
    {
        return CompraAsignacion::where('compra_id', $compraId)
            ->where('destino', CompraAsignacion::DESTINO_RESERVA)
            ->orderBy('id')
            ->get()
            ->pluck('monto', 'reserva_id')
            ->all();
    }

    /**
     * Lectura para T-143 y T-145: las compras vigentes cargadas a una reserva.
     *
     * @return Collection<int, CompraAsignacion> cada una con su `compra`
     */
    public function comprasDeReserva(int $reservaId): Collection
    {
        return $this->vigentesDe('reserva_id', $reservaId);
    }

    /**
     * Antes de mover las asignaciones de una salida: bloquea sus compras. Mismo orden que
     * guardar() (compra y después salida) para no cruzarse. Solo dentro de una transacción.
     */
    public function bloquearComprasDeSalida(int $salidaId): void
    {
        Compra::whereIn('id', CompraAsignacion::where('salida_operativa_id', $salidaId)->select('compra_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    /** Q9: al reprogramar la salida completa, lo cargado a la vieja pasa a la nueva. */
    public function trasladarSalida(int $viejaId, int $nuevaId): void
    {
        foreach (CompraAsignacion::where('salida_operativa_id', $viejaId)->get() as $asignacion) {
            $enLaNueva = CompraAsignacion::where('compra_id', $asignacion->compra_id)
                ->where('salida_operativa_id', $nuevaId)
                ->first();

            if ($enLaNueva) {
                $enLaNueva->update(['monto' => (string) BigDecimal::of($enLaNueva->monto)->plus($asignacion->monto)]);
                $asignacion->delete();
            } else {
                $asignacion->update(['salida_operativa_id' => $nuevaId]);
            }
        }
    }

    private function vigentesDe(string $columna, int $id): Collection
    {
        return CompraAsignacion::with('compra.proveedor')
            ->where($columna, $id)
            ->whereHas('compra', fn (Builder $q) => $q->vigentes())
            ->get();
    }

    /** @return array<string, array{destino: string, reserva_id: ?int, salida_operativa_id: ?int, monto: string}> por destino */
    private function validar(mixed $filas): array
    {
        $validator = Validator::make(['filas' => $filas], [
            'filas' => ['present', 'array', 'max:'.self::MAX_FILAS],
            'filas.*' => ['array'],
            'filas.*.destino' => ['required', 'in:'.implode(',', CompraAsignacion::DESTINOS)],
            'filas.*.reserva_id' => ['nullable', 'integer', 'min:1'],
            'filas.*.salida_operativa_id' => ['nullable', 'integer', 'min:1'],
            'filas.*.monto' => ['required', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
        ], [
            'filas.present' => 'Falta el reparto.',
            'filas.array' => 'El reparto no es válido.',
            'filas.max' => 'Una compra se reparte en hasta '.self::MAX_FILAS.' filas.',
            'filas.*.destino.required' => 'Hay una fila sin destino.',
            'filas.*.destino.in' => 'Hay una fila con un destino no válido.',
            'filas.*.reserva_id.min' => 'Hay una fila con una reserva que no existe.',
            'filas.*.salida_operativa_id.min' => 'Hay una fila con una salida que no existe.',
            'filas.*.monto.required' => 'Hay un destino sin monto.',
            'filas.*.monto.gt' => 'Cada monto debe ser mayor a cero.',
            'filas.*.monto.decimal' => 'Los montos van con dos decimales como máximo.',
        ]);
        if ($validator->fails()) {
            throw new CompraException($validator->errors()->first(), 422, ['errors' => $validator->errors()->toArray()]);
        }

        $porDestino = [];
        foreach ($filas as $fila) {
            $destino = $fila['destino'];
            $reservaId = isset($fila['reserva_id']) ? (int) $fila['reserva_id'] : null;
            $salidaId = isset($fila['salida_operativa_id']) ? (int) $fila['salida_operativa_id'] : null;

            $corresponde = ($reservaId !== null) === ($destino === CompraAsignacion::DESTINO_RESERVA)
                && ($salidaId !== null) === ($destino === CompraAsignacion::DESTINO_SALIDA);
            if (! $corresponde) {
                throw new CompraException("Una fila de «{$destino}» no indica bien a qué se carga.");
            }

            $clave = CompraAsignacion::clave($destino, $reservaId ?? $salidaId);
            if (isset($porDestino[$clave])) {
                throw new CompraException('Hay un destino repetido en el reparto.');
            }
            $porDestino[$clave] = [
                'destino' => $destino,
                'reserva_id' => $reservaId,
                'salida_operativa_id' => $salidaId,
                'monto' => (string) $fila['monto'],
            ];
        }

        return $porDestino;
    }

    // Las salidas se bloquean para que no se borren mientras se les carga la compra.
    private function exigirDestinosExistentes(array $filas): void
    {
        $reservas = array_values(array_filter(array_column($filas, 'reserva_id')));
        if ($reservas && Reserva::whereIn('id', $reservas)->count() !== count($reservas)) {
            throw new CompraException('Una de las reservas ya no existe.');
        }

        $salidas = array_values(array_filter(array_column($filas, 'salida_operativa_id')));
        if ($salidas && SalidaOperativa::whereIn('id', $salidas)->orderBy('id')->lockForUpdate()->get(['id'])->count() !== count($salidas)) {
            throw new CompraException('Una de las salidas ya no existe: vuelve a buscarla.');
        }
    }

    // El nombre del cliente sale de la cotización, que tiene un filtro global por vendedor (Q12).
    private function relacionesReserva(string $prefijo = ''): array
    {
        return [
            $prefijo.'alternativa.cotizacion' => fn ($q) => $q->withoutGlobalScope('vendedor')->with('cliente'),
        ];
    }

    private function tituloReserva(Reserva $reserva): string
    {
        $cotizacion = $reserva->alternativa?->cotizacion;
        $codigo = $reserva->codigo ?? $cotizacion?->codigo ?? "#{$reserva->id}";

        return implode(' · ', array_filter([
            "Reserva {$codigo}",
            $cotizacion?->cliente?->full_name,
            $reserva->fecha_viaje_desde?->format('d/m/Y'),
            $reserva->estado === 'cancelada' ? 'cancelada' : null,
        ]));
    }

    private function tituloSalida(SalidaOperativa $salida): string
    {
        return implode(' · ', array_filter([
            'Salida '.($salida->tourOrigen?->nombre ?? 'sin tour'),
            trim($salida->fecha->format('d/m/Y').' '.substr((string) $salida->hora, 0, 5)),
            $salida->esPrivada() ? 'privada' : null,
            in_array($salida->estado, SalidaOperativa::ESTADOS_EN_CURSO, true) ? null : $salida->estado,
        ]));
    }
}
