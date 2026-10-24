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

    public function reserva()
    {
        return $this->belongsTo(Reserva::class, 'reserva_id');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }
}
