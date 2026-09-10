<?php

namespace Tests\Fixtures;

use App\Contracts\RolesCatalogProvider;

// Catálogo de prueba para SyncPermisosNuevosCommandTest — el catálogo REAL de
// agencia_viajes lo siembra Fase 1b (plan-modulo-menus-y-roles.md §3.2).
class CatalogoDePruebaRoles implements RolesCatalogProvider
{
    public function permisos(): array
    {
        return ['prueba.ver', 'prueba.crear', 'prueba.editar'];
    }

    public function roles(): array
    {
        return [
            'Rol De Prueba' => ['prueba.ver', 'prueba.crear', 'prueba.editar'],
            'Rol Que No Existe En Ningun Tenant' => ['prueba.ver'],
        ];
    }
}
