<?php

namespace App\Services;

use App\Models\Sale\NoteSerie;
use Symfony\Component\HttpKernel\Exception\HttpException;

// ── Único punto de la app que resuelve la serie de una nota ───────────────
// Lee la tabla note_series en vez de tener la serie hardcodeada en el
// controlador, porque el usuario va a construir más adelante un módulo de
// series personalizables para factura/boleta/notas — cuando eso exista,
// solo hay que cambiar la fuente de datos detrás de este método (o
// reapuntar note_series a ese nuevo módulo), sin tocar
// NotaElectronicaController ni GreenterService.
class SerieNotaResolver
{
    public function resolver(string $tipoDoc, string $tipoDocAfectado): string
    {
        $config = NoteSerie::where('tipo_doc', $tipoDoc)
            ->where('tipo_doc_afectado', $tipoDocAfectado)
            ->first();

        if (!$config) {
            throw new HttpException(
                422,
                "No hay una serie configurada para tipo_doc={$tipoDoc}, tipo_doc_afectado={$tipoDocAfectado}. Revisa la tabla note_series."
            );
        }

        return $config->serie;
    }
}
