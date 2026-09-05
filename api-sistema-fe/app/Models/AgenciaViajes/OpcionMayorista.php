<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Comparación entre mayoristas dentro de una alternativa —
// plan-modulo-cotizaciones-reservas.md §2.4. Tenant (sin CentralConnection).
// alternativa_id/proveedor_id/salida_mayorista_id llevan belongsTo real (FK
// dentro de la misma DB tenant).
//
// descripcion_publica (fix C1, 02-sep-2026): el ÚNICO texto que
// AlternativaController::resolverNombreItemPdf() puede imprimir en el PDF
// comercial para un ítem origen_tipo=mayorista — nunca cae a
// proveedor->razon_social/nombre_comercial (dato fiscal SUNAT del
// mayorista, no debe llegar al cliente). Sin ella, el PDF muestra el
// genérico "Paquete mayorista". ReservaController::resolverNombreItem()
// (uso interno, reporte operativo) NO usa este campo — ahí el fallback a
// datos del proveedor real es correcto y deseado.
class OpcionMayorista extends Model
{
    protected $table = 'opcion_mayorista';

    protected $fillable = [
        'alternativa_id',
        'alternativa_destino_id',
        'proveedor_id',
        'salida_mayorista_id',
        'moneda',
        'incluye',
        'no_incluye',
        'descripcion_publica',
        'notas',
        'vuelo_aerolinea',
        'vuelo_detalle',
        'estado',
        'contenido_tour_id',
        'contenido_tour_descripcion_snapshot',
        'contenido_tour_fotos_snapshot',
    ];

    protected $casts = [
        'contenido_tour_fotos_snapshot' => 'array',
    ];

    public function alternativa()
    {
        return $this->belongsTo(Alternativa::class, 'alternativa_id');
    }

    // Sesión 12d — nullable a propósito, mismo criterio que
    // AlternativaItem::alternativaDestino(): filas de antes de esta
    // sesión y clonadas por duplicar() lo tienen resuelto; alternativa_id
    // sigue siendo la FK "de compatibilidad" que código viejo (ej.
    // OpcionMayoristaController::index()) sigue leyendo sin cambios.
    public function alternativaDestino()
    {
        return $this->belongsTo(AlternativaDestino::class, 'alternativa_destino_id');
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function salidaMayorista()
    {
        return $this->belongsTo(SalidaMayorista::class, 'salida_mayorista_id');
    }

    public function opcionales()
    {
        return $this->hasMany(OpcionMayoristaOpcional::class, 'opcion_mayorista_id');
    }

    public function opcionesHotel()
    {
        return $this->hasMany(OpcionHotel::class, 'opcion_mayorista_id');
    }

    // Tours incluidos con itinerario real (PaquetePlantilla), ver
    // OpcionMayoristaTour — distinto de `incluye` (texto plano, sin días/horas).
    // orderBy('orden') acá (mismo criterio que Alternativa::items()->orderBy('id')):
    // 'orden' es el "Día" que ve el vendedor, así que cualquier lugar que cargue
    // esta relación (listado del drawer, itinerarioAlternativa() del PDF) la ve
    // ya en la secuencia correcta, sin repetir el orderBy en cada caller.
    public function tours()
    {
        return $this->hasMany(OpcionMayoristaTour::class, 'opcion_mayorista_id')->orderBy('orden');
    }

    public function alternativaItems()
    {
        return $this->hasMany(AlternativaItem::class, 'opcion_mayorista_id');
    }

    // Sesión 12e — nullable a propósito, mismo criterio de adopción
    // gradual que el resto de contenido_tour: descripcion/fotos snapshot
    // (no lee ContenidoTour en vivo, ver comentario del modelo).
    public function contenidoTour()
    {
        return $this->belongsTo(ContenidoTour::class, 'contenido_tour_id');
    }
}
