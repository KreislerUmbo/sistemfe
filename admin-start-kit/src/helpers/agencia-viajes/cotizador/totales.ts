// Totales del cotizador calculados en el cliente, solo como vista previa mientras se edita:
// el total que vale siempre lo calcula y devuelve el backend. Replican sus reglas:
// PriceEngineService::convertirMoneda() y AlternativaItem::calcularTotalEfectivo().
import type { AlternativaItem } from '@/types/agencia-viajes';

type ItemPrecio = Pick<AlternativaItem, 'id' | 'modo_precio' | 'cantidad' | 'grupo_opcion_id' | 'opcion_elegida' | 'ajuste_de_item_id'>;

export const convertirMonedaLocal = (monto: number, monedaOrigen: string, monedaDestino: string, tipoCambio: number) => {
    if (monedaOrigen === monedaDestino) return Math.round(monto * 100) / 100;
    return monedaOrigen === 'USD' && monedaDestino === 'PEN'
        ? Math.round(monto * tipoCambio * 100) / 100
        : Math.round((monto / tipoCambio) * 100) / 100;
};

// Tarifa fija multiplica por cantidad; por persona ya viene resuelto
// (AlternativaItem::getTotalConvertidoAttribute()).
export const totalDeLinea = (precio: number, item: Pick<AlternativaItem, 'modo_precio' | 'cantidad'>) =>
    item.modo_precio === 'tarifa_fija' ? precio * item.cantidad : precio;

const separarGrupos = <T extends ItemPrecio>(items: T[]) => {
    const sueltos = items.filter((item) => !item.grupo_opcion_id);
    const grupos = new Map<string, T[]>();
    for (const item of items) {
        if (!item.grupo_opcion_id) continue;
        if (!grupos.has(item.grupo_opcion_id)) grupos.set(item.grupo_opcion_id, []);
        grupos.get(item.grupo_opcion_id)!.push(item);
    }
    return { sueltos, grupos: [...grupos.values()] };
};

// Fuera de grupo suma normal; en un grupo resuelto cuenta la elegida; en uno abierto
// (sin elegida), el mínimo.
export const sumaPorGrupos = <T extends ItemPrecio>(items: T[], totalDe: (item: T) => number): number => {
    const { sueltos, grupos } = separarGrupos(items);
    let total = sueltos.reduce((sum, item) => sum + totalDe(item), 0);
    for (const itemsDelGrupo of grupos) {
        const elegida = itemsDelGrupo.find((item) => item.opcion_elegida);
        total += elegida ? totalDe(elegida) : Math.min(...itemsDelGrupo.map(totalDe));
    }
    return total;
};

// El «Ajuste — …» (ajuste_de_item_id) ya está dentro del precio del servicio que ajusta: no suma.
export const totalEfectivo = <T extends ItemPrecio>(items: T[], totalDe: (item: T) => number): number =>
    sumaPorGrupos(items.filter((item) => !item.ajuste_de_item_id), totalDe);

// Una línea por grupo en el resumen: la elegida, o la de menor total si sigue abierto.
export const filasResumen = <T extends ItemPrecio>(items: T[], totalDe: (item: T) => number): T[] => {
    const { sueltos, grupos } = separarGrupos(items);
    const representantes = grupos.map((itemsDelGrupo) =>
        itemsDelGrupo.find((item) => item.opcion_elegida)
            ?? itemsDelGrupo.reduce((min, item) => (totalDe(item) < totalDe(min) ? item : min)));
    return [...sueltos, ...representantes];
};
