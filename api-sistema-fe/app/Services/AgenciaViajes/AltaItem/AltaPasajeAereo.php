<?php

namespace App\Services\AgenciaViajes\AltaItem;

use App\Http\Controllers\AgenciaViajes\AerolineaController;
use App\Models\AgenciaViajes\Alternativa;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\CotizacionPasajeAereo;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Services\HoraPeru;
use Illuminate\Support\Facades\DB;

// T-095 — origen_tipo=pasaje_aereo. validar() y calcular() son públicos porque el alta, la vista previa
// del formulario (previewPasajeAereo) y la edición (actualizarPasajeAereo) usan las MISMAS: así nunca
// hay una segunda fórmula que se desincronice de la primera.
class AltaPasajeAereo extends AltaItemBase
{
    public function crear(Alternativa $alternativa, array $datos): AlternativaItem
    {
        $validado = $this->validar($datos, $alternativa);
        $resultado = $this->calcular($alternativa, $validado);
        $tratamientoTributario = ConfiguracionAgencia::completarTratamientoTributario($validado['tip_afe_igv'] ?? null, $validado['destino_tributario'] ?? null);

        $item = DB::transaction(function () use ($alternativa, $validado, $resultado, $tratamientoTributario) {
            $item = AlternativaItem::create([
                'alternativa_id' => $alternativa->id,
                'alternativa_destino_id' => $alternativa->destinoIdODefault($validado['alternativa_destino_id'] ?? null),
                'origen_tipo' => AlternativaItem::ORIGEN_PASAJE_AEREO,
                'modo_precio' => 'por_persona', // el reparto adulto/niño/infante ya está resuelto en venta_total
                'cantidad' => 1,
                'pax_incluidos' => $validado['pax_incluidos'] ?? null,
                'moneda_costo' => $validado['moneda'],
                'costo_snapshot' => $resultado['costo_total'],
                'precio_venta_snapshot' => $resultado['venta_total'],
                'precio_convertido' => $this->convertir($resultado['venta_total'], $validado['moneda'], $alternativa),
                'dia_referencial' => $validado['dia_referencial'] ?? null,
                'tip_afe_igv' => $tratamientoTributario['tip_afe_igv'],
                'destino_tributario' => $tratamientoTributario['destino_tributario'],
            ]);

            CotizacionPasajeAereo::create([
                'alternativa_item_id' => $item->id,
                'aerolinea' => $validado['aerolinea'],
                'itinerario' => $validado['itinerario'] ?? null,
                'moneda' => $validado['moneda'],
                'tarifa_base_adulto' => $validado['tarifa_base_adulto'],
                'tarifa_base_nino' => $validado['tarifa_base_nino'] ?? null,
                'tarifa_base_infante' => $validado['tarifa_base_infante'] ?? null,
                'cargos' => $validado['cargos'] ?? [],
                'tua_incluida_en_tarifa' => $validado['tua_incluida_en_tarifa'] ?? false,
                'fee_agencia_monto' => $validado['fee_agencia_monto'] ?? 0,
                'montos_por_pasajero' => $validado['montos_por_pasajero'] ?? true,
                'tip_afe_igv' => $validado['tip_afe_igv'] ?? null,
                'fecha_cotizado' => now(),
                'tarifa_valida_hasta' => $validado['tarifa_valida_hasta'],
                'costo_total' => $resultado['costo_total'],
                'precio_venta_total' => $resultado['venta_total'],
            ]);

            return $item;
        });

        $this->cerrar($alternativa);
        $item->load('cotizacionPasajeAereo');

        return $item;
    }

    /** Valida y normaliza los datos del formulario de pasaje aéreo; lanza AltaItemException si no valen. */
    public function validar(array $datos, Alternativa $alternativa): array
    {
        $validado = $this->validarReglas($datos, [
            'aerolinea' => 'required|string|max:150',
            'itinerario' => 'nullable|string',
            'moneda' => 'required|in:PEN,USD',
            'tarifa_base_adulto' => 'required|numeric|min:0',
            'tarifa_base_nino' => 'nullable|numeric|min:0',
            'tarifa_base_infante' => 'nullable|numeric|min:0',
            'cargos' => 'nullable|array',
            'cargos.*.nombre' => 'required_with:cargos|string',
            'cargos.*.monto' => 'required_with:cargos|numeric',
            'cargos.*.tipo' => 'nullable|in:impuesto,tasa_aeropuerto,fee_agencia',
            // T-076: el cargo no se cobra a los infantes (solo con montos por pasajero).
            'cargos.*.no_aplica_infante' => 'nullable|boolean',
            // T-076: true = cargos y fee por pasajero. Sin valor: los pasajes nuevos nacen con true y la
            // edición de uno existente conserva su fórmula (ver actualizarPasajeAereo()).
            'montos_por_pasajero' => 'nullable|boolean',
            'tua_incluida_en_tarifa' => 'nullable|boolean',
            'fee_agencia_monto' => 'nullable|numeric|min:0',
            // cotizacion_pasaje_aereo.tip_afe_igv: la afectación del pasaje, que la factura aplica al
            // precio completo (D-AGE-035). destino_tributario va a nivel de alternativa_items.
            'tip_afe_igv' => 'nullable|string|max:2',
            'destino_tributario' => 'nullable|string|in:amazonia,nacional,extranjero',
            'pax_incluidos' => 'nullable|array',
            'pax_incluidos.*' => 'integer|exists:cotizacion_pasajeros,id',
            'dia_referencial' => 'nullable|integer|min:1',
            // Solo tiene efecto al crear: el destino de un ítem ya existente no se mueve por acá.
            'alternativa_destino_id' => ['nullable', 'integer', $alternativa->reglaDestinoId()],
            // Vigencia de la tarifa aérea: fecha-hora límite que da la aerolínea, escrita en hora de Perú;
            // se guarda en UTC.
            'tarifa_valida_hasta' => 'nullable|date_format:Y-m-d H:i',
        ]);

        $validado['tarifa_valida_hasta'] = HoraPeru::aUtc($validado['tarifa_valida_hasta'] ?? null);
        // Capitalización tipo título, solo hacia adelante. T-016: misma regla que el catálogo común
        // (solo si viene todo en minúsculas), para no pasar "LATAM" a "Latam".
        $validado['aerolinea'] = AerolineaController::formatearNombre($validado['aerolinea']);
        // T-007: un pasaje aéreo es un boleto por persona y guarda a quiénes cubre. Con null ("todos") un
        // pasajero agregado después quedaba dentro de un boleto que no se le cobró.
        if (empty($validado['pax_incluidos'])) {
            $validado['pax_incluidos'] = CotizacionPasajero::where('cotizacion_id', $alternativa->cotizacion_id)
                ->orderBy('id')->pluck('id')->all() ?: null;
        }

        return $validado;
    }

    /** Costo y venta del pasaje para los pasajeros incluidos (tarifas + cargos + fee). */
    public function calcular(Alternativa $alternativa, array $validado): array
    {
        $conteo = $this->priceEngine->contarPasajerosPorTipo($alternativa->cotizacion_id, $validado['pax_incluidos'] ?? null);

        // tarifa_base_* se reparte por tipo de pasajero, igual que el reparto por_persona de proveedor_tarifas.
        $costoBaseTarifas = $conteo['adulto'] * (float) $validado['tarifa_base_adulto']
            + $conteo['nino'] * (float) ($validado['tarifa_base_nino'] ?? 0)
            + $conteo['infante'] * (float) ($validado['tarifa_base_infante'] ?? 0);

        $cargos = $validado['cargos'] ?? [];
        $fee = (float) ($validado['fee_agencia_monto'] ?? 0);

        // T-076: cargos y fee son POR PASAJERO. Un cargo con «no aplica a infantes» se multiplica solo por
        // adultos y niños; el fee, por todos los marcados (el infante paga fee). Un pasaje anterior
        // (montos_por_pasajero=false) conserva su fórmula: montos del grupo, una vez.
        if ($validado['montos_por_pasajero'] ?? true) {
            $total = $conteo['adulto'] + $conteo['nino'] + $conteo['infante'];
            $sinInfantes = $conteo['adulto'] + $conteo['nino'];
            $cargos = array_map(function (array $cargo) use ($total, $sinInfantes) {
                $cargo['monto'] = (float) $cargo['monto'] * (! empty($cargo['no_aplica_infante']) ? $sinInfantes : $total);

                return $cargo;
            }, $cargos);
            $fee *= $total;
        }

        // fee_agencia_monto es lo único que la agencia realmente vende: se modela como margen 'fijo' del
        // motor de precios, no como un cargo más (los cargos son pass-through de terceros).
        return $this->priceEngine->calcular(
            costoBase: $costoBaseTarifas,
            cargos: $cargos,
            margenTipo: 'fijo',
            margenValor: $fee,
            descuentoMaximoPct: null,
            margenMinimoPct: null,
        );
    }
}
