<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// T-133 (plan-tours-venta-simple-costo-real.md §3.7c) — el proveedor es del sistema base: un solo
// catálogo para Caja, Compras, Rendiciones y Agencia, en todos los giros. Acá van los datos
// básicos; lo turístico (servicios, tarifas, hotel, fotos) vive en la subclase
// App\Models\AgenciaViajes\Proveedor, sobre la misma tabla.
class Proveedor extends Model
{
    protected $table = 'proveedores';

    // Lo que un proveedor muestra y acepta fuera de Agencia (sin márgenes, fotos ni tipo).
    public const CAMPOS_BASICOS = [
        'razon_social',
        'nombre_comercial',
        'tipo_persona',
        'tipo_documento',
        'numero_documento',
        'direccion',
        'telefono',
        'celular',
        'email',
        'observaciones',
        'estado',
    ];

    protected $fillable = self::CAMPOS_BASICOS;

    protected $casts = [
        'estado' => 'boolean',
        'es_referencial' => 'boolean',
    ];
}
