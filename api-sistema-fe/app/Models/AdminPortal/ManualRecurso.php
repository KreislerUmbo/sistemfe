<?php

namespace App\Models\AdminPortal;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class ManualRecurso extends Model
{
    use CentralConnection;

    protected $table = 'manual_recursos';

    protected $fillable = [
        'sistema_id',
        'categoria',
        'titulo',
        'descripcion',
        'tipo_recurso',
        'url',
        'archivo',
        'miniatura',
        'orden',
        'destacado',
        'estado',
    ];

    protected $casts = [
        'destacado' => 'boolean',
        'estado' => 'boolean',
    ];

    public function sistema()
    {
        return $this->belongsTo(System::class, 'sistema_id');
    }
}
