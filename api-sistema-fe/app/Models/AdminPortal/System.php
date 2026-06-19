<?php

namespace App\Models\AdminPortal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;


class System extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description_short',
        'description_long',
        'icon_emoji',
        'badge',
        'imagen_principal',
        'rating_prom',
        'cantidad_clientes',
        'es_destacado',
        'is_active',
        'metadata',
    ];
    
    protected $casts = [
        // 'metadata' => 'array',
        'es_destacado' => 'boolean',
        'is_active' => 'boolean',
        'rating_prom' => 'decimal:1',
    ];



    // Relaciones
    public function category()
    {
        return $this->belongsTo(SystemCategory::class, 'system_category_id');
    }

    public function plans()
    {
        // return $this->hasMany(Plan::class);
    }

    public function modules()
    {
        //  return $this->hasMany(SystemModule::class);
    }

    public function features()
    {
        //  return $this->hasMany(SystemFeature::class);
    }
    public function media()
    {
        // return $this->hasMany(SystemMedia::class);
    }
    // Mutators para zona horaria
    public function setCreatedAtAttribute($value)
    {
        date_default_timezone_set('America/Lima');
        $this->attributes['created_at'] = Carbon::now();
    }

    public function setUpdatedAtAttribute($value)
    {
        date_default_timezone_set('America/Lima');
        $this->attributes['updated_at'] = Carbon::now();
    }
}
