<?php

namespace App\Models\Concerns;

// T-108 — Descripción editable de una línea (sale_details y note_details).
// `descripcion_linea` REEMPLAZA al título del producto en XML, PDF y ticket.
// Un solo lugar para "qué cuenta como descripción editada", para que el XML,
// el PDF A4 y el ticket nunca discrepen.
trait ConDescripcionLinea
{
    public const DESCRIPCION_LINEA_MAX = 500;

    // El texto editado ya normalizado (saltos de línea \n, sin espacios en los
    // extremos, tope de SUNAT) o null si no hay: vacío o solo espacios = sin editar.
    public function descripcionLineaLimpia(): ?string
    {
        $texto = trim(str_replace(["\r\n", "\r"], "\n", (string) $this->descripcion_linea));

        if ($texto === '') {
            return null;
        }

        return mb_substr($texto, 0, self::DESCRIPCION_LINEA_MAX);
    }

    public function tieneDescripcionEditada(): bool
    {
        return $this->descripcionLineaLimpia() !== null;
    }
}
