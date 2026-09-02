<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

// Alternativa de cotización (combinación completa de paquete) —
// plan-modulo-cotizaciones-reservas.md §3.1/§3.2. Tenant (sin
// CentralConnection). cotizacion_id lleva belongsTo real (FK dentro de la
// misma DB tenant).
class Alternativa extends Model
{
    protected $table = 'alternativas';

    protected $fillable = [
        'cotizacion_id',
        'nombre',
        'estado',
        'moneda_cotizacion',
        'tipo_cambio_aplicado',
        'tipo_cambio_origen',
        'fecha_envio',
        'fecha_vencimiento',
        'descuento_global_pct',
        'total',
    ];

    protected $casts = [
        'tipo_cambio_aplicado' => 'decimal:4',
        'fecha_envio' => 'datetime',
        'fecha_vencimiento' => 'datetime',
        'descuento_global_pct' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    // Sesión M1 (matriz de opciones de hotel) — true si algún grupo de
    // opciones (alternativa_items.grupo_opcion_id) todavía no tiene
    // ninguna fila opcion_elegida=true. El frontend (M4) lo usa para
    // mostrar "desde $X" en vez de un total cerrado — requiere items()
    // cargado (lazy-load si no vino eager, mismo costo que cualquier
    // accessor que navega una relación; en la práctica esta atributo solo
    // se serializa donde ya se carga la alternativa completa con sus
    // ítems, no en listados livianos como CotizacionController::index()).
    protected function tieneGruposSinResolver(): Attribute
    {
        return Attribute::make(
            get: fn () => AlternativaItem::calcularTotalEfectivo($this->items)['tiene_grupos_sin_resolver'],
        );
    }

    protected $appends = ['tiene_grupos_sin_resolver'];

    public function cotizacion()
    {
        return $this->belongsTo(Cotizacion::class, 'cotizacion_id');
    }

    // orderBy('id') explícito: sin esto, Postgres no garantiza el orden de
    // fila sin ORDER BY, y un UPDATE (ej. editar precio) puede reubicar
    // físicamente la fila — el ítem "saltaba" de posición en el lienzo del
    // cotizador justo después de guardar su precio (bug real reportado por
    // el usuario 2026-08-28). No hay columna de orden propia — 'id' refleja
    // el orden de alta, que es el criterio que ya espera el lienzo.
    public function items()
    {
        return $this->hasMany(AlternativaItem::class, 'alternativa_id')->orderBy('id');
    }

    // Sesión 11c — usada al aceptar una alternativa para saber si hay que
    // mover cupo_ocupado en salidas_mayorista (§4/§4.2). No hay CHECK ni
    // índice único que garantice como máximo una 'elegida' por
    // alternativa — la garantía la da OpcionMayoristaController::elegir()
    // (desmarca la anterior antes de marcar la nueva).
    public function opcionMayoristaElegida()
    {
        return $this->hasOne(OpcionMayorista::class, 'alternativa_id')->where('estado', 'elegida');
    }

    // Sesión 12b — mismo motivo que items(): orderBy('orden') explícito,
    // no confiar en el orden físico de Postgres para "Tarapoto → México →
    // Cancún...". AlternativaItem/OpcionMayorista todavía no cuelgan de
    // acá (12c/12d) — hoy siempre hay exactamente 1 fila por alternativa
    // (backfill de esta misma sesión).
    public function destinos()
    {
        return $this->hasMany(AlternativaDestino::class, 'alternativa_id')->orderBy('orden')->orderBy('id');
    }
}
