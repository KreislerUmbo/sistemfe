<template>
    <DefaultLayout>
        <div class="card">
            <div class="card-header">
                <h4 class="card-title mb-0">Periodos de compras</h4>
                <small class="text-muted">En un periodo cerrado no se registran, editan ni anulan compras.</small>
            </div>
            <div class="card-body">
                <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>
                <div class="table-responsive">
                    <table class="table table-sm table-centered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Periodo</th>
                                <th>Estado</th>
                                <th class="text-end">Compras</th>
                                <th>Último cierre</th>
                                <th>Última reapertura</th>
                                <th class="text-end">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="p in periodos" :key="p.periodo">
                                <tr>
                                    <td class="fw-semibold">{{ p.periodo }}</td>
                                    <td>
                                        <span class="badge" :class="p.estado === 'cerrado' ? 'bg-secondary' : 'bg-success'">
                                            {{ p.estado === 'cerrado' ? 'Cerrado' : 'Abierto' }}
                                        </span>
                                    </td>
                                    <td class="text-end">{{ p.compras }}</td>
                                    <td>
                                        <template v-if="p.cerrado_at">{{ formatFechaHora(p.cerrado_at) }} · {{ p.cerrado_por ?? '—' }}</template>
                                        <span v-else class="text-muted">—</span>
                                    </td>
                                    <td>
                                        <template v-if="p.reabierto_at">
                                            {{ formatFechaHora(p.reabierto_at) }} · {{ p.reabierto_por ?? '—' }}
                                            <small class="d-block text-muted">{{ p.motivo_reapertura }}</small>
                                        </template>
                                        <span v-else class="text-muted">—</span>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <button v-if="p.historial.length" type="button" class="btn btn-sm btn-link"
                                            @click="abierto = abierto === p.periodo ? '' : p.periodo">
                                            Historial
                                        </button>
                                        <button type="button" class="btn btn-sm"
                                            :class="p.estado === 'cerrado' ? 'btn-outline-warning' : 'btn-outline-primary'"
                                            @click="p.estado === 'cerrado' ? reabrir(p.periodo) : cerrar(p.periodo)">
                                            {{ p.estado === 'cerrado' ? 'Reabrir' : 'Cerrar' }}
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="abierto === p.periodo">
                                    <td colspan="6" class="bg-light-subtle">
                                        <ul class="small mb-0">
                                            <li v-for="(e, i) in p.historial" :key="i">
                                                {{ e.accion === 'cierre' ? 'Cierre' : 'Reapertura' }} · {{ formatFechaHora(e.at) }} ·
                                                {{ e.usuario ?? '—' }}<template v-if="e.motivo"> · {{ e.motivo }}</template>
                                            </li>
                                        </ul>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import httpClient from '@/helpers/http-client';
import { formatFechaHora } from '@/helpers/fecha';
import type { CompraPeriodo } from '@/types/compras';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const periodos = ref<CompraPeriodo[]>([]);
const abierto = ref('');
const error = ref('');

const mensajeDe = (e: any) => e.response?.data?.message ?? 'No se pudo completar la operación.';

const listar = async () => {
    error.value = '';
    try {
        const { data } = await httpClient.get('compra-periodos');
        periodos.value = data.periodos;
    } catch (e) {
        error.value = mensajeDe(e);
    }
};

const enviar = async (url: string, datos: object = {}) => {
    try {
        await httpClient.post(url, datos);
        return true;
    } catch (e) {
        (Swal as TVueSwalInstance).showValidationMessage(mensajeDe(e));
        return false;
    }
};

const cerrar = async (periodo: string) => {
    const { isConfirmed } = await (Swal as TVueSwalInstance).fire({
        title: `Cerrar el periodo ${periodo}`,
        text: 'Sus compras ya no se podrán editar ni anular.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Cerrar periodo',
        cancelButtonText: 'Cancelar',
        showLoaderOnConfirm: true,
        preConfirm: () => enviar(`compra-periodos/${periodo}/cerrar`),
    });
    if (isConfirmed) listar();
};

const reabrir = async (periodo: string) => {
    const { isConfirmed } = await (Swal as TVueSwalInstance).fire({
        title: `Reabrir el periodo ${periodo}`,
        input: 'textarea',
        inputPlaceholder: 'Motivo de la reapertura',
        inputAttributes: { maxlength: '255' },
        showCancelButton: true,
        confirmButtonText: 'Reabrir',
        cancelButtonText: 'Cancelar',
        showLoaderOnConfirm: true,
        preConfirm: (motivo: string) => enviar(`compra-periodos/${periodo}/reabrir`, { motivo }),
    });
    if (isConfirmed) listar();
};

onMounted(listar);
</script>
