<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Product\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::where('state', 1)->with('categorie:id,title');

        // Filtros
        if ($request->has('categorie_id') && $request->categorie_id) {
            $ids = explode(',', $request->categorie_id);
            $query->whereIn('categorie_id', $ids);
        }
        if ($request->has('max_price')) {
            $query->where('price_general', '<=', $request->max_price);
        }
        if ($request->has('search')) {
            $query->where('title', 'like', '%' . $request->search . '%');
        }
        if ($request->order_by == 'price_asc') {
            $query->orderBy('price_general', 'asc');
        } elseif ($request->order_by == 'price_desc') {
            $query->orderBy('price_general', 'desc');
        }

        $perPage = $request->input('per_page', 9);
        $products = $query->paginate($perPage);

        $data = $products->map(function ($p) {
            return [
                'id' => $p->id,
                'nombre' => $p->title,
                'slug' => $p->id,
                'precio' => (float)$p->price_general,
                'categoria' => $p->categorie->title ?? '',
                'categoria_id' => $p->categorie_id,
                'stock' => $p->stock,
                'imagen' => $p->imagen ? env("APP_URL") . "storage/" . $p->imagen : null,
                'descripcion' => $p->description ?? '',
                'rating' => 4.5,
                'ratingCount' => rand(10, 200)
            ];
        });

        return response()->json([
            'data' => $data,
            'current_page' => $products->currentPage(),
            'last_page' => $products->lastPage(),
            'total' => $products->total(),
            'per_page' => $products->perPage()
        ]);
    }

    public function show($id)
    {
        $product = Product::where('state', 1)->with('categorie')->findOrFail($id);
        return response()->json([
            'id' => $product->id,
            'nombre' => $product->title,
            'slug' => $product->id,
            'precio' => (float)$product->price_general,
            'categoria' => $product->categorie->title ?? '',
            'categoria_id' => $product->categorie_id,
            'stock' => $product->stock,
            'imagen' => $product->imagen ? env("APP_URL") . "storage/" . $product->imagen : null,
            'descripcion' => $product->description ?? '',
            'include_igv' => $product->include_igv,
            'especificaciones' => [
                ['key' => 'Stock', 'val' => $product->stock . ' unidades'],
                ['key' => 'Unidad', 'val' => $product->unidad_medida ?? ''],
                ['key' => 'Categoría', 'val' => $product->categorie->title ?? ''],
                ['key' => 'Marca', 'val' => $product->marca ?? ''],
                ['key' => 'Include IGV', 'val' => $product->include_igv == 1 ? 'Con IGV' : 'Sin IGV'],
            ]
        ]);
    }
}