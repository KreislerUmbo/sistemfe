<?php

namespace App\Contracts;

// Fase 1a (plan-modulo-menus-y-roles.md §9.4) — contrato mínimo que
// `roles:sync-permisos-nuevos` necesita de un catálogo de roles/permisos por
// giro. Fase 1b hace que `AgenciaViajesRolesSeeder` (u otro nombre que
// decida) lo implemente; en Fase 1a no existe todavía ningún implementador
// real, el comando se prueba con un catálogo de prueba (ver
// tests/Feature/SyncPermisosNuevosCommandTest.php).
interface RolesCatalogProvider
{
    /** @return string[] Todos los permisos del catálogo (se crean con firstOrCreate). */
    public function permisos(): array;

    /** @return array<string, string[]> nombre de rol => permisos que debería tener */
    public function roles(): array;
}
