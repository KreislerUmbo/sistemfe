<?php

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Controller;
use App\Models\Product\Categorie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CategorieController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $search = $request->get("search");
        $categories = Categorie::where('title', 'ilike', '%' . $search . '%')
        ->orderBy('id', 'desc')
        ->paginate(15);

        return response()->json([
            "total" => $categories->total(),
            "paginate" => 15,
            'categories' => $categories->map(function ($categorie) {
                return [
                    'id' => $categorie->id,
                    'title' => $categorie->title,
                    'imagen' => env('APP_URL') . "storage/" . $categorie->imagen,
                    'state' => $categorie->state,
                    'created_at' => $categorie->created_at->format('d-m-Y' .' | '. 'H:i:s'),
                ];
            })
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $is_exist_categorie = Categorie::where('title', $request->title)->first();
        if ($is_exist_categorie) {
            return response()->json([
                "code" => 405,
                "message" => "La categoria ya existe"
            ]);
        }

        if ($request->hasFile('image')) {
            $path = Storage::putFile("categories", $request->file("image"));
            $request->merge(['imagen' => $path]);
        }


        $categorie = Categorie::create($request->all());

        return response()->json(
            [
                "code" => 200,
                "message" => "Categoria creada correctamente",
                "categorie" =>   [
                    'id' => $categorie->id,
                    'title' => $categorie->title,
                    'imagen' => env('APP_URL') . "storage/" . $categorie->imagen,
                    'state' => $categorie->state,
                    'created_at' => $categorie->created_at,
                ]
            ]
        );
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $is_exist_categorie = Categorie::where('id', '<>', $id)->where('title', $request->title)->first();

        if ($is_exist_categorie) {
            return response()->json([
                "code" => 405,
                "message" => "La categoria ya existe"
            ]);
        }

        $categorie = Categorie::find($id);

        if ($request->hasFile('image')) {
            if ($categorie->image && Storage::exists($categorie->image)) { //Storage::exists verifica si el archivo existe en el storage
                Storage::delete($categorie->image);
            }

            $path = Storage::putFile("categories", $request->file("image"));
            $request->merge(['imagen' => $path]);
        }

        $categorie->update($request->all());

        return response()->json(
            [
                "code" => 200,
                "message" => "Categoria actualizada correctamente",
                "categorie" =>   [
                    'id' => $categorie->id,
                    'title' => $categorie->title,
                    'imagen' => env('APP_URL') . "storage/" . $categorie->imagen,
                    'state' => $categorie->state,
                ]
            ]
        );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        $categorie = Categorie::find($id);


        if ($categorie->imagen && Storage::exists($categorie->imagen)) { //Storage::exists verifica si el archivo existe en el storage
            Storage::delete($categorie->imagen);
        }

        $categorie->delete();

        return response()->json(
            [
                "code" => 200,
                "message" => "Categoria eliminada correctamente"
            ]
        );
    }
}
