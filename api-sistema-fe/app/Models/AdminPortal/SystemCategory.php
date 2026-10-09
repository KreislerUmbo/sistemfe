<?php

namespace App\Models\AdminPortal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class SystemCategory extends Model
{
    use SoftDeletes, CentralConnection;
    protected $table = 'system_categories';// Nombre de la tabla
    protected $fillable = [// Campos de la tabla
        'nombre',
        'slug',
        'icon',
        'imagen',
        'state',
        'display_order',
    ];

        // Relación con sistemas (opcional)
    public function systems()
    {
       // return $this->hasMany(System::class, 'system_category_id');
    }
}
