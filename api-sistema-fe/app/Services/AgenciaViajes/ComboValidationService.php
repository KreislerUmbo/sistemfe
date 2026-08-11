<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\PaquetePlantilla;
use Illuminate\Support\Collection;

// Reglas de negocio de paquete_combo — Sesión 11b4
// (plan-modulo-cotizaciones-reservas.md §3.7, diseño que reemplaza la fila
// 11b4 original — tabla `tours` separada + proveedor_tarifas.tour_id, nunca
// implementado). Servicio de dominio puro (sin Request/Response), mismo
// criterio que PriceEngineService — reusable entre
// PaquetePlantillaController/PaquetePlantillaItemController sin duplicar
// las reglas. Cada método devuelve un mensaje de error (string) o null si es
// válido — el controller decide el código HTTP (siempre 422 en este
// vertical para reglas de negocio).
class ComboValidationService
{
    // Regla de la migración de Sesión 6 (paquete_plantilla_items), extendida
    // en 11b4 con la tercera opción: exactamente uno de los tres debe estar
    // lleno.
    public function validarExclusividadMutua(?int $proveedorTarifaId, ?int $guiaTarifaId, ?int $paquetePlantillaHijoId): ?string
    {
        $llenos = array_filter(
            [$proveedorTarifaId, $guiaTarifaId, $paquetePlantillaHijoId],
            fn ($v) => $v !== null
        );

        if (count($llenos) !== 1) {
            return 'Elegí exactamente uno: proveedor_tarifa_id, guia_tarifa_id o paquete_plantilla_hijo_id — no más de uno ni ninguno.';
        }

        return null;
    }

    // Profundidad máxima real: paquete_combo → tour_simple → ítems atómicos.
    // - Un ítem con paquete_plantilla_hijo_id lleno solo es válido si el
    //   padre es tipo=paquete_combo Y el hijo referenciado es tipo=tour_simple.
    // - Un tour_simple NUNCA puede tener ítems con paquete_plantilla_hijo_id
    //   lleno (no puede incluir otro tour ni otro combo).
    public function validarProfundidad(PaquetePlantilla $padre, ?PaquetePlantilla $hijo): ?string
    {
        if ($hijo === null) {
            return null;
        }

        if ($padre->esTourSimple()) {
            return 'Un tour simple no puede incluir otro tour ni un combo como ítem — solo un paquete_combo puede tener tours-hijo.';
        }

        if ($hijo->id === $padre->id) {
            return 'Un combo no puede incluirse a sí mismo.';
        }

        if (! $hijo->esTourSimple()) {
            return 'El paquete incluido como ítem debe ser un tour simple, no otro combo (profundidad máxima permitida: combo → tour_simple → ítems).';
        }

        return null;
    }

    // Bloqueo duro (422) de duplicados dentro del MISMO paquete_plantilla —
    // Sesión 11n. Sin caso de uso legítimo (a diferencia del margen mínimo,
    // que es advisory): agregar dos veces la misma proveedor_tarifa_id/
    // guia_tarifa_id/paquete_plantilla_hijo_id al mismo tour/paquete es
    // siempre un error de captura. No es global — un mismo proveedor_tarifa
    // puede repetirse legítimamente entre 2 tours-hijo distintos del mismo
    // combo, porque items() ya filtra por paquete_plantilla_id.
    public function validarNoDuplicado(PaquetePlantilla $paquete, ?int $proveedorTarifaId, ?int $guiaTarifaId, ?int $paquetePlantillaHijoId): ?string
    {
        $query = $paquete->items();

        if ($proveedorTarifaId !== null) {
            $query->where('proveedor_tarifa_id', $proveedorTarifaId);
        } elseif ($guiaTarifaId !== null) {
            $query->where('guia_tarifa_id', $guiaTarifaId);
        } elseif ($paquetePlantillaHijoId !== null) {
            $query->where('paquete_plantilla_hijo_id', $paquetePlantillaHijoId);
        } else {
            return null;
        }

        if ($query->exists()) {
            return 'Este servicio ya está incluido en este tour/paquete.';
        }

        return null;
    }

    // Piso de margen del combo tras el descuento — (venta_neta - costo_total)
    // / costo_total no puede ser menor a margen_minimo_pct. null (sin error)
    // si el combo no tiene piso configurado o si costo_total es 0.
    public function validarMargenMinimo(float $costoTotal, float $ventaNeta, ?float $margenMinimoPct): ?string
    {
        if ($margenMinimoPct === null || $costoTotal <= 0) {
            return null;
        }

        $margenResultante = (($ventaNeta - $costoTotal) / $costoTotal) * 100;

        if ($margenResultante < $margenMinimoPct - 0.005) {
            return sprintf(
                'El descuento configurado deja un margen de %.2f%% sobre el costo del combo, menor al mínimo permitido (%.2f%%).',
                $margenResultante,
                $margenMinimoPct
            );
        }

        return null;
    }

    // Combos activos que quedarían afectados si se desactiva este tour_simple
    // — punto 10 del diseño. Usado por PaquetePlantillaController::update()
    // antes de aplicar activo=false sobre un tour_simple.
    public function combosActivosQueLoIncluyen(PaquetePlantilla $tourSimple): Collection
    {
        return $tourSimple->itemsComoHijo()
            ->whereHas('paquetePlantilla', function ($q) {
                $q->where('tipo', PaquetePlantilla::TIPO_PAQUETE_COMBO)->where('activo', true);
            })
            ->with('paquetePlantilla')
            ->get()
            ->pluck('paquetePlantilla')
            ->unique('id')
            ->values();
    }
}
