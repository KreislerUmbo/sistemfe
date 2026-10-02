<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Enums\Creditos\RequisitoFicha;
use App\Models\Creditos\CreditoConfiguracion;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listado de clientes en el giro Créditos (04c): situación, ficha incompleta y asesor, en SQL
 * para poder filtrar y paginar sin calcular cliente por cliente. Las reglas son las mismas que
 * usa el resto del módulo: atraso = cuota vigente pendiente ya vencida (00 1.6); bloqueado =
 * bloqueo manual o crédito castigado (1.10); ficha = requisitos_ficha de la configuración.
 */
class ClientesCreditoConsulta
{
    public const SITUACIONES = ['al_dia', 'atrasado', 'bloqueado', 'sin_creditos'];

    private const VIVOS = "('activo', 'castigado')";

    public function __construct(private readonly Reloj $reloj)
    {
    }

    /**
     * @param Builder<\App\Models\Client\Client> $clientes
     * @param array{asesor_id?: int|null, situacion?: string|null, ficha_incompleta?: bool} $filtros asesor_id 0 = sin asesor
     */
    public function filtrar(Builder $clientes, array $filtros): Builder
    {
        if (array_key_exists('asesor_id', $filtros) && $filtros['asesor_id'] !== null) {
            $asesor = 'EXISTS (SELECT 1 FROM cartera_asignaciones a WHERE a.tipo = \'cliente\' AND a.referencia_id = clients.id'
                . ' AND a.funcion = \'asesor\' AND a.vigente_hasta IS NULL' . ($filtros['asesor_id'] === 0 ? ')' : ' AND a.usuario_id = ?)');
            $filtros['asesor_id'] === 0
                ? $clientes->whereRaw("NOT {$asesor}")
                : $clientes->whereRaw($asesor, [$filtros['asesor_id']]);
        }

        [$atrasado, $bAtrasado] = $this->atrasado();
        match ($filtros['situacion'] ?? null) {
            'bloqueado' => $clientes->whereRaw($this->bloqueado()),
            'atrasado' => $clientes->whereRaw("({$atrasado}) AND NOT ({$this->bloqueado()})", $bAtrasado),
            'al_dia' => $clientes->whereRaw("({$this->vivo()}) AND NOT ({$atrasado}) AND NOT ({$this->bloqueado()})", $bAtrasado),
            'sin_creditos' => $clientes->whereRaw("NOT ({$this->vivo()}) AND NOT ({$this->bloqueado()})"),
            default => null,
        };

        if ($filtros['ficha_incompleta'] ?? false) {
            $faltas = $this->faltas();
            $faltas === [] ? $clientes->whereRaw('false') : $clientes->whereRaw('(' . implode(' OR ', $faltas) . ')');
        }

        return $clientes;
    }

    /**
     * Columnas del listado para una página de clientes.
     *
     * @param list<int> $ids
     * @return array<int, array{asesor: string|null, situacion: string, ficha_faltante: int}>
     */
    public function datos(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        [$atrasado, $bAtrasado] = $this->atrasado();
        $faltas = $this->faltas();
        $cuenta = $faltas === [] ? '0' : implode(' + ', array_map(static fn (string $f): string => "(CASE WHEN {$f} THEN 1 ELSE 0 END)", $faltas));

        $filas = \App\Models\Client\Client::query()->whereIn('clients.id', $ids)
            ->selectRaw('clients.id')
            ->selectRaw("(SELECT u.name FROM cartera_asignaciones a JOIN users u ON u.id = a.usuario_id WHERE a.tipo = 'cliente'"
                . " AND a.referencia_id = clients.id AND a.funcion = 'asesor' AND a.vigente_hasta IS NULL LIMIT 1) AS asesor")
            ->selectRaw("CASE WHEN {$this->bloqueado()} THEN 'bloqueado' WHEN {$atrasado} THEN 'atrasado'"
                . " WHEN {$this->vivo()} THEN 'al_dia' ELSE 'sin_creditos' END AS situacion", $bAtrasado)
            ->selectRaw("({$cuenta}) AS ficha_faltante")
            ->get();

        return $filas->mapWithKeys(static fn ($f): array => [(int) $f->id => [
            'asesor' => $f->asesor,
            'situacion' => $f->situacion,
            'ficha_faltante' => (int) $f->ficha_faltante,
        ]])->all();
    }

    private function vivo(): string
    {
        return 'EXISTS (SELECT 1 FROM creditos c WHERE c.cliente_id = clients.id AND c.estado IN ' . self::VIVOS . ')';
    }

    /** @return array{0: string, 1: list<string>} */
    private function atrasado(): array
    {
        return [
            'EXISTS (SELECT 1 FROM creditos c JOIN credito_cuotas q ON q.credito_id = c.id AND q.version_cronograma = c.version_cronograma_actual'
            . ' WHERE c.cliente_id = clients.id AND c.estado IN ' . self::VIVOS . " AND q.estado = 'pendiente' AND q.fecha_vencimiento < ?)",
            [$this->reloj->hoy()->aTexto()],
        ];
    }

    private function bloqueado(): string
    {
        return '(EXISTS (SELECT 1 FROM credito_cliente_limites l WHERE l.cliente_id = clients.id AND l.bloqueado)'
            . " OR EXISTS (SELECT 1 FROM creditos c WHERE c.cliente_id = clients.id AND c.estado = 'castigado'))";
    }

    /**
     * Una condición SQL por requisito exigido que el cliente NO cumple (mismo criterio que
     * ClienteCreditoService::fichaFaltante()).
     *
     * @return list<string>
     */
    private function faltas(): array
    {
        $ficha = static fn (string $condicion): string => "NOT EXISTS (SELECT 1 FROM credito_cliente_fichas f WHERE f.cliente_id = clients.id AND {$condicion})";
        $archivo = static fn (string $tipo): string => "NOT EXISTS (SELECT 1 FROM credito_cliente_archivos x WHERE x.cliente_id = clients.id AND x.tipo = '{$tipo}')";

        return array_values(array_filter(array_map(static fn (string $r): ?string => match (RequisitoFicha::tryFrom($r)) {
            RequisitoFicha::DniAnverso, RequisitoFicha::DniReverso, RequisitoFicha::FotoCliente => $archivo($r),
            RequisitoFicha::Telefono => "COALESCE(TRIM(clients.phone), '') = ''",
            RequisitoFicha::DireccionCobro => $ficha("COALESCE(TRIM(f.direccion_cobro), '') <> ''"),
            RequisitoFicha::Referencia => $ficha("COALESCE(TRIM(f.referencia), '') <> ''"),
            RequisitoFicha::Ubicacion => $ficha('f.latitud IS NOT NULL AND f.longitud IS NOT NULL'),
            RequisitoFicha::Ocupacion => $ficha("COALESCE(TRIM(f.ocupacion), '') <> ''"),
            null => null,
        }, CreditoConfiguracion::actual()->requisitos_ficha ?? [])));
    }
}
