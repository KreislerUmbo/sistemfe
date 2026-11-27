<?php

namespace App\Http\Controllers\Product;

use App\Rules\ArchivoSubido;
use App\Http\Controllers\Controller;
use App\Models\Compras\Compra;
use App\Http\Resources\Product\ProductCollection;
use App\Http\Resources\Product\ProductResource;
use App\Models\Product\Categorie;
use App\Models\Product\DetractionCode;
use App\Models\Product\Product;
use App\Models\Product\StockMovimiento;
use App\Models\Sale\Note;
use App\Models\Sale\Sale;
use App\Services\Stock\StockService;
use App\Models\Product\TaxConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'cantidad_mayorista',
            'description',
            'is_discount',
            'max_discount',
            'disponiblidad',
            'state',
            'unidad_medida',
            // 'stock' no: solo lo mueve StockService (T-153).
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


    // T-153: el stock no se asigna con el resto de la ficha; cambiarlo es un ajuste con motivo.
    private const REGLAS_STOCK = [
        'stock' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        'motivo_stock' => ['nullable', 'string', 'max:255'],
    ];

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

        // Caso normal: paginado. ?per_page= opcional (default 15, igual que
        // antes) — acotado entre 1 y 100, mismo criterio que
        // CotizacionController::index().
        $perPage = min(100, max(1, (int) $request->get('per_page', 15)));
        $products = $query->paginate($perPage);

        return response()->json([
            "total" => $products->total(),
            "paginate" => $perPage,
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
        $request->validate(['image' => ArchivoSubido::imagen(), 'cantidad_mayorista' => ['nullable', 'numeric', 'min:0', 'max:999999999']] + self::REGLAS_STOCK, ArchivoSubido::MENSAJES);
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

        $product = DB::transaction(function () use ($request, $data) {
            $product = Product::create($data + ['stock' => 0]);
            // El stock inicial es el primer ajuste del kárdex.
            app(StockService::class)->ajustar($product->id, (float) $request->input('stock'), 'stock inicial', true);

            return $product->refresh();
        });

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
            // El costo (T-154) solo va en la ficha: el listado lo usa también quien vende.
            "product" => ProductResource::make($product)->resolve() + ['costo_promedio' => $product->costo_promedio],
            "code" => 200
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $request->validate(['image' => ArchivoSubido::imagen(), 'cantidad_mayorista' => ['nullable', 'numeric', 'min:0', 'max:999999999']] + self::REGLAS_STOCK, ArchivoSubido::MENSAJES);
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

        Product::findOrFail($id);
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

        [$product, $imagenAnterior] = DB::transaction(function () use ($request, $data, $id) {
            $product = Product::lockForUpdate()->findOrFail($id);
            $controlaba = $product->controlaStock();
            $product->update($data);

            // Si el ajuste da 422, la transacción deshace también el resto de la ficha.
            $empiezaAControlar = !$controlaba && $product->controlaStock();
            $stockNuevo = $request->filled('stock') ? (float) $request->input('stock') : (float) $product->stock;
            app(StockService::class)->ajustar($product->id, $stockNuevo, $request->input('motivo_stock'), $empiezaAControlar);

            // La imagen va después del ajuste: un 422 no debe haber tocado ningún archivo.
            $imagenAnterior = null;
            if ($request->hasFile('image')) {
                $imagenAnterior = $product->imagen;
                $product->update(['imagen' => Storage::disk('public')->putFile("products", $request->file("image"))]);
            }

            return [$product->refresh(), $imagenAnterior];
        });

        if ($imagenAnterior && Storage::disk('public')->exists($imagenAnterior)) {
            Storage::disk('public')->delete($imagenAnterior);
        }

        return response()->json([
            "code" => 200,
            "message" => "Producto actualizado correctamente",
            "product" => $product,
        ]);
    }

    /** T-153: kárdex del producto, del movimiento más nuevo al más viejo. */
    public function kardex(Request $request, string $id)
    {
        $product = Product::findOrFail($id);
        $perPage = min(100, max(1, (int) $request->get('per_page', 20)));
        $pagina = StockMovimiento::with('user:id,name,surname')
            ->where('product_id', $product->id)
            ->orderByDesc('id')
            ->paginate($perPage);

        // Documentos de la página, en una consulta por tipo (incluidos los eliminados).
        $filas = collect($pagina->items());
        $ids = fn (string $tipo) => $filas->where('documento_tipo', $tipo)->pluck('documento_id')->unique()->values();
        $documentos = [
            'venta' => Sale::withTrashed()->whereIn('id', $ids('venta'))->get(['id', 'serie', 'correlativo'])->keyBy('id'),
            'nota' => Note::withTrashed()->whereIn('id', $ids('nota'))->get(['id', 'serie', 'correlativo'])->keyBy('id'),
            'compra' => Compra::whereIn('id', $ids('compra'))->get(['id', 'serie', 'numero'])->keyBy('id'),
        ];

        return response()->json([
            'total' => $pagina->total(),
            'paginate' => $perPage,
            'movimientos' => $filas->map(function (StockMovimiento $m) use ($documentos) {
                $documento = null;
                if ($m->documento_tipo) {
                    $doc = $documentos[$m->documento_tipo]->get($m->documento_id);
                    $numero = $m->documento_tipo === 'compra' ? $doc?->numero : $doc?->correlativo;
                    $documento = ['venta' => 'Venta', 'nota' => 'Nota', 'compra' => 'Compra'][$m->documento_tipo]
                        . " #{$m->documento_id}" . ($numero ? " ({$doc->serie}-{$numero})" : '');
                }

                return [
                    'id' => $m->id,
                    'created_at' => $m->created_at->toIso8601ZuluString(),
                    'tipo' => $m->tipo,
                    'documento' => $documento,
                    'motivo' => $m->motivo,
                    'cantidad' => $m->cantidad,
                    'costo_unitario' => $m->costo_unitario,
                    'saldo' => $m->saldo,
                    'usuario' => $m->user ? trim("{$m->user->name} {$m->user->surname}") : null,
                ];
            })->values(),
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
