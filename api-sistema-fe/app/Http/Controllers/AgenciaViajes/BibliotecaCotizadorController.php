<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\PaquetePlantilla;
use App\Models\AgenciaViajes\ProveedorTarifa;
use App\Services\AgenciaViajes\ComboExplosionService;
use App\Services\StorageUrl;
use Illuminate\Http\Request;

// Biblioteca unificada del cotizador — Sesión 11b3 (Parte A,
// plan-modulo-cotizaciones-reservas.md §7.1). Un único endpoint que decide
// internamente contra qué tabla(s) consulta según `tipo`, en vez de que el
// frontend combine dos llamadas — "Todos" + texto y un tipo específico +
// texto usan el mismo AND, solo cambia qué se consulta, nunca dos modos de
// búsqueda distintos en el cliente.
//
// No reemplaza GET proveedor-tarifas (ProveedorTarifaController::biblioteca())
// — esa ruta sigue usada tal cual por paquetes/detalle.vue (biblioteca de
// "Incluye" al armar un tour, caso distinto de este).
class BibliotecaCotizadorController extends Controller
{
    public function __construct(private ComboExplosionService $comboExplosion)
    {
    }

    public function index(Request $request)
    {
        $tipo = $request->get('tipo', 'todos');
        $search = $request->filled('search') ? (string) $request->get('search') : null;
        $proveedorTipoId = $request->filled('proveedor_tipo_id') ? (int) $request->get('proveedor_tipo_id') : null;

        $resultados = [];

        if (in_array($tipo, ['todos', 'tour', 'paquete'], true)) {
            $resultados = array_merge($resultados, $this->buscarPaquetes($tipo, $search));
        }

        if (in_array($tipo, ['todos', 'proveedor'], true)) {
            $resultados = array_merge($resultados, $this->buscarProveedorTarifas($proveedorTipoId, $search));
        }

        return response()->json(['resultados' => $resultados]);
    }

    // tour/paquete: paquetes_plantilla activos, con resumen_items (badge
    // OBLIGATORIO antes del click — §7.1, "sin esto un click puede inyectar
    // 10 líneas de golpe sin que el vendedor lo anticipe"). tipo_resultado
    // discrimina la tarjeta en el frontend.
    private function buscarPaquetes(string $tipo, ?string $search): array
    {
        $query = PaquetePlantilla::where('activo', true);

        if ($tipo === 'tour') {
            $query->where('tipo', PaquetePlantilla::TIPO_TOUR_SIMPLE);
        } elseif ($tipo === 'paquete') {
            $query->where('tipo', PaquetePlantilla::TIPO_PAQUETE_COMBO);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'ilike', "%{$search}%")->orWhere('codigo', 'ilike', "%{$search}%");
            });
        }

        return $query->orderBy('nombre')->limit(50)->get()
            ->map(function (PaquetePlantilla $paquete) {
                if ($paquete->esPaqueteCombo()) {
                    $tours = $this->comboExplosion->toursDelCombo($paquete);
                    $resumenItems = ['tours' => $tours->count(), 'items' => $tours->sum(fn (PaquetePlantilla $t) => $t->items()->count())];
                } else {
                    $resumenItems = ['tours' => null, 'items' => $paquete->items()->count()];
                }

                $paquete->setAttribute('tipo_resultado', $paquete->esPaqueteCombo() ? 'paquete' : 'tour');
                $paquete->setAttribute('resumen_items', $resumenItems);
                $paquete->setAttribute('fotos', StorageUrl::resolveMuchas($paquete->fotos ?? []));

                return $paquete;
            })
            ->values()
            ->all();
    }

    // Mismo filtro de vigencia que ProveedorTarifaController::biblioteca()
    // — duplicado a propósito, no extraído a un service compartido (mismo
    // criterio ya usado en PaquetePlantillaController::hoteles(): el
    // duplicado es más simple que forzar una abstracción común para algo
    // tan chico). + filtro nuevo opcional por proveedor_tipo_id.
    private function buscarProveedorTarifas(?int $proveedorTipoId, ?string $search): array
    {
        $hoy = now()->toDateString();

        $query = ProveedorTarifa::with([
            // alojamientoDetalle — Consolidación de hoteles: el cotizador
            // necesita el tramo de edad de cama adicional del proveedor
            // dueño de esta tarifa (ver clicBibliotecaItem()/matrizHotelActiva
            // en editar.vue), mismo dato que antes vivía en OpcionHotel.
            'proveedorServicio.proveedor.alojamientoDetalle',
            'proveedorServicio.destinoServicio.destinoAtractivo',
            'proveedorServicio.destinoServicio.servicio',
        ])
            ->where('vigente_desde', '<=', $hoy)
            ->where(fn ($q) => $q->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', $hoy))
            // Sesión 11k, Fix 8 — mismo filtro que ProveedorTarifaController::biblioteca().
            ->whereHas('proveedorServicio.proveedor', fn ($q) => $q->where('estado', true));

        if ($proveedorTipoId) {
            $query->whereHas('proveedorServicio.proveedor', fn ($q) => $q->where('tipo_id', $proveedorTipoId));
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('proveedorServicio.proveedor', fn ($qq) => $qq->where('razon_social', 'ilike', "%{$search}%")->orWhere('nombre_comercial', 'ilike', "%{$search}%"))
                    ->orWhereHas('proveedorServicio.destinoServicio.servicio', fn ($qq) => $qq->where('nombre', 'ilike', "%{$search}%"));
            });
        }

        return $query->orderByDesc('id')->limit(100)->get()
            ->map(function (ProveedorTarifa $tarifa) {
                $tarifa->setAttribute('tipo_resultado', 'proveedor_tarifa');

                return $tarifa;
            })
            ->values()
            ->all();
    }
}
