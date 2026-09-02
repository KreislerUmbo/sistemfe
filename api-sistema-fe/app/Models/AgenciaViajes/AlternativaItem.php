<?php

namespace App\Models\AgenciaViajes;

use Illuminate\Database\Eloquent\Model;

// Ítem atómico de una alternativa — plan-modulo-cotizaciones-reservas.md
// §3.1/§3.3. Tenant (sin CentralConnection). alternativa_id/proveedor_tarifa_id/
// opcion_mayorista_id llevan belongsTo real (FK dentro de la misma DB
// tenant — opcion_mayorista_id cerrada vía retrofit en Sesión 7b, ver
// 2026_07_28_100300_add_opcion_mayorista_foreign_to_alternativa_items_table.php).
//
// origen_tipo/cantidad/descripcion_manual: RETROFIT Sesión 11b (ver
// 2026_07_28_180000_add_origen_tipo_cantidad_descripcion_manual_to_alternativa_items_table.php).
//
// origen_tipo='hotel_plantilla' (Sesión 11k) fue eliminado en la
// consolidación de hoteles: un hotel ya no cuelga de un paquete_plantilla,
// es una proveedor_tarifa más (origen_tipo=proveedor). Las columnas
// opcion_hotel_tarifa_id/paquete_plantilla_id de esta tabla siguen
// existiendo en la BD (fuera de alcance de esa migración) pero quedaron
// muertas — ningún código las escribe ya.
class AlternativaItem extends Model
{
    protected $table = 'alternativa_items';

    public const ORIGEN_PROVEEDOR = 'proveedor';
    public const ORIGEN_MAYORISTA = 'mayorista';
    public const ORIGEN_PASAJE_AEREO = 'pasaje_aereo';
    public const ORIGEN_MANUAL = 'manual';
    public const ORIGEN_GUIA = 'guia';

    public const ORIGENES = [
        self::ORIGEN_PROVEEDOR,
        self::ORIGEN_MAYORISTA,
        self::ORIGEN_PASAJE_AEREO,
        self::ORIGEN_MANUAL,
        self::ORIGEN_GUIA,
    ];

    protected $fillable = [
        'alternativa_id',
        'alternativa_destino_id',
        'origen_tipo',
        'proveedor_tarifa_id',
        'opcion_mayorista_id',
        'grupo_opcion_id',
        'opcion_elegida',
        'guia_tarifa_id',
        'tour_origen_id',
        'dia_referencial',
        'descripcion_manual',
        'proveedor_sugerido_manual',
        'proveedor_promovido_id',
        'modo_precio',
        'cantidad',
        'pax_incluidos',
        'moneda_costo',
        'costo_snapshot',
        'precio_venta_snapshot',
        'descuento_pct',
        'precio_convertido',
        'tip_afe_igv',
        'destino_tributario',
    ];

    protected $casts = [
        'pax_incluidos' => 'array',
        'cantidad' => 'integer',
        'dia_referencial' => 'integer',
        'costo_snapshot' => 'decimal:2',
        'precio_venta_snapshot' => 'decimal:2',
        'descuento_pct' => 'decimal:2',
        'precio_convertido' => 'decimal:2',
        'opcion_elegida' => 'boolean',
    ];

    protected $appends = ['total', 'total_convertido'];

    public function alternativa()
    {
        return $this->belongsTo(Alternativa::class, 'alternativa_id');
    }

    // Sesión 12c — nullable a propósito: hoy solo los ítems ya existentes
    // al migrar (backfill) y los clonados por AlternativaController::
    // duplicar() lo tienen resuelto. Los 9 puntos de creación individual
    // de ítems (AlternativaItemController/ComboExplosionService) todavía
    // no lo setean — eso queda para 12f, que es donde recién se empieza a
    // leer este dato (subtotal por destino).
    public function alternativaDestino()
    {
        return $this->belongsTo(AlternativaDestino::class, 'alternativa_destino_id');
    }

    public function proveedorTarifa()
    {
        return $this->belongsTo(ProveedorTarifa::class, 'proveedor_tarifa_id');
    }

    public function opcionMayorista()
    {
        return $this->belongsTo(OpcionMayorista::class, 'opcion_mayorista_id');
    }

    // Sesión fix/guia-como-item-real — de qué guia_tarifa vino el costo de
    // este ítem (origen_tipo=guia). QUÉ guía puntual del catálogo termina
    // asignado sigue siendo una decisión de reserva (reserva_items.guia_id),
    // esto solo trae el costo/margen a la cotización.
    public function guiaTarifa()
    {
        return $this->belongsTo(GuiaTarifa::class, 'guia_tarifa_id');
    }

    // Sesión 11b4 — tour_simple de origen cuando este ítem vino de explotar
    // un paquete_combo (ComboExplosionService). Null si el ítem es manual o
    // no vino de un combo.
    public function tourOrigen()
    {
        return $this->belongsTo(PaquetePlantilla::class, 'tour_origen_id');
    }

    public function cotizacionPasajeAereo()
    {
        return $this->hasOne(CotizacionPasajeAereo::class, 'alternativa_item_id');
    }

    // Auditoría del módulo Reservas/Cotizador (2026-08-27) — antes de esto
    // el frontend no tenía forma de saber si un ítem ya generó una reserva
    // (el guard existe hace tiempo en destroy()/actualizarManual()/
    // actualizarPasajeAereo(), pero solo se veía como error DESPUÉS de
    // intentar la acción). hasOne: crearReservaItemDesdeAlternativaItem()
    // solo crea un ReservaItem por AlternativaItem, nunca más de uno.
    public function reservaItem()
    {
        return $this->hasOne(ReservaItem::class, 'alternativa_item_id');
    }

    // Sesión 11q — proveedor real creado a partir de este ítem manual (ver
    // AlternativaItemController::promoverAProveedor()). Puramente
    // informativo: este ítem sigue siendo 'manual', sin relink a
    // proveedor_tarifa_id — la cotización actual no se mueve.
    public function proveedorPromovido()
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_promovido_id');
    }

    // Total del ítem (sección 3 del plan): solo modo_precio='tarifa_fija'
    // multiplica por cantidad — 'por_persona' ya viene resuelto en
    // precio_venta_snapshot (repartido entre pax_incluidos al crearse),
    // multiplicar de nuevo sería duplicar el cálculo. Sesión 11q: 'manual'
    // dejó de ser una excepción — ahora sigue la misma regla que el resto
    // (antes siempre ignoraba cantidad, sin efecto real posible porque
    // cantidad quedaba hardcodeada en 1).
    public function getTotalAttribute(): float
    {
        $precio = (float) $this->precio_venta_snapshot;

        if ($this->modo_precio === 'tarifa_fija') {
            return $precio * $this->cantidad;
        }

        return $precio;
    }

    // Igual que total(), pero sobre precio_convertido (post-descuento, en
    // moneda_cotizacion de la alternativa) — es lo que realmente se suma
    // para el panel de precio en vivo (§7.1). precio_convertido es unitario,
    // igual que precio_venta_snapshot (ver comentario de la migración de
    // 'cantidad'), así que se multiplica con la misma regla.
    public function getTotalConvertidoAttribute(): float
    {
        $precio = (float) ($this->precio_convertido ?? $this->precio_venta_snapshot);

        if ($this->modo_precio === 'tarifa_fija') {
            return $precio * $this->cantidad;
        }

        return $precio;
    }

    // Sesión M1 (matriz de opciones de hotel, plan-matriz-hoteles-
    // cotizador.md Ronda 1-2) — separa una colección de ítems en "sin
    // grupo" (comportamiento sin cambios) y "grupos" (opciones
    // intercambiables entre sí, mismo grupo_opcion_id). Punto único de
    // esta lógica de agrupación — reusado por el guard de aceptar(), el
    // recálculo de precio en vivo, y el reparto de descuento_global_pct,
    // para no reimplementarla en cada caller.
    public static function agruparPorGrupoOpcion(\Illuminate\Support\Collection $items): array
    {
        return [
            'sinGrupo' => $items->whereNull('grupo_opcion_id')->values(),
            'grupos' => $items->whereNotNull('grupo_opcion_id')
                ->groupBy('grupo_opcion_id')
                ->map(fn (\Illuminate\Support\Collection $itemsDelGrupo) => [
                    'grupo_opcion_id' => $itemsDelGrupo->first()->grupo_opcion_id,
                    'items' => $itemsDelGrupo->values(),
                ])
                ->values(),
        ];
    }

    // Total "efectivo" de una colección de ítems (lo que realmente se
    // suma para el panel de precio en vivo / alternativas.total) — Ronda
    // 2/P5-P6: ítems sin grupo se suman como siempre; dentro de un grupo
    // RESUELTO (una fila opcion_elegida=true) solo esa fila cuenta, el
    // resto del grupo existe pero no suma; dentro de un grupo ABIERTO
    // (ninguna elegida) se suma el MÍNIMO total_convertido del grupo —
    // válido porque un ítem de grupo nunca recibe descuento (ver
    // AlternativaController::aplicarDescuentoGlobal()), así que su
    // total_convertido YA ES precio de lista convertido, sin volver a
    // convertir moneda acá. Si un grupo tiene 2+ elegidas (estado
    // corrupto que aceptar() bloquea, pero puede existir transitoriamente
    // antes de resolverse) se toma la primera — el guard es lo que
    // realmente impide que esto llegue a producción, no este cálculo.
    public static function calcularTotalEfectivo(\Illuminate\Support\Collection $items): array
    {
        ['sinGrupo' => $sinGrupo, 'grupos' => $grupos] = self::agruparPorGrupoOpcion($items);

        $total = (float) $sinGrupo->sum(fn (self $item) => $item->total_convertido);
        $tieneGruposSinResolver = false;

        foreach ($grupos as $grupo) {
            $elegida = $grupo['items']->firstWhere('opcion_elegida', true);

            if ($elegida) {
                $total += (float) $elegida->total_convertido;
            } else {
                $tieneGruposSinResolver = true;
                $total += (float) $grupo['items']->min(fn (self $item) => $item->total_convertido);
            }
        }

        return ['total' => round($total, 2), 'tiene_grupos_sin_resolver' => $tieneGruposSinResolver];
    }
}
