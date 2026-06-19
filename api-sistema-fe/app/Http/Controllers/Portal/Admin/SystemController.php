<?php

namespace App\Http\Controllers\Portal\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminPortal\System;
use App\Models\AdminPortal\SystemCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SystemController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $search = $request->get('search');
        $systems = System::with('category')
            ->where('name', 'ilike', '%' . $search . '%')
            ->orderBy('id', 'desc')
            ->paginate(15);

        return response()->json([
            'total' => $systems->total(),
            'paginate' => 15,
            'systems' => $systems->map(function ($system) {
                return [
                    'id' => $system->id,
                    'name' => $system->name,
                    'slug' => $system->slug,
                    'icon_emoji' => $system->icon_emoji,
                    'badge' => $system->badge,
                    'category' => $system->category ? $system->category->name : null,
                    'category_id' => $system->system_category_id,
                    'description_short' => $system->description_short,
                    'description_long' => $system->description_long,
                    'rating_avg' => $system->rating_avg,
                    'rating_count' => $system->rating_count,
                    'is_featured' => $system->is_featured,
                    'is_active' => $system->is_active,
                    'metadata' => $system->metadata,
                    'created_at' => $system->created_at ? $system->created_at->format('d-m-Y | H:i:s') : null,
                ];
            })
        ]);
    }
    public function config()
    {
        $categories = SystemCategory::where('state', 1)->get();

        return response()->json([
            "categories" => $categories->map(function ($categorie) {
                return [
                    "id" => $categorie->id,
                    "name" => $categorie->nombre,
                ];
            })
        ]);
    }
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Validación
        $request->validate([
            'system_category_id' => 'required|exists:system_categories,id',
            'name' => 'required|string|max:100',
            'description_short' => 'required|string',
            'icon_emoji' => 'nullable|string|max:10',
            'badge' => 'nullable|string|max:30',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
        ]);
        // Generar slug
        $slug = Str::slug($request->name);
        if (System::where('slug', $slug)->exists()) {
            $slug = $slug . '-' . uniqid();
        }

        $system = System::create(array_merge($request->all(), ['slug' => $slug]));

        return response()->json([
            'code' => 200,
            'message' => 'Sistema creado correctamente',
            'system' => $system
        ]);
    }


    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $system = System::with('category')->findOrFail($id);
        return response()->json([
            'system' => [
                'id' => $system->id,
                'system_category_id' => $system->system_category_id,
                'name' => $system->name,
                'slug' => $system->slug,
                'description_short' => $system->description_short,
                'description_long' => $system->description_long,
                'icon_emoji' => $system->icon_emoji,
                'badge' => $system->badge,
                'rating_avg' => $system->rating_avg,
                'rating_count' => $system->rating_count,
                'is_featured' => $system->is_featured,
                'is_active' => $system->is_active,
                'metadata' => $system->metadata,
                'category' => $system->category ? $system->category->name : null,
            ]
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $system = System::findOrFail($id);

        $request->validate([
            'system_category_id' => 'sometimes|exists:system_categories,id',
            'name' => 'sometimes|string|max:100',
            'description_short' => 'sometimes|string',
            'icon_emoji' => 'nullable|string|max:10',
            'badge' => 'nullable|string|max:30',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
        ]);

        // Actualizar slug si cambia el nombre
        if ($request->has('name') && $request->name !== $system->name) {
            $slug = Str::slug($request->name);
            if (System::where('slug', $slug)->where('id', '!=', $id)->exists()) {
                $slug = $slug . '-' . uniqid();
            }
            $system->slug = $slug;
        }

        $system->update($request->all());

        return response()->json([
            'code' => 200,
            'message' => 'Sistema actualizado correctamente',
            'system' => $system
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $system = System::findOrFail($id);
        $system->delete();
        return response()->json([
            'code' => 200,
            'message' => 'Sistema eliminado correctamente'
        ]);
    }
}
