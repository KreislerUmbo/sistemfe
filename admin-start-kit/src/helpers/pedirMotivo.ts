import Swal from 'sweetalert2/dist/sweetalert2.js';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

/**
 * Pide un motivo y ejecuta la acción; el rechazo del servidor se muestra en el mismo diálogo.
 * Con un b-modal abierto no se puede escribir: ciérralo antes.
 */
export const pedirMotivo = async (titulo: string, texto: string, boton: string, accion: (motivo: string) => Promise<unknown>) => {
    const { isConfirmed } = await (Swal as TVueSwalInstance).fire({
        title: titulo,
        text: texto,
        input: 'textarea',
        inputPlaceholder: 'Motivo',
        inputAttributes: { maxlength: '255' },
        showCancelButton: true,
        confirmButtonText: boton,
        cancelButtonText: 'Cancelar',
        showLoaderOnConfirm: true,
        preConfirm: async (motivo: string) => {
            try {
                await accion(motivo);
            } catch (e: any) {
                (Swal as TVueSwalInstance).showValidationMessage(e.response?.data?.message ?? 'No se pudo completar la operación.');
                return false;
            }
            return true;
        },
    });
    return isConfirmed as boolean;
};
