<?php

declare(strict_types=1);

namespace App\Models\Creditos;

use App\Enums\Creditos\PrendaEstado;
use App\Enums\Creditos\PrendaTipo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Prenda en garantía (1.11). */
class Prenda extends Model
{
    protected $table = 'prendas';

    protected $fillable = [
        'credito_id',
        'tipo',
        'descripcion',
        'marca',
        'modelo',
        'serie',
        'color',
        'accesorios',
        'estado_fisico',
        'ubicacion_custodia',
        'valor_tasado',
        'estado',
        'fecha_ingreso',
        'fecha_devolucion',
        'fecha_venta',
        'precio_venta',
        'excedente_por_devolver',
        'fecha_devolucion_excedente',
        'cash_movement_venta_id',
        'cash_movement_excedente_id',
        'placa',
        'anio',
        'tarjeta_propiedad_recibida',
        'llaves_recibidas',
        'registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => PrendaTipo::class,
            'valor_tasado' => 'decimal:2',
            'estado' => PrendaEstado::class,
            'fecha_ingreso' => 'date',
            'fecha_devolucion' => 'date',
            'fecha_venta' => 'date',
            'precio_venta' => 'decimal:2',
            'excedente_por_devolver' => 'decimal:2',
            'fecha_devolucion_excedente' => 'date',
            'tarjeta_propiedad_recibida' => 'boolean',
            'llaves_recibidas' => 'boolean',
        ];
    }

    public function credito(): BelongsTo
    {
        return $this->belongsTo(Credito::class, 'credito_id');
    }

    public function fotos(): HasMany
    {
        return $this->hasMany(PrendaFoto::class, 'prenda_id');
    }
}
