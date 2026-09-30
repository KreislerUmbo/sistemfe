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

    // Cada giro tiene sus propios permisos (Agencia de Viajes, Créditos…): del catálogo
    // curado solo se muestra lo que existe en este tenant, para no ofrecer casillas que el
    // backend rechazaría. Sin la lista real todavía (cargando), se muestra el catálogo tal cual.
    const reales = new Set(permisosDisponibles);
    const curados = reales.size === 0
        ? PERMISOS
        : PERMISOS
            .map((grupo) => ({ ...grupo, permisos: grupo.permisos.filter((p) => reales.has(p.permiso)) }))
            .filter((grupo) => grupo.permisos.length > 0);

    if (nuevos.length === 0) {
        return curados;
    }

    return [
        ...curados,
        {
            name: 'Otros permisos',
            permisos: nuevos.map((permiso) => ({ name: humanizarPermiso(permiso), permiso })),
        },
    ];
};
