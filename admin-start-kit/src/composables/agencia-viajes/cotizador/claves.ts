import { inject, type ComputedRef, type InjectionKey, type Ref } from 'vue';
import type { AlternativaItem } from '@/types/agencia-viajes';
import type { useCotizacionEditor } from './useCotizacionEditor';
import type { useEdicionPrecios } from './useEdicionPrecios';
import type { usePasajerosCotizacion } from './usePasajerosCotizacion';
import type { useCabeceraCotizacion } from './useCabeceraCotizacion';
import type { useResultadoCotizacion } from './useResultadoCotizacion';
import type { useAlternativas } from './useAlternativas';
import type { useDestinosAlternativa } from './useDestinosAlternativa';
import type { useDiasLienzo } from './useDiasLienzo';
import type { useLineaTour } from './useLineaTour';
import type { useBloquesLienzo } from './useBloquesLienzo';
import type { PasoDrawer, useDrawerBiblioteca } from './useDrawerBiblioteca';
import type { useHotelesLocales } from './useHotelesLocales';
import type { useBibliotecaCotizador } from './useBibliotecaCotizador';
import type { useOpcionesMayorista } from './useOpcionesMayorista';
import type { useHotelesMayorista } from './useHotelesMayorista';
import type { useOpcionalesMayorista } from './useOpcionalesMayorista';
import type { useModificacionEnCotizador } from './useModificacionEnCotizador';
import type { useRevisionAlternativa } from './useRevisionAlternativa';
import type { useGuiaCotizador } from './useGuiaCotizador';
import type { useItemsSueltos } from './useItemsSueltos';

/*
 * Claves de provide/inject de la pantalla del cotizador (T-105).
 *
 * La página (`views/agencia-viajes/cotizador/editar.vue`) crea cada composable en su orden y lo
 * publica entero con `provide(CLAVE_…, objeto)`. Los componentes de
 * `components/AgenciaViajes/cotizador/` lo reciben con `inyectar(CLAVE_…)` y desestructuran lo que
 * usan: el template solo desenvuelve refs de primer nivel (recorrido 69 §1, regla 3).
 */

export const CLAVE_NUCLEO: InjectionKey<ReturnType<typeof useCotizacionEditor>> = Symbol('nucleo');
export const CLAVE_PRECIOS: InjectionKey<ReturnType<typeof useEdicionPrecios>> = Symbol('precios');
export const CLAVE_PASAJEROS: InjectionKey<ReturnType<typeof usePasajerosCotizacion>> = Symbol('pasajeros');
export const CLAVE_CABECERA: InjectionKey<ReturnType<typeof useCabeceraCotizacion>> = Symbol('cabecera');
export const CLAVE_RESULTADO: InjectionKey<ReturnType<typeof useResultadoCotizacion>> = Symbol('resultado');
export const CLAVE_ALTERNATIVAS: InjectionKey<ReturnType<typeof useAlternativas>> = Symbol('alternativas');
export const CLAVE_DESTINOS: InjectionKey<ReturnType<typeof useDestinosAlternativa>> = Symbol('destinos');
export const CLAVE_DIAS: InjectionKey<ReturnType<typeof useDiasLienzo>> = Symbol('dias');
export const CLAVE_LINEA_TOUR: InjectionKey<ReturnType<typeof useLineaTour>> = Symbol('lineaTour');
export const CLAVE_BLOQUES: InjectionKey<ReturnType<typeof useBloquesLienzo>> = Symbol('bloques');
export const CLAVE_DRAWER: InjectionKey<ReturnType<typeof useDrawerBiblioteca>> = Symbol('drawer');
export const CLAVE_HOTELES_LOCALES: InjectionKey<ReturnType<typeof useHotelesLocales>> = Symbol('hotelesLocales');
export const CLAVE_BIBLIOTECA: InjectionKey<ReturnType<typeof useBibliotecaCotizador>> = Symbol('biblioteca');
export const CLAVE_OPCIONES_MAYORISTA: InjectionKey<ReturnType<typeof useOpcionesMayorista>> = Symbol('opcionesMayorista');
export const CLAVE_HOTELES_MAYORISTA: InjectionKey<ReturnType<typeof useHotelesMayorista>> = Symbol('hotelesMayorista');
export const CLAVE_OPCIONALES_MAYORISTA: InjectionKey<ReturnType<typeof useOpcionalesMayorista>> = Symbol('opcionalesMayorista');
export const CLAVE_MODIFICACION: InjectionKey<ReturnType<typeof useModificacionEnCotizador>> = Symbol('modificacion');
export const CLAVE_REVISION: InjectionKey<ReturnType<typeof useRevisionAlternativa>> = Symbol('revision');
export const CLAVE_GUIA: InjectionKey<ReturnType<typeof useGuiaCotizador>> = Symbol('guia');
export const CLAVE_ITEMS_SUELTOS: InjectionKey<ReturnType<typeof useItemsSueltos>> = Symbol('itemsSueltos');

/** Lo que vive en la página y no en un composable. */
export interface ContextoPagina {
    modo: Ref<'local' | 'intl' | 'guia'>;
    seleccionarAlternativa: (id: number) => void;
    tipoMayoristaId: Ref<number | null>;
    tipoHotelId: Ref<number | null>;
    desglosePasajerosTexto: (item: AlternativaItem) => string | null;
    pasoDrawer: ComputedRef<PasoDrawer>;
    tituloDrawer: ComputedRef<string>;
}

export const CLAVE_PAGINA: InjectionKey<ContextoPagina> = Symbol('pagina');

/** `inject` que falla en voz alta: un componente del cotizador montado fuera de su pantalla. */
export function inyectar<T>(clave: InjectionKey<T>): T {
    const valor = inject(clave, null);
    if (valor === null) {
        throw new Error(`${clave.description ?? 'Clave'} usado fuera de la pantalla del cotizador`);
    }
    return valor;
}
