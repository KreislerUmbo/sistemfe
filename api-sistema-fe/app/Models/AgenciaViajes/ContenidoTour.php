<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Sesión 12e — contenido reutilizable (descripción/fotos) desacoplado del
// precio del mayorista (auditoria-arquitectonica-agencia-viajes.md §9.1).
// SIN precio, SIN moneda, SIN vigencia a propósito — eso vive siempre en
// la oferta del mayorista (OpcionMayorista/OpcionMayoristaOpcional, que
// SNAPSHOTEAN descripcion/fotos al vincular — nunca leen esto en vivo).
class ContenidoTour extends Model
{
    protected $table = 'contenido_tour';

    public const CATEGORIA_INCLUIDO = 'incluido';
    public const CATEGORIA_OPCIONAL = 'opcional';
    public const CATEGORIA_EXCURSION = 'excursion';

    public const CATEGORIAS = [
        self::CATEGORIA_INCLUIDO,
        self::CATEGORIA_OPCIONAL,
        self::CATEGORIA_EXCURSION,
    ];

    protected $fillable = [
        'destino_atractivo_id',
        'categoria',
        'nombre',
        'descripcion',
        'incluye',
        'no_incluye',
        'fotos',
        'activo',
    ];

    protected $casts = [
        'fotos' => 'array',
        'activo' => 'boolean',
    ];

    public function destinoAtractivo()
    {
        return $this->belongsTo(DestinoAtractivo::class, 'destino_atractivo_id');
    }
}
