/**
 * Id para agrupar las opciones que se comparan (`alternativa_items.grupo_opcion_id`).
 *
 * `crypto.randomUUID()` solo existe en un contexto seguro (HTTPS o localhost); en dev el tenant se
 * abre por http://<tenant>.sistemafe.test y el navegador no la expone. El id solo agrupa filas
 * (no tiene tabla propia), así que no hace falta que sea criptográficamente fuerte.
 */
export function generarUuidGrupo(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        const v = c === 'x' ? r : (r & 0x3) | 0x8;
        return v.toString(16);
    });
}
