<?php

namespace App\Console\Commands;

use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

// Fase 1 del fix Cotización↔Reserva (ver brief "Fix fechas Cotización↔Reserva,
// FASE 1" — 18-ago-2026). SOLO LECTURA — no escribe nada, en ningún tenant.
//
// Por qué hace falta: reserva_items.fecha se calculó, en el momento de
// creación, como cotizacion.fecha_viaje_desde (en ese instante) +
// dia_referencial - 1. Pero la cotización sigue siendo editable sin ningún
// guard después de aceptada (bug de fondo, ver análisis de la sesión
// anterior), así que para una reserva ya existente NO se puede asumir que
// cotizacion.fecha_viaje_desde (valor de HOY) coincide con el valor que
// existía cuando esa reserva se creó/sincronizó. Este comando reconstruye
// esa fecha original ("fecha_base_inferida") a partir de los reserva_items
// ya persistidos, para decidir cómo debe comportarse el backfill de la
// migración de la Fase 1 (§2 del brief) sin congelar el bug en los datos.
//
// Resolución de dia_referencial: ReservaItem::alternativaItem() es un
// belongsTo real y directo a AlternativaItem (retrofit Sesión 11c,
// reserva_items.alternativa_item_id) — dia_referencial se lee de ahí, NO
// hay que reconstruirlo por tour_origen_id/orden. Confirmado leyendo
// ReservaItem.php/AlternativaItem.php antes de escribir este comando.
class DiagnosticarFechasReserva extends Command
{
    protected $signature = 'agencia-viajes:diagnosticar-fechas-reserva
        {--tenant=* : Id(s) de tenant específico(s) a diagnosticar. Por defecto: todos los tenants giro=agencia_viajes, no archivados}
        {--incluir-archivados : Incluye también tenants con status=archivado}
        {--csv= : Ruta de archivo donde exportar el reporte completo en CSV, además de la tabla en consola}';

    protected $description = 'DIAGNÓSTICO DE SOLO LECTURA: clasifica cada reserva existente según si reserva_items.fecha es reconstruible de forma consistente contra cotizacion.fecha_viaje_desde actual. No modifica nada — su salida decide cómo debe correr el backfill de la migración de la Fase 1 del fix Cotización↔Reserva.';

    private const CONSISTENTE = 'CONSISTENTE';
    private const AMBIGUA = 'AMBIGUA';
    private const DIVERGENTE = 'DIVERGENTE';
    private const SIN_FECHA = 'SIN_FECHA';

    // Prioridad de atención al ordenar el reporte (lo más urgente primero).
    private const ORDEN_CATEGORIA = [
        self::DIVERGENTE => 0,
        self::AMBIGUA => 1,
        self::SIN_FECHA => 2,
        self::CONSISTENTE => 3,
    ];

    public function handle(): int
    {
        $tenants = $this->resolverTenants();

        if ($tenants->isEmpty()) {
            $this->error('No se encontró ningún tenant para diagnosticar (revisa --tenant o el filtro giro=agencia_viajes).');

            return self::FAILURE;
        }

        $todasLasFilas = [];
        $tenantsSinTablaReserva = [];

        foreach ($tenants as $tenant) {
            $this->info("Diagnosticando tenant '{$tenant->id}'...");

            $filasTenant = $tenant->run(function () use ($tenant, &$tenantsSinTablaReserva) {
                if (! Schema::hasTable('reserva')) {
                    $tenantsSinTablaReserva[] = $tenant->id;

                    return [];
                }

                return $this->diagnosticarTenant($tenant->id);
            });

            $todasLasFilas = array_merge($todasLasFilas, $filasTenant);
        }

        if (! empty($tenantsSinTablaReserva)) {
            $this->warn('Tenants sin tabla `reserva` (vertical agencia-viajes sin migrar todavía, omitidos): ' . implode(', ', $tenantsSinTablaReserva));
        }

        if (empty($todasLasFilas)) {
            $this->info('No se encontró ninguna reserva en ningún tenant diagnosticado. Nada que reportar.');

            return self::SUCCESS;
        }

        usort($todasLasFilas, function (array $a, array $b) {
            $prioridadA = self::ORDEN_CATEGORIA[$a['categoria']];
            $prioridadB = self::ORDEN_CATEGORIA[$b['categoria']];

            // Dentro de la misma categoría, las que además requieren revisión
            // operativa (SalidaOperativa desalineada) suben primero.
            $urgenciaA = $a['requiere_revision_operativa'] ? 0 : 1;
            $urgenciaB = $b['requiere_revision_operativa'] ? 0 : 1;

            return [$prioridadA, $urgenciaA, $a['tenant_id'], $a['reserva_id']]
                <=> [$prioridadB, $urgenciaB, $b['tenant_id'], $b['reserva_id']];
        });

        $this->newLine();
        $this->table(
            ['Tenant', 'Reserva ID', 'Código', 'Categoría', 'Fecha cotización (actual)', 'Fecha base inferida', '# Items', 'Rev. operativa'],
            array_map(fn (array $f) => [
                $f['tenant_id'],
                $f['reserva_id'],
                $f['codigo'] ?? '(sin código)',
                $f['categoria'],
                $f['fecha_cotizacion_actual'] ?? '—',
                $f['fecha_base_inferida'] ?? '—',
                $f['cantidad_items'],
                $f['requiere_revision_operativa'] ? 'SÍ' : 'no',
            ], $todasLasFilas)
        );

        $resumen = collect($todasLasFilas)->groupBy('categoria')->map->count();
        $totalRevisionOperativa = collect($todasLasFilas)->where('requiere_revision_operativa', true)->count();

        $this->newLine();
        $this->info('Resumen:');
        $this->table(
            ['Categoría', 'Cantidad'],
            array_map(fn ($cat) => [$cat, $resumen[$cat] ?? 0], array_keys(self::ORDEN_CATEGORIA))
        );
        $this->line("Total de reservas diagnosticadas: " . count($todasLasFilas));
        $this->line("Con SalidaOperativa potencialmente desalineada (requiere_revision_operativa): {$totalRevisionOperativa}");

        if ($this->option('csv')) {
            $this->exportarCsv($this->option('csv'), $todasLasFilas);
            $this->info("Reporte exportado a: {$this->option('csv')}");
        }

        $this->newLine();
        $this->warn('Este comando NO modificó ningún dato. Revisa el resumen con el usuario antes de continuar con la migración/backfill (§2 del brief) — no avances sin ese visto bueno.');

        return self::SUCCESS;
    }

    private function resolverTenants()
    {
        $idsExplicitos = $this->option('tenant');

        if (! empty($idsExplicitos)) {
            return Tenant::whereIn('id', $idsExplicitos)->get();
        }

        return Tenant::where('giro', 'agencia_viajes')
            ->when(! $this->option('incluir-archivados'), fn ($q) => $q->where('status', '!=', 'archivado'))
            ->get();
    }

    /**
     * @return array<int, array{
     *   tenant_id: string, reserva_id: int, codigo: ?string, categoria: string,
     *   fecha_cotizacion_actual: ?string, fecha_base_inferida: ?string,
     *   cantidad_items: int, requiere_revision_operativa: bool
     * }>
     */
    private function diagnosticarTenant(string $tenantId): array
    {
        $reservas = Reserva::with([
            'alternativa.cotizacion',
            'items.alternativaItem',
            'items.salidaOperativa',
        ])->get();

        $filas = [];

        foreach ($reservas as $reserva) {
            $filas[] = $this->clasificarReserva($tenantId, $reserva);
        }

        return $filas;
    }

    private function clasificarReserva(string $tenantId, Reserva $reserva): array
    {
        $cotizacion = $reserva->alternativa?->cotizacion;
        $fechaCotizacionActual = $cotizacion?->fecha_viaje_desde;

        // Solo entran al cálculo de "fecha base" los ítems donde AMBOS datos
        // existen — mismo criterio ya usado en
        // ReservaController::crearReservaItemDesdeAlternativaItem() (si falta
        // cualquiera de los dos, la fecha del ítem nace/queda null, no aporta
        // información para inferir la base).
        $basesInferidas = [];

        foreach ($reserva->items as $item) {
            $diaReferencial = $item->alternativaItem?->dia_referencial;

            if ($diaReferencial === null || $item->fecha === null) {
                continue;
            }

            $baseInferida = $item->fecha->copy()->subDays($diaReferencial - 1)->toDateString();
            $basesInferidas[$baseInferida] = true;
        }

        $basesUnicas = array_keys($basesInferidas);
        $cantidadItems = $reserva->items->count();

        $requiereRevisionOperativa = $this->detectarDesalineacionOperativa($reserva);

        if (empty($basesUnicas)) {
            return [
                'tenant_id' => $tenantId,
                'reserva_id' => $reserva->id,
                'codigo' => $cotizacion?->codigo,
                'categoria' => self::SIN_FECHA,
                'fecha_cotizacion_actual' => $fechaCotizacionActual?->toDateString(),
                'fecha_base_inferida' => null,
                'cantidad_items' => $cantidadItems,
                'requiere_revision_operativa' => $requiereRevisionOperativa,
            ];
        }

        if (count($basesUnicas) > 1) {
            return [
                'tenant_id' => $tenantId,
                'reserva_id' => $reserva->id,
                'codigo' => $cotizacion?->codigo,
                'categoria' => self::DIVERGENTE,
                'fecha_cotizacion_actual' => $fechaCotizacionActual?->toDateString(),
                // DIVERGENTE no tiene UNA base — se listan todas separadas por
                // "|" para que la revisión manual sepa qué está en juego.
                'fecha_base_inferida' => implode(' | ', $basesUnicas),
                'cantidad_items' => $cantidadItems,
                'requiere_revision_operativa' => $requiereRevisionOperativa,
            ];
        }

        $baseUnica = $basesUnicas[0];
        $coincideConCotizacionActual = $fechaCotizacionActual && $fechaCotizacionActual->toDateString() === $baseUnica;

        return [
            'tenant_id' => $tenantId,
            'reserva_id' => $reserva->id,
            'codigo' => $cotizacion?->codigo,
            'categoria' => $coincideConCotizacionActual ? self::CONSISTENTE : self::AMBIGUA,
            'fecha_cotizacion_actual' => $fechaCotizacionActual?->toDateString(),
            'fecha_base_inferida' => $baseUnica,
            'cantidad_items' => $cantidadItems,
            'requiere_revision_operativa' => $requiereRevisionOperativa,
        ];
    }

    // Invariante esperado desde la creación: un reserva_item enganchado a una
    // SalidaOperativa (ReservaController::engancharSalidaOperativa()) nace
    // con reserva_item.fecha === salidaOperativa.fecha, ambas calculadas del
    // mismo $fechaCalculada en el mismo momento. SalidaOperativaController::
    // update() nunca permite editar `fecha` de la salida — así que la ÚNICA
    // forma de que ambas terminen distintas es que reserva_item.fecha se haya
    // editado a mano después (ReservaItemController::update()) sin
    // desenganchar/reenganchar la salida. Eso es exactamente la señal de "hay
    // una salida operativa agrupando pasajeros bajo una fecha que ya no es la
    // del ítem" que pide el brief — comparación directa, sin necesidad de
    // re-derivar nada contra dia_referencial/cotización.
    private function detectarDesalineacionOperativa(Reserva $reserva): bool
    {
        if ($reserva->estado !== 'activa') {
            return false;
        }

        return $reserva->items->contains(function (ReservaItem $item) {
            if (! $item->salidaOperativa) {
                return false;
            }

            $fechaItem = $item->fecha?->toDateString();
            $fechaSalida = $item->salidaOperativa->fecha?->toDateString();

            return $fechaItem !== $fechaSalida;
        });
    }

    private function exportarCsv(string $ruta, array $filas): void
    {
        $handle = fopen($ruta, 'w');

        fputcsv($handle, ['tenant_id', 'reserva_id', 'codigo', 'categoria', 'fecha_cotizacion_actual', 'fecha_base_inferida', 'cantidad_items', 'requiere_revision_operativa']);

        foreach ($filas as $fila) {
            fputcsv($handle, [
                $fila['tenant_id'],
                $fila['reserva_id'],
                $fila['codigo'] ?? '',
                $fila['categoria'],
                $fila['fecha_cotizacion_actual'] ?? '',
                $fila['fecha_base_inferida'] ?? '',
                $fila['cantidad_items'],
                $fila['requiere_revision_operativa'] ? '1' : '0',
            ]);
        }

        fclose($handle);
    }
}
