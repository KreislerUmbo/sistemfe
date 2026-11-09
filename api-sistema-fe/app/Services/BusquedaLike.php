<?php

namespace App\Services;

// T-092: patrón para `like`/`ilike` a partir de lo que escribe el usuario.
// En Postgres `%` y `_` son comodines y `\` es el carácter de escape por
// defecto: sin escaparlos, buscar «50%» traía cualquier nombre que tuviera
// un «50» y «_» coincidía con cualquier letra.
class BusquedaLike
{
    // Escapa los comodines: el texto se busca literal.
    public static function escapar(string $texto): string
    {
        return addcslashes($texto, '\\%_');
    }

    // «contiene»: %texto%.
    public static function contiene(string $texto): string
    {
        return '%' . self::escapar($texto) . '%';
    }
}
