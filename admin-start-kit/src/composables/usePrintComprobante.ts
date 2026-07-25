import Swal from 'sweetalert2/dist/sweetalert2.js';
import httpClient from '@/helpers/http-client';

export type FormatoImpresion = 'a4' | 'ticket80mm';

// Pregunta al usuario si desea imprimir el comprobante, con el formato
// (A4 / Ticket 80mm) preseleccionado según su preferencia guardada.
// Usado tras registrar/editar una venta y desde "Reimprimir" en el listado.
export async function imprimirComprobante(
    saleId: string | number,
    formatoDefault: FormatoImpresion = 'a4',
): Promise<void> {
    const result = await Swal.fire({
        title: '¿Desea imprimir el comprobante?',
        icon: 'question',
        html: `
            <div style="text-align:left;display:inline-block;">
                <label style="display:block;margin:6px 0;">
                    <input type="radio" name="formato_impresion" value="a4" ${formatoDefault === 'a4' ? 'checked' : ''}>
                    Formato A4
                </label>
                <label style="display:block;margin:6px 0;">
                    <input type="radio" name="formato_impresion" value="ticket80mm" ${formatoDefault === 'ticket80mm' ? 'checked' : ''}>
                    Ticket térmico 80mm
                </label>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Imprimir',
        cancelButtonText: 'Omitir',
        preConfirm: () => {
            const seleccionado = document.querySelector<HTMLInputElement>(
                'input[name="formato_impresion"]:checked',
            );
            return seleccionado?.value ?? formatoDefault;
        },
    });

    if (!result.isConfirmed) {
        return;
    }

    const formato = (result.value as FormatoImpresion) ?? formatoDefault;

    try {
        const { data } = await httpClient.get(`sales-pdf-url/${saleId}`, {
            params: { format: formato },
        });

        abrirEImprimir(data.url);
    } catch (e: any) {
        Swal.fire('Error', e.response?.data?.message ?? 'No se pudo generar el PDF del comprobante.', 'error');
    }
}

// Mismo flujo que imprimirComprobante(), pero para una Nota de Crédito/Débito
// (endpoint notas-pdf-url en vez de sales-pdf-url). Ver NotaController::pdfSignedUrl().
export async function imprimirNota(
    notaId: string | number,
    formatoDefault: FormatoImpresion = 'a4',
): Promise<void> {
    const result = await Swal.fire({
        title: '¿Desea imprimir la nota?',
        icon: 'question',
        html: `
            <div style="text-align:left;display:inline-block;">
                <label style="display:block;margin:6px 0;">
                    <input type="radio" name="formato_impresion_nota" value="a4" ${formatoDefault === 'a4' ? 'checked' : ''}>
                    Formato A4
                </label>
                <label style="display:block;margin:6px 0;">
                    <input type="radio" name="formato_impresion_nota" value="ticket80mm" ${formatoDefault === 'ticket80mm' ? 'checked' : ''}>
                    Ticket térmico 80mm
                </label>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Imprimir',
        cancelButtonText: 'Omitir',
        preConfirm: () => {
            const seleccionado = document.querySelector<HTMLInputElement>(
                'input[name="formato_impresion_nota"]:checked',
            );
            return seleccionado?.value ?? formatoDefault;
        },
    });

    if (!result.isConfirmed) {
        return;
    }

    const formato = (result.value as FormatoImpresion) ?? formatoDefault;

    try {
        const { data } = await httpClient.get(`notas-pdf-url/${notaId}`, {
            params: { format: formato },
        });

        abrirEImprimir(data.url);
    } catch (e: any) {
        Swal.fire('Error', e.response?.data?.message ?? 'No se pudo generar el PDF de la nota.', 'error');
    }
}

// Mismo flujo que imprimirComprobante()/imprimirNota(), para el recibo de
// pago (documento interno, sin QR fiscal — ver PaymentReceiptController::pdfSignedUrl()).
export async function imprimirReciboPago(
    receiptId: string | number,
    formatoDefault: FormatoImpresion = 'a4',
): Promise<void> {
    const result = await Swal.fire({
        title: '¿Desea imprimir el recibo de pago?',
        icon: 'question',
        html: `
            <div style="text-align:left;display:inline-block;">
                <label style="display:block;margin:6px 0;">
                    <input type="radio" name="formato_impresion_recibo" value="a4" ${formatoDefault === 'a4' ? 'checked' : ''}>
                    Formato A4
                </label>
                <label style="display:block;margin:6px 0;">
                    <input type="radio" name="formato_impresion_recibo" value="ticket80mm" ${formatoDefault === 'ticket80mm' ? 'checked' : ''}>
                    Ticket térmico 80mm
                </label>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Imprimir',
        cancelButtonText: 'Omitir',
        preConfirm: () => {
            const seleccionado = document.querySelector<HTMLInputElement>(
                'input[name="formato_impresion_recibo"]:checked',
            );
            return seleccionado?.value ?? formatoDefault;
        },
    });

    if (!result.isConfirmed) {
        return;
    }

    const formato = (result.value as FormatoImpresion) ?? formatoDefault;

    try {
        const { data } = await httpClient.get(`payment-receipts-pdf-url/${receiptId}`, {
            params: { format: formato },
        });

        abrirEImprimir(data.url);
    } catch (e: any) {
        Swal.fire('Error', e.response?.data?.message ?? 'No se pudo generar el PDF del recibo de pago.', 'error');
    }
}

// Intento best-effort de disparar el diálogo de impresión automáticamente.
// El PDF vive en otro origen (backend), así que el navegador puede bloquear
// el acceso a la ventana — en ese caso, el usuario igual puede imprimir
// desde el botón del visor de PDF nativo del navegador.
function abrirEImprimir(url: string): void {
    const ventana = window.open(url, '_blank');

    if (ventana) {
        ventana.addEventListener('load', () => {
            try {
                ventana.print();
            } catch {
                // Bloqueado por política cross-origin — no es crítico.
            }
        });
    }
}
