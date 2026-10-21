<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Vehículo de una salida — fila 13, fase 2b (§3.6, D12/D13). Una salida
// puede tener varios (las reservas van en vehículos y zonas de recojo
// distintas). proveedor_id = transportista (null = vehículo propio); guia_id =
// guía de este vehículo para grupos grandes (el principal sigue en la salida).
class SalidaVehiculo extends Model
{
    protected $table = 'salida_vehiculos';

    protected $fillable = [
        'salida_operativa_id',
        'proveedor_id',
        'descripcion',
        'chofer_nombre',
        'chofer_telefono',
        'capacidad',
        'guia_id',
        'notas',
        'orden',
    ];

    protected $casts = [
        'capacidad' => 'integer',
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

    public function guia()
    {
        return $this->belongsTo(Guia::class, 'guia_id');
    }

    // Las reservas que lleva este vehículo.
    public function reservas()
    {
        return $this->belongsToMany(Reserva::class, 'salida_vehiculo_reservas', 'salida_vehiculo_id', 'reserva_id')->withTimestamps();
    }
}
