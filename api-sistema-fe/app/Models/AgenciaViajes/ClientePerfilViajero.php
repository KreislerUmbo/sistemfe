<?php

namespace App\Models\AgenciaViajes;

use App\Models\Client\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

// T-060 (CRM C5) — preferencias del viajero de un cliente (una fila por cliente, vertical Agencia).
// tipos_viaje = ids de crm_tipos_viaje. Se edita en la ficha del cliente y el cotizador la muestra
// en solo lectura (PreferenciasViajeroResumen.vue).
class ClientePerfilViajero extends Model
{
    public const MONEDAS = ['PEN', 'USD'];
    public const TEXTO_MAX = 150;
    public const NOTAS_MAX = 2000;

    protected $table = 'cliente_perfil_viajero';

    protected $fillable = [
        'cliente_id',
        'tipos_viaje',
        'presupuesto_por_persona',
        'moneda',
        'alimentacion',
        'movilidad',
        'hotel_preferido',
        'notas',
        'actualizado_por',
    ];

    protected $casts = [
        'tipos_viaje' => 'array',
        'presupuesto_por_persona' => 'decimal:2',
    ];

    public function cliente()
    {
        return $this->belongsTo(Client::class, 'cliente_id');
    }

    public function actualizadoPor()
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }

    // Un perfil sin ningún dato no se muestra en el cotizador (recuadro vacío).
    public function estaVacio(): bool
    {
        return empty($this->tipos_viaje)
            && $this->presupuesto_por_persona === null
            && blank($this->alimentacion) && blank($this->movilidad)
            && blank($this->hotel_preferido) && blank($this->notas);
    }
}
