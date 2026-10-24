<?php

namespace App\Models\AgenciaViajes;

use App\Models\Sale\Sale;
use Illuminate\Database\Eloquent\Model;

// Tabla puente entre reserva y Sale — NO un campo simple sale_id, una
// reserva puede terminar con más de una venta (§4.3 "documento adicional",
// §4.4 pagos por pasajero con varios responsables). Tenant (sin
// CentralConnection). reserva_id lleva belongsTo real; sale_id apunta al
// core (App\Models\Sale\Sale, tabla 'sales').
class ReservaVenta extends Model
{
    protected $table = 'reserva_ventas';

    protected $fillable = [
        'reserva_id',
        'sale_id',
        'reserva_item_ids',
        'reserva_pasajero_ids',
        'partes_tour',
    ];

    protected $casts = [
        'reserva_item_ids' => 'array',
        'reserva_pasajero_ids' => 'array',
        // Fila 13, 1c: lo cobrado de cada línea de tour en esta factura, ver
        // migración 2026_10_20_100000_add_partes_tour_to_reserva_ventas_table.
        'partes_tour' => 'array',
    ];

    // Líneas de tour que alguna de estas ventas cobró solo en parte, sin que
    // ninguna haya cobrado la última parte (cierra). Siguen pendientes de
    // facturar, aunque figuren en reserva_item_ids.
    public static function itemsDeTourAMedias(iterable $ventas): \Illuminate\Support\Collection
    {
        $partes = collect($ventas)->flatMap(fn (self $rv) => $rv->partes_tour ?? []);

        return $partes->pluck('reserva_item_id')
            ->unique()
            ->diff($partes->where('cierra', true)->pluck('reserva_item_id'))
            ->values();
    }

    // ¿Este tenant tiene reservas? reserva_ventas es una tabla del vertical
    // Agencia: no existe en retail ni en créditos. Todo código del sistema
    // base (Ventas, Notas de crédito) que la consulte pregunta esto antes
    // (bug real 24-oct-2026: desde el 24-ago, eliminar una venta o aceptar
    // una NC en retail fallaba con "no existe la relación reserva_ventas").
    public static function hayReservasEnEsteTenant(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable((new self)->getTable());
    }

    // Qué servicios y qué pasajeros de una reserva ya están en alguna venta
    // (auditoría reserva + facturación, punto 5, 23-oct-2026: antes este
    // cálculo estaba escrito a mano en 17 lugares). Lo que está aquí no se
    // vuelve a facturar ni se puede editar o quitar de la reserva.
    // Consulta la base cada vez: se puede usar dentro de un lockForUpdate().
    public static function itemIdsFacturados(int $reservaId): \Illuminate\Support\Collection
    {
        return self::itemIdsDe(self::where('reserva_id', $reservaId)->get());
    }

    public static function pasajeroIdsFacturados(int $reservaId): \Illuminate\Support\Collection
    {
        return self::pasajeroIdsDe(self::where('reserva_id', $reservaId)->get());
    }

    // Lo mismo a partir de ventas ya cargadas ($reserva->ventas), sin consultar.
    public static function itemIdsDe(iterable $ventas): \Illuminate\Support\Collection
    {
        return collect($ventas)->flatMap(fn (self $rv) => $rv->reserva_item_ids ?? [])->unique()->values();
    }

    public static function pasajeroIdsDe(iterable $ventas): \Illuminate\Support\Collection
    {
        return collect($ventas)->flatMap(fn (self $rv) => $rv->reserva_pasajero_ids ?? [])->unique()->values();
    }

    // Una Nota de Crédito 07 aceptada por SUNAT sobre una venta que salió de
    // facturar una reserva. Salió de NotaElectronicaController (auditoría
    // reserva + facturación, 23-oct-2026) para poder probarla sin SUNAT.
    // - total: anula la venta entera; la reserva vuelve a poder facturar esos
    //   pasajeros y servicios (auditoría 2026-08-24).
    // - parcial: acredita solo algunas líneas (sale_detail_id); se liberan
    //   los servicios que esas líneas agrupaban (sale_detail_items), y si no
    //   queda ninguno es lo mismo que la total (auditoría 2026-09-23).
    public static function liberarPorNotaDeCredito(int $saleId, string $tipoAfectacion, iterable $saleDetailIdsAcreditados): void
    {
        // Retail y créditos no tienen reservas que liberar.
        if (! self::hayReservasEnEsteTenant()) {
            return;
        }

        if ($tipoAfectacion === 'total') {
            self::where('sale_id', $saleId)->delete();

            return;
        }

        if ($tipoAfectacion !== 'parcial') {
            return;
        }

        $reservaVenta = self::where('sale_id', $saleId)->first();
        if (! $reservaVenta) {
            return;
        }

        $reservaItemIdsAcreditados = SaleDetailItem::whereIn('sale_detail_id', collect($saleDetailIdsAcreditados)->filter()->values())
            ->pluck('reserva_item_id');

        $itemIdsRestantes = collect($reservaVenta->reserva_item_ids ?? [])
            ->diff($reservaItemIdsAcreditados)
            ->values()
            ->all();

        if (empty($itemIdsRestantes)) {
            $reservaVenta->delete();

            return;
        }

        // La parte de un tour acreditada deja de contar como cobrada: si no,
        // la factura que cierra el tour la descontaba igual y cobraba de menos.
        $partesRestantes = collect($reservaVenta->partes_tour ?? [])
            ->reject(fn (array $parte) => $reservaItemIdsAcreditados->contains($parte['reserva_item_id']))
            ->values()
            ->all();

        $reservaVenta->update([
            'reserva_item_ids' => $itemIdsRestantes,
            'partes_tour' => $partesRestantes ?: null,
        ]);
    }

    public function reserva()
    {
        return $this->belongsTo(Reserva::class, 'reserva_id');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }
}
