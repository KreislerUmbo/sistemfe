import { computed, ref, type Ref } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import httpClient from '@/helpers/http-client';
import { formatFecha } from '@/helpers/fecha';
import { cotizacionService } from '@/services/admin/cotizacionService';
import type { Cotizacion } from '@/types/agencia-viajes';
import type { Client } from '@/types/clients';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

/** Lo que la cabecera necesita del núcleo. */
export interface DependenciasCabecera {
    cotizacion: Ref<Cotizacion | null>;
    cargarCotizacion: () => Promise<void>;
}

/**
 * Cabecera de la cotización: texto de las fechas y corrección de cliente, destino y fechas
 * (cuando uno se equivocó al crearla), con su búsqueda de cliente. No crea watchers.
 */
export function useCabeceraCotizacion(deps: DependenciasCabecera) {
    const { cotizacion, cargarCotizacion } = deps;

    // Un único mensaje cuando falta cualquiera de las dos fechas es más claro que dos
    // placeholders pegados con un guion.
    const textoFechasViaje = computed(() => {
        const desde = cotizacion.value?.fecha_viaje_desde;
        const hasta = cotizacion.value?.fecha_viaje_hasta;
        if (!desde || !hasta) return 'Fecha por confirmar';
        return `${formatFecha(desde)} — ${formatFecha(hasta)}`;
    });

    const editandoCabecera = ref(false);
    const formCabecera = ref({ destino: '', fecha_viaje_desde: '' as string | null, fecha_viaje_hasta: '' as string | null });
    const clienteEditado = ref<Client | null>(null);
    const guardandoCabecera = ref(false);

    const clientSearchText = ref('');
    const clientSuggestions = ref<Client[]>([]);
    const showClientSuggestions = ref(false);
    const showQuickClientModal = ref(false);
    const modoEdicionCliente = ref(false);
    let clientSearchTimeout: any = null;

    const abrirEdicionCabecera = () => {
        if (!cotizacion.value) return;
        // El backend devuelve fecha_viaje_desde/hasta como timestamp ISO completo
        // ('2026-08-20T00:00:00.000000Z', ver formatFecha() en helpers/fecha.ts) y un
        // <input type="date"> solo acepta 'YYYY-MM-DD': sin el slice el campo queda en blanco al
        // reabrir el panel aunque la fecha esté guardada.
        formCabecera.value = {
            destino: cotizacion.value.destino,
            fecha_viaje_desde: cotizacion.value.fecha_viaje_desde ? cotizacion.value.fecha_viaje_desde.slice(0, 10) : '',
            fecha_viaje_hasta: cotizacion.value.fecha_viaje_hasta ? cotizacion.value.fecha_viaje_hasta.slice(0, 10) : '',
        };
        clienteEditado.value = cotizacion.value.cliente
            ? { id: cotizacion.value.cliente.id, full_name: cotizacion.value.cliente.full_name, n_document: cotizacion.value.cliente.n_document } as Client
            : null;
        clientSearchText.value = cotizacion.value.cliente?.full_name ?? '';
        editandoCabecera.value = true;
    };

    const onClientSearchInput = () => {
        clearTimeout(clientSearchTimeout);
        clientSearchTimeout = setTimeout(buscarClientesEditar, 300);
    };

    const buscarClientesEditar = async () => {
        if (clientSearchText.value.trim().length < 2) { clientSuggestions.value = []; return; }
        const res = await httpClient.get('/clients', { params: { search: clientSearchText.value } });
        // ClientController::index() envuelve el listado en ClientCollection
        // ({ data: [...] }) — clients.data, no clients directo.
        clientSuggestions.value = res.data.clients?.data ?? [];
    };

    const seleccionarClienteEditado = (c: Client) => {
        clienteEditado.value = c;
        clientSearchText.value = c.full_name;
        showClientSuggestions.value = false;
    };

    const limpiarClienteEditado = () => {
        clienteEditado.value = null;
        clientSearchText.value = '';
    };

    const abrirNuevoCliente = () => {
        modoEdicionCliente.value = false;
        showQuickClientModal.value = true;
    };

    const abrirEdicionCliente = () => {
        if (!clienteEditado.value) return;
        modoEdicionCliente.value = true;
        showQuickClientModal.value = true;
    };

    const onClientSearchBlur = () => { setTimeout(() => { showClientSuggestions.value = false; }, 150); };

    const onClientCreated = (client: Client) => {
        seleccionarClienteEditado(client);
        showQuickClientModal.value = false;
    };

    const guardarCabecera = async () => {
        if (!cotizacion.value) return;
        if (!clienteEditado.value) { (Swal as TVueSwalInstance).fire('Error', 'Seleccioná un cliente.', 'error'); return; }
        if (!formCabecera.value.destino.trim()) { (Swal as TVueSwalInstance).fire('Error', 'Ingresá un destino.', 'error'); return; }

        guardandoCabecera.value = true;
        try {
            await cotizacionService.actualizar(cotizacion.value.id, {
                cliente_id: clienteEditado.value.id,
                destino: formCabecera.value.destino.trim(),
                fecha_viaje_desde: formCabecera.value.fecha_viaje_desde || null,
                fecha_viaje_hasta: formCabecera.value.fecha_viaje_hasta || null,
            });
            editandoCabecera.value = false;
            await cargarCotizacion();
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire('Error', error.response?.data?.message ?? 'No se pudo actualizar la cotización', 'error');
        } finally {
            guardandoCabecera.value = false;
        }
    };

    return {
        textoFechasViaje, editandoCabecera, formCabecera, clienteEditado, guardandoCabecera, clientSearchText,
        clientSuggestions, showClientSuggestions, showQuickClientModal, modoEdicionCliente, abrirEdicionCabecera,
        onClientSearchInput, seleccionarClienteEditado, limpiarClienteEditado, abrirNuevoCliente,
        abrirEdicionCliente, onClientSearchBlur, onClientCreated, guardarCabecera,
    };
}
