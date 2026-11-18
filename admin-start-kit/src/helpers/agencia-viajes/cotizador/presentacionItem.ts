// Cómo se muestra un ítem del cotizador: nombre, ícono, tratamiento tributario, desglose
// de pasajeros. Solo presentación sobre datos ya cargados; no calcula precios.
import type { AlternativaItem, CotizacionPasajero, DestinoServicio, OpcionMayoristaTour } from '@/types/agencia-viajes';

type TipoPax = 'adulto' | 'nino' | 'infante';

// Instante UTC ya pasado = tarifa aérea vencida.
export const tarifaAereaVencida = (iso: string, ahora: number = Date.now()) => new Date(iso).getTime() < ahora;

// Vista previa compacta de un HTML del editor (incluye, vuelo_detalle): la interpolación
// {{ }} mostraría las etiquetas crudas. Cada etiqueta se cambia por un espacio para no
// pegar palabras de líneas distintas.
export const textoPlano = (html: string) => html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();

// destino_atractivo puede venir null (no todo servicio está atado a un destino).
export const descripcionDestinoServicio = (ds?: DestinoServicio) => {
    const destino = ds?.destino_atractivo?.nombre;
    const servicio = ds?.servicio?.nombre ?? '';
    return destino ? `${destino} · ${servicio}` : servicio;
};

export const iconoItem = (item: AlternativaItem) => {
    if (item.origen_tipo === 'tour') return 'fa-route';
    if (item.origen_tipo === 'pasaje_aereo') return 'fa-plane';
    if (item.origen_tipo === 'mayorista') return 'fa-plane-departure';
    if (item.origen_tipo === 'manual') return 'fa-pen';
    if (item.origen_tipo === 'guia') return 'fa-user-tie';
    if (item.proveedor_tarifa?.tipo_habitacion || item.opcion_hotel_tarifa) return 'fa-bed';
    return 'fa-concierge-bell';
};

// Todos los ítems de un grupo comparten opcion_mayorista: con el primero alcanza.
export const nombreMayoristaDeGrupo = (items: AlternativaItem[]): string | null => {
    const op = items[0]?.opcion_mayorista;
    return op?.proveedor?.nombre_comercial ?? op?.proveedor?.razon_social ?? null;
};

// Itinerario incluido del mayorista del grupo, sin precio (ya está prorrateado en el paquete).
export const toursDeGrupo = (items: AlternativaItem[]): OpcionMayoristaTour[] => {
    return items[0]?.opcion_mayorista?.tours ?? [];
};

// Copia del resolver del backend: ReservaController::resolverNombreItem().
export const etiquetaItem = (item: AlternativaItem) => {
    if (item.origen_tipo === 'manual') return item.descripcion_manual ?? 'Servicio sin catálogo';
    if (item.origen_tipo === 'tour') {
        const nombre = item.tour_origen?.nombre ?? item.tour_linea?.tour_nombre ?? 'Tour';
        const sufijo: Record<string, string> = { '10': ' (gravado)', '20': ' (exonerado)', '30': ' (inafecto)' };
        return (item.tour_linea?.n_grupos ?? 1) > 1 ? nombre + (sufijo[item.tip_afe_igv ?? ''] ?? '') : nombre;
    }
    if (item.origen_tipo === 'pasaje_aereo') return item.cotizacion_pasaje_aereo?.aerolinea ?? 'Pasaje aéreo';
    // Un opcional del mayorista lleva su propio nombre; nunca tiene tarifa de habitación,
    // por eso va antes de la rama de opcion_hotel_tarifa.
    if (item.origen_tipo === 'mayorista' && item.opcion_mayorista_opcional) {
        return item.opcion_mayorista_opcional.nombre;
    }
    // Paquete de mayorista «tarifa fija» sin habitación propia.
    if (item.origen_tipo === 'mayorista' && !item.opcion_hotel_tarifa) {
        return item.opcion_mayorista?.proveedor?.nombre_comercial ?? item.opcion_mayorista?.proveedor?.razon_social ?? 'Paquete mayorista';
    }
    if (item.origen_tipo === 'guia') {
        const nombreGuia = item.guia_tarifa?.guia?.nombre ?? 'Guía';
        return `Guía: ${nombreGuia}${item.guia_tarifa?.destino?.nombre ? ` — ${item.guia_tarifa.destino.nombre}` : ''}`;
    }
    if (item.proveedor_tarifa?.tipo_habitacion) {
        // Hotel: «Proveedor · tipo_habitación» (la categoría «Alojamiento» no dice nada).
        const proveedor = item.proveedor_tarifa.proveedor_servicio?.proveedor?.nombre_comercial ?? item.proveedor_tarifa.proveedor_servicio?.proveedor?.razon_social ?? 'Hotel';
        return `${proveedor} · ${item.proveedor_tarifa.tipo_habitacion}`;
    }
    // Hotel de la matriz de un mayorista o hotel sin catálogo del flujo local.
    if (item.opcion_hotel_tarifa) {
        const nombreHotel = item.opcion_hotel_tarifa.opcion_hotel?.nombre_hotel ?? 'Hotel';
        return `${nombreHotel} · ${item.opcion_hotel_tarifa.tipo_habitacion}`;
    }
    return descripcionDestinoServicio(item.proveedor_tarifa?.proveedor_servicio?.destino_servicio) || 'Servicio';
};

// Mismo conteo que AlternativaItemController::contarPasajerosPorTipo(): sin pax_incluidos,
// el ítem aplica a todos.
export const contarPasajerosDeItem = (item: AlternativaItem, pasajeros: CotizacionPasajero[]) => {
    const filtrados = item.pax_incluidos && item.pax_incluidos.length
        ? pasajeros.filter((p) => item.pax_incluidos!.includes(p.id))
        : pasajeros;
    const conteo: Record<TipoPax, number> = { adulto: 0, nino: 0, infante: 0 };
    filtrados.forEach((p) => { conteo[p.tipo_pax] += 1; });
    return conteo;
};

// Tratamiento tributario ya resuelto en el ítem, visible antes de facturar (misma cascada que
// ReservaFacturacionController). null si el ítem no tiene nada capturado.
export const badgeTratamientoTributario = (item: AlternativaItem): { texto: string; clase: string } | null => {
    if (!item.tip_afe_igv) return null;

    const etiquetaTipo: Record<string, { texto: string; clase: string }> = {
        '10': { texto: 'Gravado', clase: 'bg-secondary-subtle text-secondary' },
        '20': { texto: 'Exonerado', clase: 'bg-success-subtle text-success' },
        '30': { texto: 'Inafecto', clase: 'bg-info-subtle text-info' },
    };
    const base = etiquetaTipo[item.tip_afe_igv] ?? { texto: item.tip_afe_igv, clase: 'bg-light text-dark' };

    if (item.destino_tributario && item.destino_tributario !== 'nacional') {
        const etiquetaDestino = item.destino_tributario === 'amazonia' ? 'Amazonía' : 'Extranjero';
        return { texto: `${base.texto} · ${etiquetaDestino}`, clase: base.clase };
    }

    return base;
};

// «3 adultos × PEN 70 + 1 niño × PEN 45» bajo cada línea por persona. Un manual con
// pax_incluidos parcial sigue siendo tarifa fija, pero dice a quiénes aplica.
export const desglosePasajerosTexto = (item: AlternativaItem, pasajeros: CotizacionPasajero[]): string | null => {
    if (item.origen_tipo === 'manual' && item.pax_incluidos && item.pax_incluidos.length) {
        return `Aplica a: ${item.pax_incluidos.length} de ${pasajeros.length} pasajeros`;
    }

    if (item.modo_precio !== 'por_persona') return null;

    const precios = item.origen_tipo === 'pasaje_aereo' && item.cotizacion_pasaje_aereo
        ? {
            adulto: item.cotizacion_pasaje_aereo.tarifa_base_adulto,
            nino: item.cotizacion_pasaje_aereo.tarifa_base_nino,
            infante: item.cotizacion_pasaje_aereo.tarifa_base_infante,
        }
        : item.proveedor_tarifa
            ? {
                adulto: item.proveedor_tarifa.precio_venta_adulto,
                nino: item.proveedor_tarifa.precio_venta_nino,
                infante: item.proveedor_tarifa.precio_venta_infante,
            }
            : null;

    if (!precios) return null;

    const conteo = contarPasajerosDeItem(item, pasajeros);
    const etiquetas: Record<TipoPax, string> = { adulto: 'adulto', nino: 'niño', infante: 'infante' };

    const partes = (['adulto', 'nino', 'infante'] as const)
        .filter((t) => conteo[t] > 0 && precios[t] != null)
        .map((t) => `${conteo[t]} ${etiquetas[t]}${conteo[t] > 1 ? 's' : ''} × ${item.moneda_costo} ${Number(precios[t]).toFixed(0)}`);

    return partes.length ? partes.join(' + ') : null;
};
