// Cómo se agrupan los ítems de una alternativa en el lienzo y en el panel de precio.
import type { AlternativaItem } from '@/types/agencia-viajes';

// clave: identificador estable para :key (tourOrigenId puede repetirse entre un bloque
// clásico y una carga del mismo tour).
export type BloqueItem = {
    clave: string;
    tourOrigenId: number | null;
    tourNombre: string | null;
    items: AlternativaItem[];
    carga: string | null;
    cargaCombo: string | null;
    comboNombre: string | null;
};

// La línea de tour (origen_tipo='tour') se agrupa por su carga (carga_tour: una carga = un
// tour cargado, con una línea por grupo de IGV), no por tour_origen_id: dos cargas del mismo
// tour son dos bloques, y un bloque clásico del mismo tour no se mezcla con la línea nueva.
// Los ítems sin tour van al final, en un bloque «sueltos».
export const agruparPorTour = (items: AlternativaItem[]): BloqueItem[] => {
    const bloques = new Map<string, BloqueItem>();
    const sueltos: AlternativaItem[] = [];

    for (const item of items) {
        if (item.origen_tipo === 'tour' && item.carga_tour) {
            const clave = `carga-${item.carga_tour}`;
            if (!bloques.has(clave)) {
                bloques.set(clave, {
                    clave,
                    tourOrigenId: item.tour_origen_id ?? null,
                    tourNombre: item.tour_origen?.nombre ?? item.tour_linea?.tour_nombre ?? null,
                    items: [],
                    carga: item.carga_tour,
                    cargaCombo: item.carga_combo ?? null,
                    comboNombre: item.tour_linea?.combo?.nombre ?? null,
                });
            }
            bloques.get(clave)!.items.push(item);
        } else if (item.tour_origen_id) {
            const clave = `tour-${item.tour_origen_id}`;
            if (!bloques.has(clave)) {
                bloques.set(clave, { clave, tourOrigenId: item.tour_origen_id, tourNombre: item.tour_origen?.nombre ?? null, items: [], carga: null, cargaCombo: null, comboNombre: null });
            }
            bloques.get(clave)!.items.push(item);
        } else {
            sueltos.push(item);
        }
    }

    const resultado = Array.from(bloques.values());
    if (sueltos.length) resultado.push({ clave: 'sueltos', tourOrigenId: null, tourNombre: null, items: sueltos, carga: null, cargaCombo: null, comboNombre: null });

    return resultado;
};

// Dentro de un bloque, los ítems consecutivos con el mismo grupo_opcion_id son UNA tarjeta
// («comparar varias opciones»). Asume adyacencia: los ítems de un grupo se crean en un solo
// lote con ids consecutivos; un grupo partido por otro ítem en medio sale como dos tarjetas.
export type FilaLienzo =
    | { esGrupo: false; item: AlternativaItem }
    | { esGrupo: true; grupoId: string; items: AlternativaItem[] };

export const filasDelBloque = (items: AlternativaItem[]): FilaLienzo[] => {
    const filas: FilaLienzo[] = [];
    for (const item of items) {
        const ultima = filas[filas.length - 1];
        if (item.grupo_opcion_id && ultima?.esGrupo && ultima.grupoId === item.grupo_opcion_id) {
            ultima.items.push(item);
        } else if (item.grupo_opcion_id) {
            filas.push({ esGrupo: true, grupoId: item.grupo_opcion_id, items: [item] });
        } else {
            filas.push({ esGrupo: false, item });
        }
    }
    return filas;
};
