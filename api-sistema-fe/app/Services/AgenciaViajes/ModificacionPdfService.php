<?php

namespace App\Services\AgenciaViajes;

use App\Http\Controllers\AgenciaViajes\ReservaController;
use App\Models\AgenciaViajes\AlternativaItem;
use App\Models\AgenciaViajes\Reserva;
use App\Models\AgenciaViajes\ReservaItem;
use App\Models\AgenciaViajes\ReservaModificacion;
use App\Services\HoraPeru;
use App\Services\PdfFontService;
use Symfony\Component\HttpKernel\Exception\HttpException;

// PDF de la modificación de una reserva para el cliente (T-006; R-AGE-09). No calcula nada
// propio: toma la diferencia de ModificacionDiferenciaService (modificación abierta) o la
// `diferencia_aplicada` guardada (ya aplicada), y solo la ordena para imprimirla.
//
// - El "Ajuste — <servicio>" (alternativa_items.ajuste_de_item_id) no sale como línea aparte: la
//   diferencia ya lo trae como la línea `ajuste` del servicio que sube, y los ítems de ajuste
//   no se leen de la alternativa (listarlos sumaría la diferencia dos veces).
// - Los nombres se resuelven de nuevo en audiencia 'cliente': la descripción guardada en la
//   diferencia es la interna (con el nombre del mayorista).
class ModificacionPdfService
{
    // Solo lo que cambia (T-006). Ni `igual` ni `para_revisar`: un "para revisar" confirmado
    // (hotel con otra cantidad de pasajeros y el mismo precio) no le cambia nada al cliente.
    private const ESTADOS_QUE_SE_IMPRIMEN = ['agregado', 'quitado', 'requiere_nota_credito', 'cambio_precio', 'ajuste'];

    public function __construct(
        private MembreteAgenciaPdfService $membrete,
        private ModificacionDiferenciaService $diferencia,
    ) {
    }

    public function generar(ReservaModificacion $modificacion)
    {
        $datos = $this->datos($modificacion);

        return $this->construir($datos)->download($datos['nombreArchivo']);
    }

    // Bytes y nombre, sin respuesta HTTP (para tests y para adjuntarlo a un correo más adelante).
    public function contenido(ReservaModificacion $modificacion): array
    {
        $datos = $this->datos($modificacion);

        return ['bytes' => $this->construir($datos)->output(), 'nombre' => $datos['nombreArchivo']];
    }

    /**
     * Todo lo que imprime la vista, ya resuelto. Público para poder comprobar en un test que
     * coincide con la diferencia sin tener que leer el PDF.
     *
     * @return array<string, mixed>
     */
    public function datos(ReservaModificacion $modificacion): array
    {
        $diferencia = $this->diferenciaDe($modificacion);
        $reserva = Reserva::findOrFail($modificacion->reserva_id);

        // Las penalidades se reconocen por la diferencia: al aplicar se borra el pasajero quitado y
        // su penalidad pierde penalidad_reserva_pasajero_id (nullOnDelete).
        $idsPenalidad = collect($diferencia['pasajeros'] ?? [])
            ->flatMap(fn (array $p) => [$p['penalidad_alternativa_item_id'] ?? null, $p['penalidad_sobrante_item_id'] ?? null])
            ->filter()->map(fn ($id) => (int) $id)->all();

        $lineas = collect($diferencia['lineas'])
            ->filter(fn (array $l) => in_array($l['estado'], self::ESTADOS_QUE_SE_IMPRIMEN, true))
            ->map(fn (array $l) => $this->lineaParaCliente($l, $idsPenalidad))
            ->values();

        // Las penalidades salen en su propia sección, con el motivo; no en la tabla de cambios.
        $penalidades = $lineas->where('es_penalidad', true)->values()->all();
        $lineas = $lineas->where('es_penalidad', false)->values()->all();

        $pasajeros = collect($diferencia['pasajeros'])
            ->filter(fn (array $p) => in_array($p['estado'], ['agregado', 'quitado', 'requiere_nota_credito'], true))
            ->map(fn (array $p) => [
                'accion' => $p['estado'] === 'agregado' ? 'Agregado' : 'Quitado',
                'nombre' => $p['nombre'] ?: ($p['estado'] === 'agregado' ? 'Pasajero nuevo' : 'Pasajero'),
                'tipo' => $p['tipo_pax'] === 'nino' ? 'Niño' : ($p['tipo_pax'] === 'adulto' ? 'Adulto' : ucfirst((string) $p['tipo_pax'])),
            ])->values()->all();

        // Solo los totales exactos de la diferencia. Sin "ya facturado" (un tour facturado a
        // medias cuenta entero, R-AGE-04), sin anticipos (no restan devoluciones) y sin saldo (no
        // ve los cobros de las facturas): en un documento para el cliente serían cifras falsas.
        $totales = [
            'vendido' => (int) $diferencia['totales']['vendido'],
            'nuevo' => (int) $diferencia['totales']['nuevo'],
            'diferencia' => (int) $diferencia['totales']['diferencia'],
        ];
        $emision = HoraPeru::ahora();

        return [
            'moneda' => $diferencia['moneda'],
            'simbolo' => $diferencia['moneda'] === 'USD' ? 'US$' : 'S/',
            'codigoReserva' => $reserva->codigo ?: 'Reserva '.$reserva->id,
            'cliente' => $this->nombreCliente($modificacion, $reserva),
            'emision' => $emision->format('d/m/Y'),
            'aplicadaEn' => $modificacion->aplicado_en ? HoraPeru::deUtc($modificacion->aplicado_en)->format('d/m/Y H:i') : null,
            'lineas' => $lineas,
            'pasajeros' => $pasajeros,
            'penalidades' => $penalidades,
            'totales' => $totales,
            'nombreArchivo' => 'modificacion_'.preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($reserva->codigo ?: $reserva->id)).'_'.$emision->format('d-m-Y').'.pdf',
        ];
    }

    // Abierta → se calcula en vivo; aplicada → lo que se aplicó, aunque la reserva haya cambiado.
    private function diferenciaDe(ReservaModificacion $modificacion): array
    {
        if ($modificacion->estaAbierta()) {
            return $this->diferencia->calcular($modificacion)->toArray();
        }

        if ($modificacion->estado === ReservaModificacion::ESTADO_APLICADA && is_array($modificacion->diferencia_aplicada)) {
            return $modificacion->diferencia_aplicada;
        }

        throw new HttpException(422, 'Esta modificación fue descartada: no hay nada que imprimir.');
    }

    /**
     * @param  array<string, mixed>  $linea
     * @return array<string, mixed>
     */
    private function lineaParaCliente(array $linea, array $idsPenalidad = []): array
    {
        // El ítem de la versión nueva, o el que se vendió si la línea es un servicio quitado.
        $item = AlternativaItem::find($linea['alternativa_item_id'] ?? $linea['item_origen_id'] ?? 0);
        $reservaItem = ! empty($linea['reserva_item_id']) ? ReservaItem::find($linea['reserva_item_id']) : null;

        return [
            // Sin el ítem no se puede resolver la audiencia: nunca se cae a la descripción interna.
            'descripcion' => $item ? ReservaController::resolverNombreItem($item, $reservaItem, 'cliente') : 'Servicio',
            'accion' => match ($linea['estado']) {
                'agregado' => 'Agregado',
                'quitado', 'requiere_nota_credito' => 'Quitado',
                'ajuste' => 'Ajuste de precio',
                'cambio_precio' => 'Cambio de precio',
            },
            'vendido_centavos' => (int) $linea['vendido_centavos'],
            'nuevo_centavos' => (int) $linea['nuevo_centavos'],
            'diferencia_centavos' => (int) $linea['diferencia_centavos'],
            'es_penalidad' => $item !== null && ($item->penalidad_reserva_pasajero_id !== null || in_array($item->id, $idsPenalidad, true)),
        ];
    }

    private function nombreCliente(ReservaModificacion $modificacion, Reserva $reserva): string
    {
        $cotizacion = $modificacion->cotizacion ?? $reserva->alternativa?->cotizacion;

        return (string) ($cotizacion?->cliente?->full_name ?? '');
    }

    private function construir(array $datos)
    {
        // Poppins se registra ANTES de loadView() (dompdf resuelve font-family al parsear el CSS).
        $pdf = app('dompdf.wrapper');
        PdfFontService::registrarPoppins($pdf->getDomPDF());

        $membrete = $this->membrete->datos();
        $agencia = $membrete['empresa']?->razon_social_comercial ?? $membrete['empresa']?->razon_social ?? '';

        return $pdf->loadView('pdf.agencia-viajes.modificacion-reserva', [
            ...$membrete,
            ...$datos,
            'titulo' => 'Modificación de reserva',
            'textoLegal' => trim('Modificación de reserva '.$datos['codigoReserva'].($agencia ? " · {$agencia}" : '')." · Emitido el {$datos['emision']}"),
        ]);
    }
}
