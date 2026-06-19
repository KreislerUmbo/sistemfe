<?php

namespace App\Http\Controllers\Portal\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminPortal\SystemCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SystemCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $search = $request->get('search');
        $categories = SystemCategory::where('nombre', 'ilike', '%' . $search . '%')
            ->orderBy('id', 'desc')
            ->paginate(15);

        return response()->json([
            'total' => $categories->total(),
            'paginate' => 15,
            'categories' => $categories->map(function ($categorie) {
                return [
                    'id' => $categorie->id,
                    'nombre' => $categorie->nombre,
                    'icon' => $categorie->icon,
                    'imagen' => $categorie->imagen ? env('APP_URL') . 'storage/' . $categorie->imagen : null,
                    'state' => $categorie->state,
                    'display_order' => $categorie->display_order,
                    'created_at' => $categorie->created_at? $categorie->created_at->format('d-m-Y | H:i:s'): null,
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
            'nombre' => 'required|string|max:50|unique:system_categories,nombre',
            'icon' => 'nullable|string|max:10',
            'imagen' => 'nullable|string',
            'state' => 'sometimes|numeric|in:1,2',
            'display_order' => 'nullable|integer|min:0',
        ]);

        $is_exist_categorie = SystemCategory::where('nombre', $request->nombre)->first();
        if ($is_exist_categorie) {
            return response()->json([
                "code" => 405,
                "message" => "La categoria ya existe"
            ]);
        }

        // Generar slug automáticamente
        $slug = Str::slug($request->nombre);
        // Verificar unicidad (si ya existe, agregar un sufijo numérico)
        $count = SystemCategory::where('slug', $slug)->count();
        if ($count > 0) {
            $slug = $slug . '-' . ($count + 1);
        }


        if ($request->hasFile('image')) {
            $path = Storage::putFile("system_categories", $request->file("image"));
            $request->merge(['imagen' => $path]);
        }
        $category = SystemCategory::create(array_merge(
            $request->all(),
            ['slug' => $slug]
        ));

        return response()->json(
            [
                "code" => 200,
                "message" => "Categoria creada correctamente",
                "category" =>   [
                    'id' => $category->id,
                    'nombre' => $category->nombre,
                    'icon' => $category->icon,
                    'display_order' => $category->display_order,
                    'imagen' => env('APP_URL') . "storage/" . $category->imagen,
                    'state' => $category->state,
                    'created_at' => $category->created_at,
                ]
            ]
        );
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id) {}

    public function update(Request $request, string $id)
    {
        $is_exist_category = SystemCategory::where('id', '<>', $id)->where('nombre', $request->nombre)->first();
        if ($is_exist_category) {
            return response()->json([
                "code" => 405,
                "message" => "La categoria ya existe"
            ]);
        }

        $category = SystemCategory::find($id);
        if ($request->hasFile('image')) {
            if ($category->image && Storage::exists($category->image)) { //Storage::exists verifica si el archivo existe en el storage
                Storage::delete($category->image);
            }

            $path = Storage::putFile("system_categories", $request->file("image"));
            $request->merge(['imagen' => $path]);
        }

        // Generar slug automáticamente
        $slug = Str::slug($request->nombre); //Str es una libreria de php que permite generar un slug a partir de una cadena


        $category->update(array_merge(
            $request->all(),
            ['slug' => $slug]
        ));


        return response()->json(
            [
                "code" => 200,
                "message" => "Categoria actualizada correctamente",
                "categorie" =>   [
                    'id' => $category->id,
                    'nombre' => $category->nombre,
                    'icon' => $category->icon,
                    'display_order' => $category->display_order,
                    'imagen' => env('APP_URL') . "storage/" . $category->imagen,
                    'state' => $category->state,
                ]
            ]
        );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
