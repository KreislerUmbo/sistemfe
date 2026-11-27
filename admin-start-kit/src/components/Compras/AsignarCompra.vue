<template>
    <b-modal :model-value="compraId !== null" :title="compra ? `Asignar ${compra.serie}-${compra.numero}` : 'Asignar compra'"
        hide-footer centered size="lg" @update:model-value="(abierto: boolean) => !abierto && emit('cerrar')">
        <div v-if="!compra && error" class="alert alert-danger py-2 small mb-0">{{ error }}</div>
        <div v-else-if="!compra" class="text-center py-3"><span class="spinner-border spinner-border-sm"></span></div>
        <template v-else>
            <p class="small text-muted mb-2">
                Reparte el total de {{ formatMonto(compra.total, compra.moneda) }}. Sin filas queda «sin asignar».
            </p>
            <div v-if="propuesto" class="alert alert-info py-2 small">
                Destinos de {{ compra.referencia?.serie }}-{{ compra.referencia?.numero }}: revisa los montos y guarda.
            </div>

            <table v-if="filas.length" class="table table-sm align-middle small">
                <thead class="table-light">
                    <tr>
                        <th>Destino</th>
                        <th class="text-end" style="width: 140px">Monto</th>
                        <th style="width: 40px"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(f, i) in filas" :key="clave(f)">
                        <td>{{ f.titulo }}</td>
                        <td>
                            <input v-model="f.monto" type="number" min="0" step="0.01" class="form-control form-control-sm text-end"
                                :aria-label="`Monto de ${f.titulo}`">
                        </td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-link p-0 text-danger" title="Quitar" @click="filas.splice(i, 1)">
                                <i class="las la-times fs-18"></i>
                            </button>
                        </td>
                    </tr>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th class="text-end">
                            Suma
                            <small v-if="diferencia !== 0" class="text-danger ms-2">
                                {{ diferencia > 0 ? 'faltan' : 'sobran' }} {{ formatMonto(Math.abs(diferencia) / 100, compra.moneda) }}
                            </small>
                        </th>
                        <th class="text-end" :class="{ 'text-danger': diferencia !== 0 }">{{ formatMonto(suma / 100, compra.moneda) }}</th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
            <div v-else class="text-muted small fst-italic mb-3">Sin asignar.</div>

            <div class="input-group input-group-sm">
                <select v-model="tipo" class="form-select flex-grow-0 w-auto" aria-label="Buscar en" @change="buscar">
                    <option value="reserva">Reserva</option>
                    <option value="salida">Salida</option>
                </select>
                <input v-model="texto" type="text" class="form-control" aria-label="Buscar destino"
                    :placeholder="tipo === 'reserva' ? 'Código o cliente' : 'Tour o fecha (dd/mm/aaaa)'" @input="buscarConEspera"
                    @focus="buscar">
                <button type="button" class="btn btn-outline-secondary" :disabled="yaEsta('general:')" @click="agregarGeneral">
                    General
                </button>
            </div>
            <div v-if="resultados" class="list-group mt-1 overflow-auto" style="max-height: 200px">
                <button v-for="r in resultados" :key="r.id" type="button" class="list-group-item list-group-item-action py-1 small"
                    :disabled="yaEsta(`${tipo}:${r.id}`)" @click="agregar(r)">
                    {{ r.titulo }}
                </button>
                <div v-if="!resultados.length" class="list-group-item py-1 small text-muted">Sin resultados.</div>
            </div>

            <div v-if="error" class="alert alert-danger py-2 small mt-2 mb-0">{{ error }}</div>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-sm btn-secondary" @click="emit('cerrar')">Cancelar</button>
                <button type="button" class="btn btn-sm btn-success" :disabled="guardando || !puedeGuardar" @click="guardar">
                    <span v-if="guardando" class="spinner-border spinner-border-sm me-1"></span>
                    {{ filas.length ? 'Guardar reparto' : 'Guardar sin asignar' }}
                </button>
            </div>
        </template>
    </b-modal>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import httpClient from '@/helpers/http-client';
import { TIPOS_NOTA, enCentimos, formatMonto } from '@/helpers/compras';
import type { Compra, CompraAsignacion, DestinoAsignacion } from '@/types/compras';

// T-152 — reparto manual del total de una compra. Se guarda siempre completo.
type Fila = Pick<CompraAsignacion, 'destino' | 'reserva_id' | 'salida_operativa_id' | 'titulo'> & { monto: string | number };

const props = defineProps<{ compraId: number | null }>();
const emit = defineEmits<{ cerrar: []; guardado: [compraId: number] }>();

const compra = ref<Compra | null>(null);
const filas = ref<Fila[]>([]);
const propuesto = ref(false);
const tipo = ref<Exclude<DestinoAsignacion, 'general'>>('reserva');
const texto = ref('');
const resultados = ref<{ id: number; titulo: string }[] | null>(null);
const guardando = ref(false);
const error = ref('');

const mensajeDe = (e: any) => e.response?.data?.message ?? 'No se pudo completar la operación.';

const clave = (f: Fila) => `${f.destino}:${f.reserva_id ?? f.salida_operativa_id ?? ''}`;
const yaEsta = (valor: string) => filas.value.some((f) => clave(f) === valor);

const suma = computed(() => filas.value.reduce((total, f) => total + enCentimos(f.monto), 0));
const diferencia = computed(() => enCentimos(compra.value?.total) - suma.value);
const puedeGuardar = computed(
    () => !filas.value.length || (diferencia.value === 0 && filas.value.every((f) => enCentimos(f.monto) > 0)),
);

const agregarGeneral = () => {
    filas.value.push({ destino: 'general', reserva_id: null, salida_operativa_id: null, titulo: 'General', monto: '' });
};

const agregar = (r: { id: number; titulo: string }) => {
    filas.value.push({
        destino: tipo.value,
        reserva_id: tipo.value === 'reserva' ? r.id : null,
        salida_operativa_id: tipo.value === 'salida' ? r.id : null,
        titulo: r.titulo,
        monto: '',
    });
    resultados.value = null;
    texto.value = '';
};

// Solo cuenta la respuesta de la última búsqueda.
let pedido = 0;
const buscar = async () => {
    const mio = ++pedido;
    try {
        const { data } = await httpClient.get('compras/destinos', { params: { tipo: tipo.value, search: texto.value.trim() } });
        if (mio === pedido) resultados.value = data.destinos;
    } catch (e) {
        error.value = mensajeDe(e);
    }
};

let espera: ReturnType<typeof setTimeout>;
const buscarConEspera = () => {
    clearTimeout(espera);
    espera = setTimeout(buscar, 300);
};

// Q7 y Q14: una nota sin reparto propone los destinos de la compra que modifica.
const proponer = async (nota: Compra) => {
    if (!TIPOS_NOTA.includes(nota.tipo_comprobante_codigo) || !nota.referencia) return;
    const { data } = await httpClient.get(`compras/${nota.referencia.id}`);
    const origen: CompraAsignacion[] = data.compra.asignaciones ?? [];
    filas.value = origen.map((a) => ({ ...a, monto: origen.length === 1 ? nota.total : '' }));
    propuesto.value = origen.length > 0;
};

watch(
    () => props.compraId,
    async (id) => {
        compra.value = null;
        filas.value = [];
        propuesto.value = false;
        resultados.value = null;
        texto.value = '';
        error.value = '';
        pedido++;
        if (id === null) return;
        try {
            const { data } = await httpClient.get(`compras/${id}`);
            const cargada: Compra = data.compra;
            filas.value = (cargada.asignaciones ?? []).map((a) => ({ ...a }));
            if (!filas.value.length) await proponer(cargada);
            compra.value = cargada;
        } catch (e) {
            error.value = mensajeDe(e);
        }
    },
    { immediate: true },
);

const guardar = async () => {
    guardando.value = true;
    error.value = '';
    try {
        const id = compra.value!.id;
        await httpClient.put(`compras/${id}/asignaciones`, {
            filas: filas.value.map(({ destino, reserva_id, salida_operativa_id, monto }) => ({
                destino,
                reserva_id,
                salida_operativa_id,
                monto: (enCentimos(monto) / 100).toFixed(2),
            })),
        });
        emit('guardado', id);
    } catch (e) {
        error.value = mensajeDe(e);
    } finally {
        guardando.value = false;
    }
};
</script>
