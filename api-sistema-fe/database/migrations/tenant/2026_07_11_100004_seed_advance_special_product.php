<?php

// Seed embebido (sin seeder separado, mismo criterio que note_motivos —
// ver create_note_motivos_table.php). Crea la categoría + producto
// "especiales" que AdvanceController::store() usa como línea de
// sale_details del comprobante de adelanto: product_id y
// product_categorie_id son NOT NULL en sale_details, así que no hay forma
// de registrar esa línea sin un producto real. Sigue el mismo patrón que
// NotaElectronicaController::armarLineasParciales() ya usa para conceptos
// libres de Nota de Débito (producto con is_especial_nota=1, sin stock
// real, con tip_afe_igv_default fijo).
//
// tip_afe_igv_default='10' (gravado 18%, tratamiento estándar) — no se
// recalcula contra destino/exportación del cliente, igual que los
// conceptos libres de ND. Ver módulo Adelantos, sección "tratamiento
// tributario fijo al momento de recibido".

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $categoria_id = DB::table('categories')->insertGetId([
            'title'      => 'Adelantos y conceptos especiales',
            'state'      => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('products')->insert([
            'title'                => 'Adelanto a cuenta - venta futura',
            'sku'                  => 'ADELANTO-001',
            'categorie_id'         => $categoria_id,
            'price_general'        => 0,
            'price_company'        => 0,
            'description'          => 'Concepto usado para el comprobante de adelantos de cliente (ver módulo Adelantos). No representa inventario real.',
            'is_discount'          => 0,
            'max_discount'         => 0,
            'disponiblidad'        => 1,
            'state'                => 1,
            'unidad_medida'        => 'ZZ', // Catálogo 03 SUNAT: unidad de servicio
            'stock'                => 0,
            'include_igv'          => 1,
            'is_icbper'            => 0,
            'is_ivap'              => 0,
            'percentage_isc'       => 0,
            'is_especial_nota'     => 1,
            'tip_afe_igv_default'  => '10',
            'tipo_bien_servicio'   => 'SERVICIO',
            'aplica_percepcion'    => false,
            'is_isc'               => false,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
    }

    public function down(): void
    {
        $producto = DB::table('products')->where('sku', 'ADELANTO-001')->first();

        if ($producto) {
            DB::table('products')->where('id', $producto->id)->delete();
            DB::table('categories')->where('id', $producto->categorie_id)->delete();
        }
    }
};
