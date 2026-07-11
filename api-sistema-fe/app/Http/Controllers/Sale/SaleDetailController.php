<?php

namespace App\Http\Controllers\Sale;

use App\Http\Controllers\Controller;
use App\Http\Resources\Sale\SaleDetailResource;
use App\Models\Product\Product;
use App\Models\Sale\Sale;
use App\Models\Sale\SaleDetail;
use Illuminate\Http\Request;

class SaleDetailController extends Controller
{
    // ── Agregar un producto a una venta existente ─────────────────────
    // Se usa desde el formulario de edición de ventas
    public function store(Request $request)
    {
        $producto = $request->product;

        // Crear el detalle con todos los campos requeridos por Greenter
        $detalle_venta = SaleDetail::create([
            "sale_id"              => $request->sale_id,
            "product_id"           => $producto["id"],
            "product_categorie_id" => $producto["categorie_id"],

            // Tipo de afectación IGV (Catálogo 07 SUNAT)
            // Siempre como string: '10', '17', '20', '30', '40'
            "tip_afe_igv"          => (string) $request->tip_afe_igv,

            // ICBPER (bolsa plástica)
            "per_icbper"           => $request->per_icbper ?? 0, // monto por unidad (0.50)
            "icbper"               => $request->icbper ?? 0,     // total = qty × 0.50

            // ISC (Impuesto Selectivo al Consumo)
            "percentage_isc"       => $request->percentage_isc ?? 0,
            "isc"                  => $request->isc ?? 0,
            // Régimen ISC: '01'=Al valor, '02'=Específico, '03'=Al valor/PVP
            "tipo_isc"             => $request->tipo_isc ?? '01',
            "monto_isc_fijo"       => $request->monto_isc_fijo ?? 0,

            "unidad_medida"        => $request->unidad_medida,

            // Cantidades y precios
            "quantity"             => $request->quantity,
            "price_base"           => $request->price_base,   // precio sin IGV
            "price_final"          => $request->price_final,  // precio con IGV
            "discount"             => $request->discount,

            // Totales de la línea
            "subtotal"             => $request->subtotal,      // base neta (con descuento, sin IGV)
            "igv"                  => $request->igv,

            // Campos requeridos por Greenter
            "mto_valor_venta"      => $request->mto_valor_venta,   // price_base × qty (sin desc.)
            "mto_base_igv"         => $request->mto_base_igv,      // base neta del IGV
            "porcentaje_igv"       => $request->porcentaje_igv,    // 18, 4 o 0
            "total_impuestos"      => $request->total_impuestos,   // igv + isc + icbper

            "description"          => $request->description,
        ]);

        // ── Actualizar los totales de la cabecera de la venta ─────────
        $venta = Sale::find($request->sale_id);

        // Calcular los nuevos totales sumando el detalle recién agregado
        $nuevo_descuento = $venta->discount + $detalle_venta->discount;
        $nuevo_igv       = $venta->igv + $detalle_venta->igv;
        $nuevo_subtotal  = $venta->subtotal + $detalle_venta->subtotal;

        // Total = subtotal (base) + igv + isc + icbper
        $nuevo_total = $nuevo_subtotal
            + $nuevo_igv
            + $venta->sale_details->sum('isc')
            + $venta->sale_details->sum('icbper');

        // La deuda aumenta porque se agregó más productos
        $nueva_deuda = $venta->debt
            + $detalle_venta->subtotal
            + $detalle_venta->igv
            + $detalle_venta->isc
            + $detalle_venta->icbper;

        // Recalcular estado de pago
        // 1=pendiente, 2=parcial, 3=pagado completo
        $estado_pago = 1; // pendiente por defecto
        if ($nueva_deuda == 0) {
            $estado_pago = 3; // pagado completo
        } elseif ($venta->paid_out > 0) {
            $estado_pago = 2; // pago parcial
        }

        $venta->update([
            "discount"      => $nuevo_descuento,
            "igv"           => $nuevo_igv,
            "subtotal"      => $nuevo_subtotal,
            "total"         => $nuevo_total,
            "debt"          => $nueva_deuda,
            "state_payment" => $estado_pago,
        ]);

        // ── Descontar del stock del producto ──────────────────────────
        $producto_bd = Product::find($producto["id"]);
        $producto_bd->update([
            "stock" => $producto_bd->stock - $detalle_venta->quantity
        ]);

        return response()->json([
            "sale_detail" => SaleDetailResource::make($detalle_venta),
            "code"        => 200,
            "message"     => "Producto agregado a la venta correctamente",
        ]);
    }

    // ── Eliminar un producto de una venta existente ───────────────────
    public function destroy(string $id)
    {
        $detalle_venta = SaleDetail::findOrFail($id);
        $venta = Sale::find($detalle_venta->sale_id);

        // Protección: no se puede eliminar ítem de una venta ya emitida
        if ($venta->xml || $venta->cdr) {
            return response()->json([
                "code"    => 405,
                "message" => "No se puede modificar una venta ya facturada electrónicamente.",
            ]);
        }

        // Soft delete del detalle
        $detalle_venta->delete();

        // ── Restar del total de la venta ──────────────────────────────
        // El monto a restar es el total que pagó el cliente por esta línea
        // = subtotal (base sin igv) + igv + isc + icbper
        $monto_linea_eliminada = $detalle_venta->subtotal
            + $detalle_venta->igv
            + $detalle_venta->isc
            + $detalle_venta->icbper;

        $nuevo_total = $venta->total - $monto_linea_eliminada;
        $nueva_deuda = $venta->debt - $monto_linea_eliminada;

        // Recalcular estado de pago
        $monto_pagado = (float) $venta->paid_out;
        $estado_pago  = 1; // pendiente

        if ($nuevo_total <= $monto_pagado) {
            $estado_pago = 3; // pagado completo
        } elseif ($monto_pagado > 0) {
            $estado_pago = 2; // pago parcial
        }

        $venta->update([
            "discount"      => $venta->discount - $detalle_venta->discount,
            "igv"           => $venta->igv - $detalle_venta->igv,
            "subtotal"      => $venta->subtotal - $detalle_venta->subtotal,
            "total"         => $nuevo_total,
            "debt"          => $nueva_deuda,
            "state_payment" => $estado_pago,
        ]);

        // ── Devolver el stock al producto ─────────────────────────────
        $producto = $detalle_venta->product;
        $producto->update([
            "stock" => $producto->stock + $detalle_venta->quantity
        ]);

        return response()->json([
            "sale_detail" => $id,
            "code"        => 200,
            "message"     => "Producto eliminado de la venta correctamente",
        ]);
    }
}
