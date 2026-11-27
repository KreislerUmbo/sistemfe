<template>
    <div class="card">
        <div class="card-header"><h5 class="card-title mb-0">Productos <small class="text-muted">(opcional)</small></h5></div>
        <div class="card-body">
            <p v-if="bloqueado" class="small text-muted mb-2">
                Los productos ya movieron el stock: para cambiarlos, anula la compra y regístrala de nuevo.
            </p>
            <p v-else-if="esNota" class="small text-muted mb-2">
                Productos de la compra que modifica. Escribe la cantidad que se devuelve al proveedor; deja en blanco lo demás.
            </p>
            <div v-else class="position-relative mb-2">
                <input v-model="texto" type="text" class="form-control form-control-sm" autocomplete="off"
                    placeholder="Agregar producto por nombre o código" aria-label="Agregar producto" @input="buscarConPausa"
                    @blur="cerrar" @keydown.enter.prevent="elegirPrimero">
                <div v-if="abierto" class="list-group position-absolute w-100 shadow" style="z-index: 1050; max-height: 260px; overflow-y: auto">
                    <button v-for="p in opciones" :key="p.id" type="button" class="list-group-item list-group-item-action py-1"
                        @mousedown.prevent="agregar(p)">
                        <span class="small">{{ p.title }}</span>
                        <small class="text-muted d-block">{{ p.sku }} · stock {{ p.stock }}</small>
                    </button>
                    <div v-if="!opciones.length" class="list-group-item small text-muted py-1">{{ mensaje }}</div>
                </div>
            </div>

            <div v-if="modelValue.length" class="table-responsive">
                <table class="table table-sm table-centered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Producto</th>
                            <th class="text-end" style="width: 120px">Cantidad</th>
                            <th class="text-end" style="width: 140px">Valor unit. sin IGV</th>
                            <th class="text-end">Valor venta</th>
                            <th class="text-end" style="width: 110px">IGV</th>
                            <th class="text-end">Total</th>
                            <th v-if="!bloqueado"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(l, i) in modelValue" :key="i">
                            <td>
                                {{ l.titulo }}
                                <small class="text-muted d-block">
                                    {{ l.sku }}{{ Number(l.factor) !== 1 ? ` · ${l.factor} unidades por cada una` : '' }}
                                </small>
                            </td>
                            <td>
                                <input type="number" min="0" step="any" class="form-control form-control-sm text-end" :value="l.cantidad"
                                    :disabled="bloqueado" :aria-label="`Cantidad de ${l.titulo}`"
                                    :class="{ 'is-invalid': excede(l) }" @input="cambiar(i, 'cantidad', $event)">
                                <small v-if="l.maximo !== undefined" class="d-block text-end"
                                    :class="excede(l) ? 'text-danger' : 'text-muted'">máx. {{ l.maximo / Number(l.factor) }}</small>
                            </td>
                            <td>
                                <input type="number" min="0" step="any" class="form-control form-control-sm text-end" :value="l.valor_unitario"
                                    :disabled="bloqueado" :aria-label="`Valor unitario de ${l.titulo}`"
                                    @input="cambiar(i, 'valor_unitario', $event)">
                            </td>
                            <td class="text-end">{{ montosDeLinea(l, comprobante).valor_venta }}</td>
                            <td>
                                <input type="number" min="0" step="0.01" class="form-control form-control-sm text-end"
                                    :value="l.igv ?? montosDeLinea(l, comprobante).igv" :disabled="bloqueado"
                                    :aria-label="`IGV de ${l.titulo}`" @change="cambiar(i, 'igv', $event)">
                            </td>
                            <td class="text-end">{{ montosDeLinea(l, comprobante).total }}</td>
                            <td v-if="!bloqueado" class="text-end">
                                <button type="button" class="btn btn-sm btn-link p-1" title="Quitar" @click="quitar(i)">
                                    <i class="las la-trash text-danger fs-18"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="3" class="text-end">Suma de los productos</th>
                            <th class="text-end">{{ suma }}</th>
                            <th colspan="3"></th>
                        </tr>
                    </tfoot>
                </table>
                <small v-if="enCentimos(suma) !== enCentimos(base)" class="text-warning">
                    Los productos suman {{ suma }} y la base del comprobante es {{ base }}. Se puede guardar igual.
                </small>
            </div>
            <p v-else-if="esNota" class="small text-muted mb-0">La compra elegida no tiene productos por devolver.</p>
        </div>
    </div>
</template>

<script setup lang="ts">
// T-154 — líneas de producto de una compra. Buscador propio: el de ventas muestra el precio de
// venta y ofrece crear productos, que aquí no aplican.
import { computed, ref } from 'vue';
import httpClient from '@/helpers/http-client';
import { enCentimos, montosDeLinea } from '@/helpers/compras';
import type { LineaCompraForm } from '@/types/compras';
import type { Product } from '@/types/products';

const props = defineProps<{
    modelValue: LineaCompraForm[];
    comprobante: { base_gravada: string | number; igv: string | number; exonerado: string | number; inafecto: string | number };
    esNota: boolean;
    bloqueado: boolean;
}>();
const emit = defineEmits<{ (e: 'update:modelValue', lineas: LineaCompraForm[]): void }>();

const sumar = (valores: (string | number)[]) => (valores.reduce<number>((t, v) => t + enCentimos(v), 0) / 100).toFixed(2);
const suma = computed(() => sumar(props.modelValue.map((l) => montosDeLinea(l, props.comprobante).valor_venta)));
const base = computed(() => sumar([props.comprobante.base_gravada, props.comprobante.exonerado, props.comprobante.inafecto]));
const excede = (l: LineaCompraForm) => l.maximo !== undefined && Number(l.cantidad) * Number(l.factor) > l.maximo;

const cambiar = (indice: number, campo: 'cantidad' | 'valor_unitario' | 'igv', evento: Event) => {
    const valor = (evento.target as HTMLInputElement).value;
    // Borrar el IGV vuelve a la propuesta.
    const nuevo = campo === 'igv' && valor === '' ? null : valor;
    emit('update:modelValue', props.modelValue.map((l, i) => (i === indice ? { ...l, [campo]: nuevo } : l)));
};
const quitar = (indice: number) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== indice));

const texto = ref('');
const opciones = ref<Product[]>([]);
const abierto = ref(false);
const mensaje = ref('Sin resultados.');
let espera: ReturnType<typeof setTimeout> | undefined;
let pedido = 0;

const buscar = async () => {
    const search = texto.value.trim();
    const actual = ++pedido;
    if (search.length < 2) {
        abierto.value = false;
        return;
    }
    try {
        const { data } = await httpClient.get('products', { params: { search, take: 10 } });
        // Una respuesta vieja no pisa a la nueva.
        if (actual !== pedido) return;
        opciones.value = data.products.data;
        mensaje.value = 'Sin resultados.';
    } catch (e: any) {
        opciones.value = [];
        mensaje.value = e.response?.data?.message ?? 'No se pudo buscar.';
    }
    abierto.value = true;
};
const buscarConPausa = () => {
    clearTimeout(espera);
    espera = setTimeout(buscar, 300);
};
const cerrar = () => {
    clearTimeout(espera);
    pedido++;
    abierto.value = false;
};

const agregar = (p: Product) => {
    emit('update:modelValue', [...props.modelValue, {
        product_id: Number(p.id),
        titulo: p.title ?? '',
        sku: p.sku ?? null,
        factor: '1',
        cantidad: 1,
        valor_unitario: '',
        igv: null,
    }]);
    texto.value = '';
    cerrar();
};
const elegirPrimero = () => {
    if (abierto.value && opciones.value.length) agregar(opciones.value[0]);
};
</script>
