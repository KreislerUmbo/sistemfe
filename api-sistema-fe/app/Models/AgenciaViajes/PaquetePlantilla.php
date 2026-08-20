<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

// "Tour" — plan-modulo-cotizaciones-reservas.md §3.7 +
// plan-modulo-tours-catalogo.md §5 (confirmado 24-jul-2026: misma entidad
// que "tour", validado con documentos reales de la agencia). Tenant (sin
// CentralConnection). destino_atractivo_id lleva belongsTo real (FK dentro
// de la misma DB tenant, Sesión 2).
//
// tipo/activo/descuento_tipo/descuento_valor/margen_minimo_pct: Sesión 11b4
// — distingue 'tour_simple' (lo original) de 'paquete_combo' (agrupa 2+
// tours_simple vía paqueteItems.paquetePlantillaHijo). Ver
// ComboValidationService para las reglas de negocio (exclusividad mutua,
// profundidad máxima 2 niveles, piso de margen) y PriceEngineService para
// el cálculo de precio en vivo del combo.
class PaquetePlantilla extends Model
{
    public const TIPO_TOUR_SIMPLE = 'tour_simple';
    public const TIPO_PAQUETE_COMBO = 'paquete_combo';

    protected $table = 'paquetes_plantilla';

    protected $fillable = [
        'codigo',
        'categoria',
        'tipo',
        'nombre',
        'descripcion',
        'fotos',
        'destino_atractivo_id',
        'duracion_horas',
        'hora_salida',
        'hora_retorno',
        'lugar_recojo',
        'no_incluye',
        'recomendaciones',
        'vuelo_incluido',
        'vuelo_aerolinea',
        'vuelo_detalle',
        'precio_venta_final',
        'vigencia_desde',
        'vigencia_hasta',
        'publicado_web',
        'activo',
        'descuento_tipo',
        'descuento_valor',
        'margen_minimo_pct',
        'ajuste_redondeo',
    ];

    protected $casts = [
        'fotos' => 'array',
        'vuelo_incluido' => 'boolean',
        'precio_venta_final' => 'decimal:2',
        'vigencia_desde' => 'date',
        'vigencia_hasta' => 'date',
        'publicado_web' => 'boolean',
        'activo' => 'boolean',
        'descuento_valor' => 'decimal:2',
        'margen_minimo_pct' => 'decimal:2',
        'ajuste_redondeo' => 'decimal:2',
    ];

    // hora_salida/hora_retorno son columnas `time` de Postgres: Eloquent las
    // devuelve tal cual las guarda la BD ("06:00:00"), pero el validador de
    // store()/update() (PaquetePlantillaController::validarPayload) exige
    // date_format:H:i (sin segundos) — <input type="time"> también trabaja
    // en H:i. Normalizado acá, a nivel de modelo, para que CUALQUIER lugar
    // que lea el paquete (form.vue, detalle.vue, el cotizador al cargar una
    // plantilla, etc.) reciba siempre "HH:MM" sin tener que recordarlo en
    // cada punto de consumo — un fix anterior (dfcdf92) solo normalizaba en
    // el punto de carga de form.vue y volvió a romperse en otro flujo.
    protected function horaSalida(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? substr($value, 0, 5) : $value,
        );
    }

    protected function horaRetorno(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? substr($value, 0, 5) : $value,
        );
    }

    public function destinoAtractivo()
    {
        return $this->belongsTo(DestinoAtractivo::class, 'destino_atractivo_id');
    }

    public function paqueteItinerario()
    {
        return $this->hasMany(TourItinerarioItem::class, 'tour_id');
    }

    public function items()
    {
        return $this->hasMany(PaquetePlantillaItem::class, 'paquete_plantilla_id');
    }

    // Combos que incluyen ESTE paquete como tour-hijo — usado por
    // ComboValidationService::bloqueosPorDesactivar() para listar qué combos
    // activos se verían afectados al desactivar un tour_simple.
    public function itemsComoHijo()
    {
        return $this->hasMany(PaquetePlantillaItem::class, 'paquete_plantilla_hijo_id');
    }

    public function esTourSimple(): bool
    {
        return $this->tipo === self::TIPO_TOUR_SIMPLE;
    }

    public function esPaqueteCombo(): bool
    {
        return $this->tipo === self::TIPO_PAQUETE_COMBO;
    }
}
