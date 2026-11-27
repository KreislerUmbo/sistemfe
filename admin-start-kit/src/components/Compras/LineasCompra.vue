<template>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">
                Detallar comprobante <small class="text-muted">(opcional)</small>
                <span v-if="modelValue.length" class="badge bg-secondary-subtle text-secondary ms-1">{{ modelValue.length }}</span>
            </h5>
            <button type="button" class="btn btn-sm btn-outline-secondary" :aria-expanded="abierto" aria-controls="compra-lineas"
                @click="abierto = !abierto">
                <i class="las me-1" :class="abierto ? 'la-angle-up' : 'la-angle-down'"></i>{{ abierto ? 'Ocultar' : 'Mostrar' }}
            </button>
        </div>
        <div v-show="abierto" id="compra-lineas" class="card-body">
            <p v-if="bloqueado" class="small text-muted mb-2">
                Las líneas se registran al crear la compra: para cambiarlas, anúlala y regístrala de nuevo.
            </p>
            <template v-else>
                <p v-if="esNota" class="small text-muted mb-2">
                    Líneas de la compra que modifica. Escribe la cantidad de las que entran en la nota; deja en blanco lo demás.
                </p>
                <div class="row g-2 mb-2">
                    <div v-if="admiteProductos && !esNota" class="col-md-8 position-relative">
                        <input v-model="texto" type="text" class="form-control form-control-sm" autocomplete="off"
                            placeholder="Agregar producto por nombre o código" aria-label="Agregar producto" @input="buscarConPausa"
                            @blur="cerrar" @keydown.enter.prevent="elegirPrimero">
                        <div v-if="sugerencias" class="list-group position-absolute w-100 shadow" style="z-index: 1050; max-height: 260px; overflow-y: auto">
                            <button v-for="p in opciones" :key="p.id" type="button" class="list-group-item list-group-item-action py-1"
                                @mousedown.prevent="agregarProducto(p)">
                                <span class="small">{{ p.title }}</span>
                                <small class="text-muted d-block">{{ p.sku }} · stock {{ p.stock }}</small>
                            </button>
                            <div v-if="!opciones.length" class="list-group-item small text-muted py-1">{{ mensaje }}</div>
                        </div>
                    </div>
                    <div class="col-md-auto">
                        <button type="button" class="btn btn-sm btn-outline-primary" @click="agregarLinea">
                            <i class="las la-plus me-1"></i>Línea sin producto
                        </button>
                    </div>
                </div>
            </template>

            <div v-if="modelValue.length" class="table-responsive">
                <table class="table table-sm mb-0 align-top">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width: 220px">Descripción</th>
                            <th class="text-end" style="width: 100px">Cantidad</th>
                            <th style="min-width: 160px">Unidad</th>
                            <th class="text-end" style="width: 120px">Valor unit. sin IGV</th>
                            <th style="width: 130px">Afectación</th>
                            <th class="text-end">Valor venta</th>
                            <th class="text-end" style="width: 100px">IGV</th>
                            <th class="text-end">Total</th>
                            <th v-if="!bloqueado"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(l, i) in modelValue" :key="i">
                            <td>
                                <input type="text" class="form-control form-control-sm" maxlength="500" :value="l.descripcion"
                                    :disabled="bloqueado" :aria-label="`Descripción de la línea ${i + 1}`"
                                    @input="cambiar(i, 'descripcion', $event)">
                                <small v-if="l.product_id !== null" class="text-muted d-block">
                                    Producto: {{ l.titulo }}{{ l.sku ? ` · ${l.sku}` : '' }}{{
                                        Number(l.factor) !== 1 ? ` · ${l.factor} unidades por cada una` : '' }}
                                </small>
                                <input type="text" class="form-control form-control-sm mt-1" maxlength="50" :value="l.codigo_proveedor"
                                    :disabled="bloqueado" placeholder="Código del proveedor (opcional)"
                                    :aria-label="`Código del proveedor de la línea ${i + 1}`" @input="cambiar(i, 'codigo_proveedor', $event)">
                            </td>
                            <td>
                                <input type="number" min="0" step="any" class="form-control form-control-sm text-end" :value="l.cantidad"
                                    :disabled="bloqueado" :aria-label="`Cantidad de la línea ${i + 1}`"
                                    :class="{ 'is-invalid': excede(l) }" @input="cambiar(i, 'cantidad', $event)">
                                <small v-if="l.maximo !== undefined" class="d-block text-end"
                                    :class="excede(l) ? 'text-danger' : 'text-muted'">máx. {{ l.maximo / Number(l.factor) }}</small>
                            </td>
                            <td>
                                <select class="form-select form-select-sm" :value="l.unidad_medida" :disabled="bloqueado"
                                    :aria-label="`Unidad de la línea ${i + 1}`" @change="cambiar(i, 'unidad_medida', $event)">
                                    <option v-if="!UNITS.some((u) => u.code === l.unidad_medida)" :value="l.unidad_medida">
                                        {{ l.unidad_medida }}
                                    </option>
                                    <option v-for="u in UNITS" :key="u.code" :value="u.code">{{ u.code }} — {{ u.name }}</option>
                                </select>
                            </td>
                            <td>
                                <input type="number" min="0" step="any" class="form-control form-control-sm text-end" :value="l.valor_unitario"
                                    :disabled="bloqueado" :aria-label="`Valor unitario de la línea ${i + 1}`"
                                    @input="cambiar(i, 'valor_unitario', $event)">
                            </td>
                            <td>
                                <select class="form-select form-select-sm" :value="afectacionDe(l, comprobante)" :disabled="bloqueado"
                                    :class="{ 'is-invalid': !bloqueado && !afectacionDe(l, comprobante) }"
                                    :aria-label="`Afectación del IGV de la línea ${i + 1}`" @change="cambiarAfectacion(i, $event)">
                                    <option value="" disabled>— Elegir —</option>
                                    <option v-for="[codigo, nombre] in AFECTACIONES_COMPRA" :key="codigo" :value="codigo">{{ nombre }}</option>
                                </select>
                            </td>
                            <td class="text-end">{{ montosDeLinea(l, comprobante).valor_venta }}</td>
                            <td>
                                <input type="number" min="0" step="0.01" class="form-control form-control-sm text-end"
                                    :value="montosDeLinea(l, comprobante).igv" :disabled="bloqueado || !llevaIgv(l)"
                                    :aria-label="`IGV de la línea ${i + 1}`" @change="cambiar(i, 'igv', $event)">
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
                            <th colspan="5" class="text-end">Suma de las líneas</th>
                            <th class="text-end">{{ suma }}</th>
                            <th colspan="3"></th>
                        </tr>
                    </tfoot>
                </table>
                <small v-if="aviso" class="text-warning">{{ aviso }} Se puede guardar igual.</small>
            </div>
            <p v-else-if="esNota" class="small text-muted mb-0">
                La compra elegida no trae líneas: la nota se registra con los montos de arriba.
            </p>
        </div>
    </div>
</template>

<script setup lang="ts">
// T-154/T-162 — líneas de una compra, como las del comprobante del proveedor. Buscador propio: el
// de ventas muestra el precio de venta y ofrece crear productos, que aquí no aplican.
import { computed, ref, watch } from 'vue';
import httpClient from '@/helpers/http-client';
import { AFECTACIONES_COMPRA, afectacionDe, avisoDeCuadre, enCentimos, montosDeLinea } from '@/helpers/compras';
import type { LineaCompraForm } from '@/types/compras';
import { UNITS, type Product } from '@/types/products';

const props = defineProps<{
    modelValue: LineaCompraForm[];
    comprobante: { base_gravada: string | number; igv: string | number; exonerado: string | number; inafecto: string | number };
    esNota: boolean;
    bloqueado: boolean;
    /** Falso en los tipos que solo llevan líneas sin producto. */
    admiteProductos: boolean;
}>();
const emit = defineEmits<{ (e: 'update:modelValue', lineas: LineaCompraForm[]): void }>();

// Cerrado por defecto; se abre solo cuando llegan líneas (compra guardada o nota de crédito).
const abierto = ref(props.modelValue.length > 0);
watch(() => props.modelValue.length, (ahora, antes) => {
    if (ahora && !antes) abierto.value = true;
});

const suma = computed(() => (props.modelValue
    .reduce((t, l) => t + enCentimos(montosDeLinea(l, props.comprobante).valor_venta), 0) / 100).toFixed(2));
const aviso = computed(() => (props.esNota ? '' : avisoDeCuadre(props.modelValue, props.comprobante)));
const excede = (l: LineaCompraForm) => l.maximo !== undefined && Number(l.cantidad) * Number(l.factor) > l.maximo;
const llevaIgv = (l: LineaCompraForm) => ['', '10'].includes(afectacionDe(l, props.comprobante));

const reemplazar = (indice: number, cambios: Partial<LineaCompraForm>) =>
    emit('update:modelValue', props.modelValue.map((l, i) => (i === indice ? { ...l, ...cambios } : l)));
type CampoEscrito = 'descripcion' | 'codigo_proveedor' | 'unidad_medida' | 'cantidad' | 'valor_unitario' | 'igv';
const cambiar = (indice: number, campo: CampoEscrito, evento: Event) => {
    const valor = (evento.target as HTMLInputElement).value;
    // Borrar el IGV vuelve a la propuesta.
    reemplazar(indice, { [campo]: campo === 'igv' && valor === '' ? null : valor });
};
// Cambiar la afectación descarta el IGV escrito: se vuelve a proponer.
const cambiarAfectacion = (indice: number, evento: Event) =>
    reemplazar(indice, { tipo_afectacion_igv: (evento.target as HTMLSelectElement).value, igv: null });
const quitar = (indice: number) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== indice));

const lineaVacia = (): LineaCompraForm => ({
    product_id: null,
    titulo: '',
    sku: null,
    descripcion: '',
    unidad_medida: 'ZZ',
    codigo_proveedor: '',
    tipo_afectacion_igv: '',
    factor: '1',
    cantidad: 1,
    valor_unitario: '',
    igv: null,
});
const agregarLinea = () => emit('update:modelValue', [...props.modelValue, lineaVacia()]);

const texto = ref('');
const opciones = ref<Product[]>([]);
const sugerencias = ref(false);
const mensaje = ref('Sin resultados.');
let espera: ReturnType<typeof setTimeout> | undefined;
let pedido = 0;

const buscar = async () => {
    const search = texto.value.trim();
    const actual = ++pedido;
    if (search.length < 2) {
        sugerencias.value = false;
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
    sugerencias.value = true;
};
const buscarConPausa = () => {
    clearTimeout(espera);
    espera = setTimeout(buscar, 300);
};
const cerrar = () => {
    clearTimeout(espera);
    pedido++;
    sugerencias.value = false;
};

const agregarProducto = (p: Product) => {
    emit('update:modelValue', [...props.modelValue, {
        ...lineaVacia(),
        product_id: Number(p.id),
        titulo: p.title ?? '',
        sku: p.sku ?? null,
        descripcion: p.title ?? '',
        unidad_medida: p.unidad_medida || 'NIU',
    }]);
    texto.value = '';
    cerrar();
};
const elegirPrimero = () => {
    if (sugerencias.value && opciones.value.length) agregarProducto(opciones.value[0]);
};
</script>
