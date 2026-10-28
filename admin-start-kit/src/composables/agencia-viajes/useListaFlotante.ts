// Lista de sugerencias de ProveedorBuscador/GuiaBuscador (T-039). Se ubica con
// position: fixed debajo del input: dentro de una tabla con .table-responsive
// (tablero de salidas, reporte operativo) una lista absoluta queda cortada por
// el scroll del contenedor, cosa que el <select> nativo que reemplaza no tenía.
// Mientras está abierta, sigue al input si la página o un contenedor se desplaza.
import { onBeforeUnmount, ref, watch, type Ref } from 'vue';

const ALTO_MAXIMO = 220;

export function useListaFlotante(input: Ref<HTMLInputElement | null>, abierto: Ref<boolean>) {
    const estilo = ref<Record<string, string>>({});

    const ubicar = () => {
        const r = input.value?.getBoundingClientRect();
        if (!r) return;
        // Sin lugar abajo (input al pie de la ventana): se abre hacia arriba.
        const haciaArriba = r.bottom + ALTO_MAXIMO > window.innerHeight && r.top > ALTO_MAXIMO;
        estilo.value = {
            position: 'fixed',
            ...(haciaArriba ? { bottom: `${window.innerHeight - r.top}px` } : { top: `${r.bottom}px` }),
            left: `${r.left}px`,
            width: `${r.width}px`,
            zIndex: '1060',
            maxHeight: `${ALTO_MAXIMO}px`,
            overflowY: 'auto',
        };
    };

    const quitarEscuchas = () => {
        window.removeEventListener('scroll', ubicar, true);
        window.removeEventListener('resize', ubicar);
    };

    watch(abierto, (si) => {
        quitarEscuchas();
        if (!si) return;
        ubicar();
        window.addEventListener('scroll', ubicar, true);
        window.addEventListener('resize', ubicar);
    });

    onBeforeUnmount(quitarEscuchas);

    return { estilo };
}
