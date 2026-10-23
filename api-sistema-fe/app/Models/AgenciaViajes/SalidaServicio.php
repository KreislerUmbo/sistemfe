<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Servicio a reservar para una salida — fila 13, fase 2b (§3.6, D10/D11).
// Sale del Incluye del tour (paquete_plantilla_item_id) o se agrega a mano.
// Cantidad: cantidad_manual si el operador la fijó; si no, la de la salida
// (adultos + niños), que calcula SalidaOperativaController::resumenSalida().
class SalidaServicio extends Model
{
    protected $table = 'salida_servicios';

    public const ESTADO_POR_RESERVAR = 'por_reservar';
    public const ESTADO_RESERVADO = 'reservado';

    protected $fillable = [
        'salida_operativa_id',
        'paquete_plantilla_item_id',
        'nombre',
        'proveedor_id',
        'cantidad_manual',
        'estado',
        'notas',
        'orden',
    ];

    protected $casts = [
        'cantidad_manual' => 'integer',
        'orden' => 'integer',
    ];

    public function salida()
    {
        return $this->belongsTo(SalidaOperativa::class, 'salida_operativa_id');
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function itemDelTour()
    {
        return $this->belongsTo(PaquetePlantillaItem::class, 'paquete_plantilla_item_id');
    }
}
