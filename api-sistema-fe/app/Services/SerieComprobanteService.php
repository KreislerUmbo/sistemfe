<?php

namespace App\Services;

use App\Models\Sale\SerieComprobante;
use App\Models\Sale\TipoComprobante;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

// ── Punto único de resolución + reserva de correlativo de series de
// comprobantes ──────────────────────────────────────────────────────────
// Reemplaza el mecanismo viejo de FacturacionElectronicaController::
// reservarCorrelativo() (MAX(correlativo) sobre 'sales', sin fila que
// bloquear si la serie era nueva — el bug de concurrencia real que motivó
// este módulo). Ahora siempre hay una fila semilla en serie_comprobantes
// (correlativo_actual=0) desde que la serie se crea, antes de cualquier
// venta.
//
// Usado por dos call sites con timing distinto (ver conversación de diseño):
// - SaleController::store(): resuelve la serie siempre; reserva el
//   correlativo de inmediato SOLO para tipos no-fiscales (NV) — nunca hay
//   un paso de "enviar" posterior para ellos.
// - FacturacionElectronicaController::enviarSunat(): reserva el correlativo
//   recién al enviar (documentos fiscales) — comportamiento sin cambios,
//   solo cambia DÓNDE vive el contador (serie_comprobantes en vez de
//   MAX(sales.correlativo)).
class SerieComprobanteService
{
    // ── Resolver sucursal + tipo + serie para un usuario, dado un tipo YA
    // decidido por reglas de negocio (no elegido libremente en un <select>) ──
    // Extraído de SaleController::resolverSerieComprobante() para que
    // AdvanceController::store() (deriva el tipo de comprobante del adelanto
    // directo del cod_tipo_doc_sunat del cliente) reuse la misma resolución
    // de sucursal/serie sin duplicar la lógica de can_switch_branch. La
    // validación de PERMISO de emisión queda a cargo de cada llamador — acá
    // solo se resuelve sucursal + catálogo + serie activa.
    public function resolverParaUsuario($usuario, string $codigoTipo, string $moneda, ?int $branchIdSolicitado = null): array
    {
        if ($usuario->can('can_switch_branch') && $branchIdSolicitado) {
            $branchId = $branchIdSolicitado;
        } else {
            $branchId = $usuario->branch_id;
        }

        if (!$branchId) {
            throw new HttpException(
                422,
                'Tu usuario no tiene una sucursal asignada — no se puede emitir ningún ' .
                'comprobante hasta que un administrador te asigne una.'
            );
        }

        $tipo = TipoComprobante::find($codigoTipo);

        if (!$tipo) {
            throw new HttpException(422, "El tipo de comprobante '{$codigoTipo}' no existe en el catálogo.");
        }

        $serie = $this->resolverSerie($branchId, $codigoTipo, $moneda);

        return ['branch_id' => $branchId, 'tipo' => $tipo, 'serie' => $serie];
    }

    public function resolverSerie(int $branchId, string $tipoComprobanteCodigo, string $moneda): SerieComprobante
    {
        $serie = SerieComprobante::where('branch_id', $branchId)
            ->where('tipo_comprobante_codigo', $tipoComprobanteCodigo)
            ->where('moneda', $moneda)
            ->where('activo', true)
            ->first();

        if (!$serie) {
            throw new HttpException(
                422,
                "No hay una serie activa configurada para la sucursal #{$branchId}, " .
                "tipo de comprobante '{$tipoComprobanteCodigo}', moneda {$moneda}. " .
                "Crea la serie primero en el módulo de Series de Comprobantes."
            );
        }

        return $serie;
    }

    // Bloquea la fila de serie_comprobantes con lockForUpdate() e incrementa
    // correlativo_actual de forma atómica — mismo mecanismo para NV que para
    // tipos fiscales. Devuelve el correlativo entero (sin padding) ya
    // reservado en BD.
    public function reservarCorrelativo(SerieComprobante $serie): int
    {
        return DB::transaction(function () use ($serie) {
            $fila = SerieComprobante::where('id', $serie->id)->lockForUpdate()->first();

            if (!$fila || !$fila->activo) {
                throw new HttpException(
                    422,
                    "La serie #{$serie->id} ya no está activa — no se puede reservar un correlativo nuevo."
                );
            }

            $nuevoCorrelativo = $fila->correlativo_actual + 1;
            $fila->update(['correlativo_actual' => $nuevoCorrelativo]);

            return $nuevoCorrelativo;
        });
    }

    // Valida que un tipo_comprobante_codigo pueda usarse para crear una
    // serie nueva: debe existir en el catálogo, y ser activo_greenter=true
    // O 'NV' — nunca un tipo fiscal sin soporte real en GreenterService
    // (Paso 3 del módulo). Devuelve el TipoComprobante si es válido.
    public function validarTipoParaCrearSerie(string $codigo): TipoComprobante
    {
        $tipo = TipoComprobante::find($codigo);

        if (!$tipo) {
            throw new HttpException(422, "El tipo de comprobante '{$codigo}' no existe en el catálogo.");
        }

        if (!$tipo->activo_greenter && $codigo !== 'NV') {
            throw new HttpException(
                422,
                "El tipo de comprobante '{$codigo}' ({$tipo->nombre}) todavía no está soportado " .
                "por GreenterService — no se puede crear una serie para él todavía."
            );
        }

        return $tipo;
    }
}
