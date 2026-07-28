<?php

namespace App\Models\AgenciaViajes;

use App\Models\Client\Client;
use Illuminate\Database\Eloquent\Model;

// Perfil reutilizable de pasajero, independiente de cualquier reserva
// puntual — plan-modulo-cotizaciones-reservas.md §6.5. Tenant (sin
// CentralConnection). cliente_id lleva belongsTo real a clients (core,
// App\Models\Client\Client) — nullable, no todo pasajero es un cliente
// registrado.
class PasajeroCatalogo extends Model
{
    protected $table = 'pasajeros_catalogo';

    protected $fillable = [
        'cliente_id',
        'nombre',
        'nacionalidad',
        'fecha_nacimiento',
    ];

    protected $casts = [
        'fecha_nacimiento' => 'date',
    ];

    public function cliente()
    {
        return $this->belongsTo(Client::class, 'cliente_id');
    }

    public function documentos()
    {
        return $this->hasMany(PasajeroDocumento::class, 'pasajero_catalogo_id');
    }

    public function reservaPasajeros()
    {
        return $this->hasMany(ReservaPasajero::class, 'pasajero_catalogo_id');
    }
}
