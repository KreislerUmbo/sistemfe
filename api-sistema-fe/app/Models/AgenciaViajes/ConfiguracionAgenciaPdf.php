<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Configuración de marca del PDF de cotización — singleton por tenant,
// mismo patrón que ConfiguracionAgencia (la migración inserta la fila
// default en up()). Tenant (sin CentralConnection).
// plan-mejora-pdf-cotizacion-cliente.md §4.1/§4.2.
class ConfiguracionAgenciaPdf extends Model
{
    protected $table = 'configuracion_agencia_pdf';

    protected $fillable = [
        'color_primario',
        'color_secundario',
        'color_categoria_local',
        'color_categoria_nacional',
        'color_categoria_internacional',
        'eslogan',
        'redes_sociales',
        'mostrar_fotos_tour',
        'mostrar_afiliaciones',
        'imagen_header_custom',
        'imagen_footer_custom',
    ];

    protected $casts = [
        'redes_sociales' => 'array',
        'mostrar_fotos_tour' => 'boolean',
        'mostrar_afiliaciones' => 'boolean',
    ];

    // Único punto de lectura del branding del PDF — igual criterio que
    // ConfiguracionAgencia::tratamientoTributarioDefault(): devuelve
    // colores neutros de sistema si el tenant nunca guardó fila (no
    // debería pasar, es singleton desde la migración, pero evita un
    // null-pointer en tenants de prueba sin seed).
    public static function actual(): self
    {
        return static::first() ?? new self([
            'color_primario' => '#1f2937',
            'color_secundario' => '#4b5563',
            'color_categoria_local' => '#2563eb',
            'color_categoria_nacional' => '#1f2937',
            'color_categoria_internacional' => '#7c3aed',
            'mostrar_fotos_tour' => true,
            'mostrar_afiliaciones' => false,
        ]);
    }

    public function afiliaciones()
    {
        return $this->hasMany(ConfiguracionAgenciaAfiliacion::class, 'configuracion_agencia_pdf_id');
    }
}
