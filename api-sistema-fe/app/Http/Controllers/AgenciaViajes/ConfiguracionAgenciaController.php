<?php

namespace App\Http\Controllers\AgenciaViajes;

use App\Http\Controllers\Controller;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// Singleton por tenant — la migración de Sesión 2 ya inserta la fila
// default para TODO tenant agencia_viajes al migrar
// (2026_07_27_160300_create_configuracion_agencia_table.php), así que en la
// práctica GET siempre encuentra una fila real. Los defaults hardcodeados
// acá son solo un backstop defensivo (ej. un tenant migrado antes de que
// esa migración insertara la fila, escenario ya no posible hoy pero barato
// de cubrir).
class ConfiguracionAgenciaController extends Controller
{
    private const DEFAULTS = [
        'sigla_comercial' => null,
        'edad_max_infante' => 2,
        'edad_max_nino' => 12,
        'formato_descuento_pdf' => 'solo_final',
        'mostrar_descuento_como_linea' => false,
        'dias_vigencia_cotizacion' => null,
        'dias_limpieza_alternativas_descartadas' => null,
        'max_pax_reserva_con_vuelo' => 15,
        'max_pax_reserva_grupo' => 50,
        'meses_margen_vencimiento_documento' => 6,
        'dias_aviso_pago_proveedor' => 2,
        'dias_cotizacion_estancada' => 15,
        'permitir_descuento_item' => true,
        'modo_descuento_item' => 'porcentaje',
        'modo_descuento_global' => 'porcentaje',
        'margen_minimo_aceptable_pct' => 20.00,
        'edad_max_infante_gratis_hotel_default' => 4,
        'edad_max_nino_cama_adicional_hotel_default' => 12,
        'condiciones_generales_servicio' => null,
    ];

    public function show()
    {
        $config = ConfiguracionAgencia::first();

        return response()->json([
            'configuracion_agencia' => $config ? $config->toArray() : self::DEFAULTS,
        ]);
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'sigla_comercial' => 'nullable|string|max:20',
            'edad_max_infante' => 'required|integer|min:0',
            'edad_max_nino' => 'required|integer|min:0',
            'formato_descuento_pdf' => 'required|in:solo_final,tachado,separado',
            'mostrar_descuento_como_linea' => 'required|boolean',
            'dias_vigencia_cotizacion' => 'nullable|integer|min:1',
            'dias_limpieza_alternativas_descartadas' => 'nullable|integer|min:1',
            'max_pax_reserva_con_vuelo' => 'required|integer|min:1',
            'max_pax_reserva_grupo' => 'required|integer|min:1',
            'meses_margen_vencimiento_documento' => 'required|integer|min:0',
            'dias_aviso_pago_proveedor' => 'required|integer|min:0',
            'dias_cotizacion_estancada' => 'required|integer|min:1',
            'permitir_descuento_item' => 'required|boolean',
            'modo_descuento_item' => 'required|in:porcentaje,monto',
            'modo_descuento_global' => 'required|in:porcentaje,monto',
            'margen_minimo_aceptable_pct' => 'required|numeric|min:0',
            'edad_max_infante_gratis_hotel_default' => 'required|integer|min:0|max:255',
            'edad_max_nino_cama_adicional_hotel_default' => 'required|integer|min:0|max:255',
            'condiciones_generales_servicio' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 422, 'message' => $validator->errors()->first()], 422);
        }

        $config = ConfiguracionAgencia::first();

        if ($config) {
            $config->update($validator->validated());
        } else {
            $config = ConfiguracionAgencia::create($validator->validated());
        }

        return response()->json([
            'code' => 200,
            'message' => 'Configuración actualizada correctamente',
            'configuracion_agencia' => $config,
        ]);
    }
}
