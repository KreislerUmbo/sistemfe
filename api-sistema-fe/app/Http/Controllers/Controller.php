<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Fase 1b (plan-modulo-menus-y-roles.md §3.3) — habilita $this->authorize()
    // (CotizacionController/ReservaController, Policy como segunda barrera
    // explícita sobre el Global Scope de EscopablePorVendedor). No afecta a
    // ningún otro controller: el trait solo agrega el método, no cambia nada
    // de lo existente.
    use AuthorizesRequests;
}
