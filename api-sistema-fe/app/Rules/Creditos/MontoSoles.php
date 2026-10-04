<?php

declare(strict_types=1);

namespace App\Rules\Creditos;

use App\Services\Creditos\Dinero;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Monto en soles con hasta 2 decimales, mayor a cero y, opcionalmente, múltiplo de un paso en centavos (00 1.1). */
final class MontoSoles implements ValidationRule
{
    public function __construct(private readonly ?int $multiploDeCentavos = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $texto = is_float($value) ? sprintf('%.2f', $value) : (string) $value;
        if (! preg_match('/^\d+(\.\d{1,2})?$/', trim($texto)) || Dinero::aCentavos($texto) <= 0) {
            $fail('El monto debe ser mayor a cero y tener como máximo 2 decimales.');

            return;
        }
        if ($this->multiploDeCentavos !== null && Dinero::aCentavos($texto) % $this->multiploDeCentavos !== 0) {
            $fail('El monto debe ser múltiplo de S/ ' . Dinero::aSoles($this->multiploDeCentavos) . '.');
        }
    }
}
