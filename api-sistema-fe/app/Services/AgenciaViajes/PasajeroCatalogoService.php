<?php

namespace App\Services\AgenciaViajes;

use App\Models\AgenciaViajes\PasajeroCatalogo;
use App\Models\AgenciaViajes\PasajeroDocumento;
use App\Models\Client\Client;
use App\Services\HoraPeru;

// Ficha del catálogo de pasajeros (pasajeros_catalogo) de un cliente — T-017;
// T-018 la reusa. Solo hay ficha para un cliente que es una PERSONA con
// documento real: type_document DNI/CE/PAS (lo normaliza ClienteRequest). No se
// usa type_client (empresas con RUC también traen 1) ni cod_tipo_doc_sunat
// (viene null en algunas filas).
class PasajeroCatalogoService
{
    private const TIPOS_PERSONA = ['DNI', 'CE', 'PAS'];

    // Nombre, tipo y número de documento con los que el cliente viaja; null si
    // no califica (empresa, SND/TM, documento vacío o 00000000, sin nombre).
    public function datosDePasajero(Client $cliente): ?array
    {
        $tipo = strtoupper(trim((string) $cliente->type_document));
        $documento = trim((string) $cliente->n_document);

        if (! in_array($tipo, self::TIPOS_PERSONA, true) || $documento === '' || $documento === '00000000') {
            return null;
        }

        $nombre = trim((string) $cliente->full_name);
        if ($nombre === '') {
            $nombre = trim($cliente->name.' '.$cliente->surname);
        }
        if ($nombre === '') {
            return null;
        }

        return ['nombre' => $nombre, 'tipo_documento' => $tipo, 'documento' => $documento];
    }

    // Busca por cliente_id; si no, por documento (y le completa el cliente_id); si
    // no, la crea. Devuelve null si el cliente no califica. Si la ficha hallada por
    // documento ya tiene OTRO cliente_id (dos clientes con el mismo documento) se
    // usa tal cual, sin cambiarlo.
    // Debe llamarse dentro de una transacción: bloquea la fila del cliente para que
    // dos aceptaciones simultáneas de cotizaciones del mismo cliente no creen dos fichas.
    public function desdeCliente(Client $cliente): ?PasajeroCatalogo
    {
        $datos = $this->datosDePasajero($cliente);
        if ($datos === null) {
            return null;
        }

        Client::where('id', $cliente->id)->lockForUpdate()->first();

        $ficha = PasajeroCatalogo::where('cliente_id', $cliente->id)->orderBy('id')->first();
        if ($ficha) {
            return $ficha;
        }

        $ficha = PasajeroDocumento::where('numero_documento', $datos['documento'])
            ->orderBy('id')
            ->first()?->pasajeroCatalogo;
        if ($ficha) {
            if ($ficha->cliente_id === null) {
                $ficha->update(['cliente_id' => $cliente->id]);
            }

            return $ficha;
        }

        $ficha = PasajeroCatalogo::create(['cliente_id' => $cliente->id, 'nombre' => $datos['nombre']]);
        PasajeroDocumento::create([
            'pasajero_catalogo_id' => $ficha->id,
            'tipo_documento' => $datos['tipo_documento'],
            'numero_documento' => $datos['documento'],
            'fecha_registro' => HoraPeru::hoyTexto(),
        ]);

        return $ficha;
    }
}
