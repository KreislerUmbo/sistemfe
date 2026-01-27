<?php

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Controller;
use App\Http\Resources\Product\ProductCollection;
use App\Http\Resources\Product\ProductResource;
use App\Models\Product\Categorie;
use App\Models\Product\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        //search
        $search = $request->get("search");
        // categories
        $categorie_id = $request->get("categorie_id");
        //estado
        $state = $request->get("state");
        //unidad de medida
        $unidad_medida = $request->get("unidad_medida");

        $products = Product::filterMultiple($search, $categorie_id, $state, $unidad_medida)
                    ->orderBy('id', 'desc')->paginate(15);

        return response()->json([
            "total" => $products->total(),
            "paginate" => 15,
            'products' => ProductCollection::make($products),
        ]);
    }

     public function config()
    {
        $categories = Categorie::where('state', 1)->get();

        return response()->json([
            "categories" => $categories->map(function ($categorie) {
                return [
                    "id" => $categorie->id,
                    "title" => $categorie->title,
                ];
            })
        ]);
    }



    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $is_product_title = Product::where('title', $request->title)->first();
        if ($is_product_title) {
            return response()->json([
                "code" => 405,
                "message" => "El producto ya existe",
            ]);
        }

        $is_product_sku = Product::where('sku', $request->sku)->first();
        if ($is_product_sku) {
            return response()->json([
                "code" => 405,
                "message" => "El sku ya existe",
            ]);
        }
         if($request->hasFile('image')) {//si tiene imagen
            $path = Storage::putFile("products", $request->file("image"));//guarda la direccion de la imagen
            $request->merge(['imagen' => $path]);//
        }



        $product = Product::create($request->all());

        return response()->json([
            "code" => 200,
            "message" => "Producto guardado correctamente",
            "product" => $product,
        ]);




    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $product = Product::find($id);

        if(!$product) {
            return response()->json([
                "code" => 405,
                "message" => "Producto no encontrado",
            ]);
        }

        return response()->json([
            "product" => ProductResource::make($product),
            "code" => 200
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
         $is_product_title = Product::where('id', '<>', $id)
        ->where('title', $request->title)->first();

        if ($is_product_title) {
            return response()->json([
                "code" => 405,
                "message" => "El producto ya existe",
            ]);
        }

        $is_product_sku = Product::where('id', '<>', $id)
                ->where('sku', $request->sku)->first();
        if ($is_product_sku) {
            return response()->json([
                "code" => 405,
                "message" => "El sku ya existe",
            ]);
        }
        
        $product = Product::findOrFail($id);

        if($request->hasFile('image')) {//si tiene imagen

           if ($product->image && Storage::exists($product->image)) { //Storage::exists verifica si el archivo existe en el storage
                Storage::delete($product->image);//elimina la imagen
            }
            $path = Storage::putFile("products", $request->file("image"));//guarda la direccion de la imagen
            $request->merge(['imagen' => $path]);//
        }


        $product->update($request->all());

        return response()->json([
            "code" => 200,
            "message" => "Producto actualizado correctamente",
            "product" => $product,
        ]);

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $product = Product::findOrFail($id);

       //en caso se quiera eliminar la imagen
  /*       if ($product->image && Storage::exists($product->image)) { //Storage::exists verifica si el archivo existe en el storage
            Storage::delete($product->image);//elimina la imagen
        } */

        $product->delete();

        return response()->json([
            "code" => 200,
            "message" => "Producto eliminado correctamente",
        ]);
    }
}
