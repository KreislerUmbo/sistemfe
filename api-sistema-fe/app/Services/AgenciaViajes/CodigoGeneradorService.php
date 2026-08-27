<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\CodigoSecuencia;
use App\Models\AgenciaViajes\ConfiguracionCodigo;
use App\Models\AgenciaViajes\Cotizacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Punto único de generación de códigos de tour/paquete/cotización/reserva/
// venta_directa — plan-modulo-codigos-numeracion.md (v3 + revisión
// 26-ago-2026, docs/planning/agencia-de-viajes/plan-modulo-codigos-numeracion.md).
//
// Mismo patrón que App\Services\SerieComprobanteService::reservarCorrelativo():
// fila semilla (creada por la migración, nunca por este servicio) +
// lockForUpdate() + incremento atómico. Ninguno de los dos métodos abre su
// propia transacción — igual que ReservaController::
// crearReservaDesdeAlternativa(), el llamador ya está dentro de una (ver
// CotizacionController::store(), VentaDirectaController::store(),
// PaquetePlantillaController::store()/duplicar()).
class CodigoGeneradorService
{
    // tour | paquete | cotizacion | venta_directa — tipos con contador propio
    // en codigo_secuencias. 'reserva' NO pasa por acá, usa generarParaReserva().
    public function generar(string $tipo): string
    {
        $config = ConfiguracionCodigo::where('tipo', $tipo)->where('activo', true)->first();

        if (!$config) {
            throw new HttpException(
                422,
                "No hay una configuración de código activa para el tipo '{$tipo}' — revisa Configuración > Códigos y numeración."
            );
        }

        $periodo = $config->incluye_periodo ? now()->format('my') : null;

        $siguiente = DB::transaction(function () use ($tipo) {
            $fila = CodigoSecuencia::where('tipo', $tipo)->whereNull('periodo')->lockForUpdate()->first();

            if (!$fila) {
                throw new HttpException(
                    422,
                    "No hay un contador de códigos inicializado para el tipo '{$tipo}' — falta la fila semilla en codigo_secuencias."
                );
            }

            $siguiente = $fila->ultimo_correlativo + 1;
            $fila->update(['ultimo_correlativo' => $siguiente]);

            return $siguiente;
        });

        return $this->formatear($config, $periodo, $siguiente);
    }

    // Reserva no tiene contador propio — reusa periodo+correlativo de la
    // cotización que le dio origen (§4.2/§6.4), cambiando solo el prefijo.
    // No se reconstruye el código desde columnas separadas (cotizaciones no
    // las tiene): se le quita a $cotizacion->codigo el prefijo original
    // (Str::after) y se le antepone el prefijo de reserva — funciona igual
    // para cotizaciones con formato viejo ({prefijo}-{año}-{correlativo:03d})
    // que con el nuevo ({prefijo}-{periodo}-{correlativo:07d}), sin asumir
    // cuántos segmentos tiene el código.
    public function generarParaReserva(Cotizacion $cotizacion): string
    {
        $config = ConfiguracionCodigo::where('tipo', 'reserva')->where('activo', true)->first();

        if (!$config) {
            throw new HttpException(
                422,
                "No hay una configuración de código activa para 'reserva' — revisa Configuración > Códigos y numeración."
            );
        }

        return DB::transaction(function () use ($cotizacion, $config) {
            $fila = Cotizacion::where('id', $cotizacion->id)->lockForUpdate()->first();

            $fila->increment('reservas_generadas');

            $resto = Str::after($fila->codigo, $fila->codigo_prefijo);
            $codigo = $config->prefijo.$resto;

            if ($fila->reservas_generadas > 1) {
                $codigo .= $config->separador.$fila->reservas_generadas;
            }

            return $codigo;
        });
    }

    // Vista previa del próximo código, sin efectos secundarios (§8: "vista
    // previa sin efectos secundarios") — no bloquea ni incrementa nada, solo
    // lee el correlativo actual + 1. Usado por la pantalla de configuración.
    //
    // $overrides permite previsualizar en vivo cambios TODAVÍA sin guardar
    // (prefijo/separador/incluye_periodo/longitud_correlativo, tal como los
    // tiene el formulario en ese momento) sin persistir nada — se aplican
    // sobre una copia en memoria de la config guardada, nunca sobre el
    // modelo real.
    public function previsualizar(string $tipo, array $overrides = []): string
    {
        if ($tipo === 'reserva') {
            $config = ConfiguracionCodigo::where('tipo', 'reserva')->where('activo', true)->first();

            if (!$config) {
                throw new HttpException(422, "No hay una configuración de código activa para 'reserva'.");
            }

            $config->fill(array_intersect_key($overrides, ['prefijo' => true, 'separador' => true]));

            // Sin una cotización real de referencia, se simula con un
            // ejemplo — mismo criterio que ya usaba el placeholder de
            // cotizador/nueva.vue antes de este módulo.
            return $config->prefijo.$config->separador.now()->format('my').$config->separador.'0000001';
        }

        $config = ConfiguracionCodigo::where('tipo', $tipo)->where('activo', true)->first();

        if (!$config) {
            throw new HttpException(422, "No hay una configuración de código activa para el tipo '{$tipo}'.");
        }

        $config->fill(array_intersect_key($overrides, array_flip([
            'prefijo', 'separador', 'incluye_periodo', 'longitud_correlativo',
        ])));

        $periodo = $config->incluye_periodo ? now()->format('my') : null;
        $fila = CodigoSecuencia::where('tipo', $tipo)->whereNull('periodo')->first();
        $siguiente = ($fila->ultimo_correlativo ?? 0) + 1;

        return $this->formatear($config, $periodo, $siguiente);
    }

    private function formatear(ConfiguracionCodigo $config, ?string $periodo, int $correlativo): string
    {
        $correlativoFormateado = str_pad((string) $correlativo, $config->longitud_correlativo, '0', STR_PAD_LEFT);

        $partes = [$config->prefijo];
        if ($periodo) {
            $partes[] = $periodo;
        }
        $partes[] = $correlativoFormateado;

        return implode($config->separador, $partes);
    }
}
