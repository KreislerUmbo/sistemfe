<?php

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Controller;
use App\Http\Resources\Product\ProductCollection;
use App\Http\Resources\Product\ProductResource;
use App\Models\Product\Categorie;
use App\Models\Product\DetractionCode;
use App\Models\Product\Product;
use App\Models\Product\TaxConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    // ── Campos permitidos para mass assignment ───────────────────────
    // Evita que campos no esperados (ej. id, timestamps) se cuelen.
    private function allowedFields(): array
    {
        return [
            'title',
            'sku',
            'categorie_id',
            'imagen',
            'price_general',
            'price_company',
            'description',
            'is_discount',
            'max_discount',
            'disponiblidad',
            'state',
            'unidad_medida',
            'stock',
            'include_igv',
            'is_icbper',
            'is_ivap',
            'is_isc',
            'tipo_isc',
            'monto_isc_fijo',
            'contenido_neto_litros',
            'percentage_isc',
            'is_especial_nota',
            // ── Campos SUNAT ─────────────────────────────────────────
            'tip_afe_igv_default',
            'tipo_bien_servicio',
            'aplica_percepcion',
            'codigo_detraccion',
        ];
    }


    public function index(Request $request)
    {
        $search = $request->get("search");
        $categorie_id = $request->get("categorie_id");
        $state = $request->get("state");
        $unidad_medida = $request->get("unidad_medida");
        $take = $request->get("take"); // nuevo

        $query = Product::filterMultiple($search, $categorie_id, $state, $unidad_medida)
            ->orderBy('id', 'desc');

        // Si se pide un número específico (para búsqueda rápida), devolver solo esos
        if ($take) {
            $products = $query->take($take)->get();
            return response()->json([
                'products' => ProductCollection::make($products),
            ]);
        }

        // Caso normal: paginado
        $products = $query->paginate(15);

        return response()->json([
            "total" => $products->total(),
            "paginate" => 15,
            'products' => ProductCollection::make($products),
        ]);
    }

    // ── Blindaje contra datos sucios de ISC ───────────────────────────
    // Si is_isc llega en false, ignora tipo_isc/percentage_isc/monto_isc_fijo
    // sin importar qué valores traiga el request (bug conocido: el form de
    // Vue podía dejar residuos al desmarcar ISC sin limpiar el modelo).
    private function normalizeIscFields(array $data): array
    {
        if (empty($data['is_isc'])) {
            $data['percentage_isc'] = 0;
            $data['tipo_isc'] = null;
            $data['monto_isc_fijo'] = 0;
            $data['contenido_neto_litros'] = null;
        } elseif (($data['tipo_isc'] ?? null) !== '02') {
            // contenido_neto_litros solo aplica al régimen Específico (por litro)
            $data['contenido_neto_litros'] = null;
        } elseif (($data['contenido_neto_litros'] ?? '') === '') {
            // string vacío no es válido para una columna decimal — normalizar a null
            $data['contenido_neto_litros'] = null;
        }

        return $data;
    }

    public function config()
    {
        $categories = Categorie::where('state', 1)->get();

        // Catálogos tributarios — agrupados por tipo para consumo directo en el frontend
        $taxes = TaxConfig::where('es_activo', true)
            ->get(['nombre', 'tipo', 'tasa_porcentaje', 'codigo_sunat', 'ubigeos_aplicables', 'es_default'])
            ->groupBy('tipo');

        $detracciones = DetractionCode::where('estado', true)
            ->orderBy('codigo')
            ->get();

        return response()->json([
            "categories" => $categories->map(fn($c) => [
                "id"    => $c->id,
                "title" => $c->title,
            ]),
            // taxes agrupado: { "IGV": [...], "IVAP": [...], "ICBPER": [...], "ISC": [...] }
            "taxes"       => $taxes,
            "detracciones" => $detracciones,
        ]);
    }

    // catalogsTributarios se mantiene para compatibilidad si ya está en rutas,
    // pero ahora config() devuelve lo mismo — puedes eliminarlo después
    public function catalogsTributarios()
    {
        return $this->config();
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
        $data = $request->only($this->allowedFields());
        // Convertir booleanos a enteros (0/1)
        $data['aplica_percepcion'] = $request->boolean('aplica_percepcion') ? 1 : 0;
        $data['is_isc'] = $request->boolean('is_isc') ? 1 : 0;
        $data = $this->normalizeIscFields($data);

        if (!empty($data['codigo_detraccion'])) {
            $existe_detraccion = DetractionCode::where('codigo', $data['codigo_detraccion'])
                ->where('estado', true)
                ->exists();

            if (!$existe_detraccion) {
                abort(422, 'Código de detracción inválido');
            }
        }

        if ($request->hasFile('image')) {
            $path = Storage::disk('public')->putFile("products", $request->file("image"));
            $data['imagen'] = $path;
        }

        $product = Product::create($data);

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

        if (!$product) {
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
        $data = $request->only($this->allowedFields());
        $data['aplica_percepcion'] = $request->boolean('aplica_percepcion') ? 1 : 0;
        $data['is_isc'] = $request->boolean('is_isc') ? 1 : 0;
        $data = $this->normalizeIscFields($data);

        if (!empty($data['codigo_detraccion'])) {
            $existe_detraccion = DetractionCode::where('codigo', $data['codigo_detraccion'])
                ->where('estado', true)
                ->exists();

            if (!$existe_detraccion) {
                abort(422, 'Código de detracción inválido');
            }
        }

        if ($request->hasFile('image')) {
            if ($product->imagen && Storage::disk('public')->exists($product->imagen)) {
                Storage::disk('public')->delete($product->imagen);
            }
            $path = Storage::disk('public')->putFile("products", $request->file("image"));
            $data['imagen'] = $path;
        }

        $product->update($data);

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
