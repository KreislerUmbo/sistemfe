import { PERMISOS, type RolePermiso } from '@/types/roles';

// Fase 2c/2d (plan-modulo-menus-y-roles.md §5/§7) — compartido entre el
// checklist de permisos de un Rol y el de "Permisos directos" de un
// Usuario: cruza el catálogo curado (PERMISOS) contra el catálogo REAL
// del backend y agrega lo que falte bajo "Otros permisos", para que
// ningún permiso real quede invisible/inasignable en ninguna de las 2
// pantallas.
export const humanizarPermiso = (permiso: string): string =>
    permiso
        .replace(/[._-]/g, ' ')
        .replace(/\b\w/g, (letra) => letra.toUpperCase());

export type GrupoPermisos = { name: string; permisos: RolePermiso[] };

export const construirCatalogoCompleto = (permisosDisponibles: string[]): GrupoPermisos[] => {
    const conocidos = new Set(PERMISOS.flatMap((grupo) => grupo.permisos.map((p) => p.permiso)));
    const nuevos = permisosDisponibles.filter((permiso) => !conocidos.has(permiso));

    if (nuevos.length === 0) {
        return PERMISOS;
    }

    return [
        ...PERMISOS,
        {
            name: 'Otros permisos',
            permisos: nuevos.map((permiso) => ({ name: humanizarPermiso(permiso), permiso })),
        },
    ];
};
