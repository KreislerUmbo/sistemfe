// Descarga de archivos que pide la API como blob (PDF de cotización, condiciones generales…).
import httpClient from '@/helpers/http-client';

// El servidor arma el nombre del archivo y lo manda en Content-Disposition
// (filename*=UTF-8'' o filename="").
export const nombreDeContentDisposition = (cabecera?: string): string | null => {
    if (!cabecera) return null;
    const utf8 = /filename\*\s*=\s*UTF-8''([^;]+)/i.exec(cabecera);
    if (utf8) {
        try { return decodeURIComponent(utf8[1].trim()); } catch { /* cae al filename simple */ }
    }
    const simple = /filename\s*=\s*"?([^";]+)"?/i.exec(cabecera);
    return simple ? simple[1].trim() : null;
};

export const descargarArchivo = async (url: string, nombreSugerido: string) => {
    const response = await httpClient.get(url, { responseType: 'blob' });
    const blobUrl = window.URL.createObjectURL(new Blob([response.data]));
    const link = document.createElement('a');
    link.href = blobUrl;
    link.setAttribute('download', nombreDeContentDisposition(response.headers?.['content-disposition']) ?? nombreSugerido);
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(blobUrl);
};

// Con responseType:'blob' un error (422, 404…) también llega como Blob: se lee para mostrar
// el mensaje real. Prioriza el primer error de validación sobre `message` (a diferencia de
// mensajeDeError() de useModificacionReserva, que solo lee `message`).
export const leerMensajeErrorBlob = async (error: any, fallback: string): Promise<string> => {
    const data = error?.response?.data;
    if (data instanceof Blob && data.type === 'application/json') {
        try {
            const texto = await data.text();
            const json = JSON.parse(texto);
            const primerError = json.errors ? Object.values(json.errors)[0] : null;
            return (Array.isArray(primerError) ? primerError[0] : null) ?? json.message ?? fallback;
        } catch {
            return fallback;
        }
    }
    return error?.response?.data?.message ?? fallback;
};
