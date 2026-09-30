<?php

declare(strict_types=1);

namespace App\Enums\Creditos;

/** Modo de cálculo de mora (1.6). */
enum ModoMora: string
{
    case DiariaSobreSaldo = 'diaria_sobre_saldo';
}
