<?php

namespace App\Models\AdminPortal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;


class SystemCategory extends Model
{
    use SoftDeletes;
    protected $table = 'system_categories';// Nombre de la tabla
    protected $fillable = [// Campos de la tabla
        'nombre',
        'slug',
        'icon',
        'imagen',
        'state',
        'display_order',
    ];

    public function setCreatedAtAttribute($value)
    {
        date_default_timezone_set('America/Lima');
        $this->attributes["created_at"] = Carbon::now();
    }

    public function setUpdatedAtAttribute($value)
    {
        date_default_timezone_set("America/Lima");
        $this->attributes["updated_at"] = Carbon::now();
    }
        // Relación con sistemas (opcional)
    public function systems()
    {
       // return $this->hasMany(System::class, 'system_category_id');
    }
}
