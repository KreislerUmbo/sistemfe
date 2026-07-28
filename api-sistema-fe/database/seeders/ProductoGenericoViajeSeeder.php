<?php

namespace Database\Seeders;

use App\Models\Product\Categorie;
use App\Models\Product\Product;
use Illuminate\Database\Seeder;

// Productos genéricos por tipo de servicio de viaje —
// plan-modulo-cotizaciones-reservas.md §6.1: "no" hacer sale_details.product_id
// nullable (cambio más riesgoso al core compartido); en su lugar, un
// servicio de viaje se factura contra uno de estos 5 productos genéricos,
// con controla_stock=false (2026_07_28_130000_alter_products_add_controla_stock.php)
// y el detalle real en sale_details.descripcion_detalle
// (2026_07_28_130100_alter_sale_details_add_descripcion_detalle.php), no en
// product.title.
//
// Standalone, NO registrado en DatabaseSeeder ni en tenants:provision —
// mismo criterio pendiente de automatizar que ReglaCancelacionSeeder
// (Sesión 8b) y los 4 seeders centrales de Sesión 1 (ver TODO.md). Solo
// tiene sentido para tenants giro=agencia_viajes; correrlo en un tenant
// retail no rompe nada (products/categories son tablas core que todo
// tenant tiene) pero deja 5 filas sin uso real.
//
// Valores mínimos usados para columnas NOT NULL de `products` — mismo
// criterio y varios valores calcados del precedente ya real en producción
// (2026_07_11_100004_seed_advance_special_product.php, producto
// "ADELANTO-001"), EXCEPTO donde ese seed usaba 0 en columnas smallint
// que en realidad son flags 1=No/2=Sí (is_discount/is_icbper/is_ivap,
// confirmado contra admin-start-kit/src/views/product/register.vue) — acá
// se usa el valor real "No" (1) de esa convención en vez de repetir el 0
// fuera de convención (funciona igual en la práctica porque el código solo
// compara "== 2" para el caso Sí, pero 1 es el valor correcto):
//   - price_general/price_company = 0        → no representan un precio de
//     catálogo real, el precio real vive en alternativa_item/reserva_item
//   - is_discount = 1 (No)                    → el descuento real se maneja
//     en alternativa_item.descuento_pct, no a nivel de producto
//   - disponiblidad = 1 (Vender sin stock)     → coherente con controla_stock=false
//   - is_icbper = 1 (No) / is_ivap = 1 (No)    → no aplican a servicios de viaje
//   - include_igv = 1 (Sí, precio incluye IGV) → mismo criterio que ADELANTO-001
//   - unidad_medida = 'ZZ'                     → Catálogo 03 SUNAT, "Unidad de Servicio"
//   - tip_afe_igv_default = '10' (gravado)     → default razonable, ajustable por
//     venta según destino/cliente (mismo texto que el formulario de productos ya
//     muestra para cualquier producto) — la tributación real de cada línea la
//     resuelve proveedor_tarifa.tip_afe_igv (Sesión 5), no este default
//   - is_especial_nota = false                 → a diferencia de ADELANTO-001, estos
//     SÍ son líneas de venta regulares (cada reserva_item se factura como una,
//     Sesión 9b/9c), no conceptos libres de NC/ND sin línea asociada
class ProductoGenericoViajeSeeder extends Seeder
{
    public function run(): void
    {
        $categoria = Categorie::firstOrCreate(
            ['title' => 'Servicios Turísticos'],
            ['state' => 1]
        );

        $productos = [
            ['sku' => 'SERVICIO-HOTEL', 'title' => 'Servicio de Hotelería'],
            ['sku' => 'SERVICIO-TRANSPORTE', 'title' => 'Servicio de Transporte'],
            ['sku' => 'SERVICIO-TOUR', 'title' => 'Tour / Actividad turística'],
            ['sku' => 'SERVICIO-VUELO', 'title' => 'Boleto Aéreo'],
            ['sku' => 'SERVICIO-OTROS-TURISTICOS', 'title' => 'Otros Servicios Turísticos'],
        ];

        foreach ($productos as $producto) {
            Product::updateOrCreate(
                ['sku' => $producto['sku']],
                [
                    'title' => $producto['title'],
                    'categorie_id' => $categoria->id,
                    'price_general' => 0,
                    'price_company' => 0,
                    'description' => 'Producto genérico del vertical Agencia de Viajes — no representa inventario real, el precio y detalle reales viven en alternativa_item/reserva_item.',
                    'is_discount' => 1,
                    'max_discount' => 0,
                    'disponiblidad' => 1,
                    'state' => 1,
                    'unidad_medida' => 'ZZ',
                    'stock' => 0,
                    'include_igv' => 1,
                    'is_icbper' => 1,
                    'is_ivap' => 1,
                    'percentage_isc' => 0,
                    'is_especial_nota' => false,
                    'tip_afe_igv_default' => '10',
                    'tipo_bien_servicio' => 'SERVICIO',
                    'aplica_percepcion' => false,
                    'is_isc' => false,
                    'controla_stock' => false,
                ]
            );
        }
    }
}
