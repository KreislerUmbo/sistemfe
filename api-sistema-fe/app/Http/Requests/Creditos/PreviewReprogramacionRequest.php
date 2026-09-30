<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

/** Vista previa de una reprogramación (sin escribir). */
class PreviewReprogramacionRequest extends ReprogramarRequest
{
    protected bool $esPreview = true;
}
