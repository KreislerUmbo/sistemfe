<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\ContenidoTour;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// Sesión 12e — biblioteca de contenido reutilizable (descripción/fotos)
// para tours cotizados por mayoristas, auditoria-arquitectonica-agencia-
// viajes.md §9.1. Solo index()/store() en esta sesión — es lo mínimo que
// necesita el flujo "buscar antes de crear" del cotizador
// (cotizador/editar.vue, formMayorista). update()/destroy() quedan fuera
// a propósito, sin pantalla de gestión todavía.
class ContenidoTourController extends Controller
{
    // Buscador: sin paginación compleja, la lista de resultados de un
    // buscador de biblioteca siempre es corta. Sin filtro de destino
    // todavía (§0/§1 del brief — el cotizador no expone el destino activo
    // de forma confiable hasta 12f).
    public function index(Request $request)
    {
        $query = ContenidoTour::where('activo', true);

        if ($request->filled('categoria')) {
            $query->where('categoria', $request->input('categoria'));
        }

        $search = $request->input('q', $request->input('search'));
        if ($search) {
            $query->whereRaw('LOWER(nombre) LIKE ?', ['%' . mb_strtolower($search) . '%']);
        }

        return response()->json(['contenido_tour' => $query->orderBy('nombre')->limit(20)->get()]);
    }

    // Rechaza duplicado (case-insensitive + trim) dentro de la misma
    // categoria — mismo criterio que el fix de duplicados de
    // ServicioController (§23.1.9, punto 9 de la auditoría).
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:250',
            'categoria' => 'required|in:' . implode(',', ContenidoTour::CATEGORIAS),
            'destino_atractivo_id' => 'nullable|integer|exists:destinos_atractivos,id',
            'descripcion' => 'nullable|string',
            'incluye' => 'nullable|string',
            'no_incluye' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $validado = $validator->validated();

        $duplicado = ContenidoTour::where('categoria', $validado['categoria'])
            ->whereRaw('LOWER(TRIM(nombre)) = LOWER(TRIM(?))', [$validado['nombre']])
            ->exists();

        if ($duplicado) {
            return response()->json([
                'code' => 422,
                'message' => "Ya existe un contenido '{$validado['nombre']}' en esta categoría — buscalo antes de crear uno nuevo.",
            ], 422);
        }

        $contenidoTour = ContenidoTour::create($validado + ['activo' => true]);

        return response()->json(['code' => 200, 'message' => 'Contenido creado correctamente', 'contenido_tour' => $contenidoTour]);
    }
}
