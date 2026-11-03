<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\ConfiguracionAgencia;
use App\Models\AgenciaViajes\ConfiguracionCodigo;
use App\Models\AgenciaViajes\Cotizacion;
use App\Models\AgenciaViajes\CotizacionPasajero;
use App\Models\Client\Client;
use Illuminate\Support\Facades\DB;

// T-059 — crear una cotización con su «Alternativa A» (T-033). Antes vivía dentro de
// CotizacionController::store(); la usan store() y «Nueva consulta» (ConsultaController), así las
// dos crean la cotización por el mismo camino. Recibe los datos ya validados por quien llama.
// Lanza AlternativaCreacionException (tipo de cambio que falta o fuera de rango): la transacción
// deshace la cotización, y si quien llama abrió una propia (la consulta), también lo suyo.
class CotizacionCreacionService
{
    public function __construct(
        private CodigoGeneradorService $codigoGenerador,
        private AlternativaCreacionService $alternativaCreacion,
    ) {
    }

    /**
     * @param array{cliente_id:int, destino:string, fecha_viaje_desde?:?string, fecha_viaje_hasta?:?string,
     *   pasajeros:array<int, array{edad:int, residencia?:?string}>, alternativa_nombre?:?string,
     *   moneda_cotizacion?:?string, tipo_cambio_valor?:mixed, tipo_cambio_confirmado?:mixed,
     *   origen_contacto_id?:?int} $datos
     */
    public function crear(array $datos, ?int $usuarioId): Cotizacion
    {
        [$edadMaxInfante, $edadMaxNino] = RecalculoPorPersonaService::umbralesEdad();
        $moneda = $datos['moneda_cotizacion'] ?? (ConfiguracionAgencia::first()?->moneda_cotizacion_default ?: 'PEN');

        return DB::transaction(function () use ($datos, $moneda, $usuarioId, $edadMaxInfante, $edadMaxNino) {
            $codigo = $this->codigoGenerador->generar('cotizacion');
            $prefijo = ConfiguracionCodigo::where('tipo', 'cotizacion')->value('prefijo');
            $origenId = $datos['origen_contacto_id'] ?? null;

            $cotizacion = Cotizacion::create([
                'cliente_id' => $datos['cliente_id'],
                'codigo_prefijo' => $prefijo,
                'codigo' => $codigo,
                // Fase 1b (§3.3) — dueño real para el scope de fila
                // (EscopablePorVendedor). auth()->id() puede ser null en
                // contexto de consola/tinker (nunca en una request real,
                // que ya pasó auth:api) — nullable acepta ambos casos.
                'vendedor_id' => auth()->id(),
                'destino' => $datos['destino'],
                'fecha_viaje_desde' => $datos['fecha_viaje_desde'] ?? null,
                'fecha_viaje_hasta' => $datos['fecha_viaje_hasta'] ?? null,
                'origen_contacto_id' => $origenId,
            ]);

            foreach ($datos['pasajeros'] as $pax) {
                CotizacionPasajero::create([
                    'cotizacion_id' => $cotizacion->id,
                    'edad' => $pax['edad'],
                    'tipo_pax' => RecalculoPorPersonaService::derivarTipoPax((int) $pax['edad'], $edadMaxInfante, $edadMaxNino),
                    'residencia' => $pax['residencia'] ?? CotizacionPasajero::RESIDENCIA_NACIONAL,
                ]);
            }

            $this->alternativaCreacion->crear(
                $cotizacion,
                $datos['alternativa_nombre'] ?? null,
                $moneda,
                'dia',
                isset($datos['tipo_cambio_valor']) ? (float) $datos['tipo_cambio_valor'] : null,
                (bool) ($datos['tipo_cambio_confirmado'] ?? false),
                $usuarioId,
            );

            // El cliente guarda el origen de su primer contacto: solo si todavía no tiene uno.
            if ($origenId) {
                Client::whereKey($datos['cliente_id'])->whereNull('origen_contacto_id')->update(['origen_contacto_id' => $origenId]);
            }

            return $cotizacion;
        });
    }
}
