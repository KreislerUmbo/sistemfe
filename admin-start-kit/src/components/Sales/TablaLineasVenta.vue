<template>
    <div>
        <div class="tabla-lineas">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr class="small text-secondary text-uppercase">
                        <th>Producto o servicio</th>
                        <th style="min-width:118px">Cant.</th>
                        <th class="text-end" style="min-width:132px">Precio s/ IGV</th>
                        <th style="min-width:150px">Afectación IGV</th>
                        <th class="text-end">Importe</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="items.length === 0">
                        <td colspan="6" class="text-center text-muted py-5">
                            <div class="fw-medium text-dark">Aún no hay productos</div>
                            Escribe en el buscador o escanea el código de barras.
                        </td>
                    </tr>
                    <template v-for="(item, i) in items" :key="item.uid ?? i">
                        <tr :ref="(el) => fijarFila(i, el as HTMLElement | null)" :class="{ recien: i === resaltado }">
                            <td>
                                <!-- T-108: la descripción editada REEMPLAZA al nombre (texto plano, nunca v-html) -->
                                <div v-if="tieneDescripcionEditada(item)" class="fw-medium descripcion-editada">{{ item.descripcion_linea }}</div>
                                <div v-else class="fw-medium">{{ item.product.title }}</div>
                                <div v-if="tieneDescripcionEditada(item)" class="small text-muted">
                                    <span class="badge bg-secondary-subtle text-secondary">Descripción editada</span>
                                    catálogo: {{ item.product.title }}
                                </div>
                                <div class="small text-muted d-flex flex-wrap gap-1 align-items-center">
                                    <span>{{ item.product.sku }}</span>
                                    <span v-if="esServicio(item)" class="badge bg-success-subtle text-success">Servicio</span>
                                    <span v-if="sinStock(item)" class="badge bg-danger">Solo hay {{ disponible(item) }}</span>
                                    <span v-else-if="pocoStock(item)" class="badge bg-warning-subtle text-warning">Quedan {{ disponible(item) }}</span>
                                    <span v-if="Number(item.discount_percent) > 0" class="badge bg-success-subtle text-success">−{{ item.discount_percent }}%</span>
                                    <button type="button" class="btn btn-link btn-sm p-0" @click="editarDescripcion(i)">Editar descripción</button>
                                    <button v-if="item.product.is_discount == 2" type="button" class="btn btn-link btn-sm p-0"
                                        @click="descuentoAbierto = descuentoAbierto === i ? null : i">
                                        {{ Number(item.discount_percent) > 0 ? 'Cambiar descuento' : 'Descuento' }}
                                    </button>
                                </div>
                            </td>
                            <td>
                                <div class="input-group input-group-sm flex-nowrap" style="width:118px">
                                    <button class="btn btn-outline-secondary" type="button" aria-label="Menos"
                                        @click="sumar(item, i, -1)">−</button>
                                    <input type="number" class="form-control text-center px-1" :value="item.quantity"
                                        :step="admiteDecimales(item.unidad_medida) ? 0.001 : 1" min="0"
                                        aria-label="Cantidad" @change="cambiarCantidad(item, i, $event)" />
                                    <button class="btn btn-outline-secondary" type="button" aria-label="Más"
                                        @click="sumar(item, i, 1)">+</button>
                                </div>
                                <div v-if="admiteDecimales(item.unidad_medida)" class="small text-muted">{{ item.unidad_medida }} · admite decimales</div>
                            </td>
                            <td class="text-end">
                                <div class="input-group input-group-sm flex-nowrap justify-content-end">
                                    <input type="number" step="0.01" min="0" class="form-control text-end precio"
                                        :value="item.price_base" aria-label="Precio sin IGV" :readonly="!precioEditable(item)"
                                        @input="marcarPrecioManual(item)" @change="cambiarPrecio(item, i, $event)" />
                                    <button v-if="precioEditable(item) && tienePreciosAlternos(item)" class="btn btn-outline-secondary" type="button"
                                        title="Elegir otro precio" aria-label="Elegir otro precio"
                                        @click="preciosAbierto = preciosAbierto === i ? null : i">▾</button>
                                </div>
                                <div class="small" :class="item.precio_manual ? 'text-warning fw-semibold' : 'text-muted'">
                                    {{ item.precio_manual ? (precioEditable(item) ? 'Precio manual' : 'Precio de la cotización') : nombrePrecio(item) }}
                                </div>
                            </td>
                            <td>
                                <select class="form-select form-select-sm afe" :class="{ cambiada: afectacionCambiada(item) }"
                                    :value="item.tip_afe_igv" aria-label="Afectación IGV" @change="cambiarAfectacion(item, i, $event)">
                                    <option v-for="a in AFECTACIONES" :key="a.codigo" :value="a.codigo">{{ a.nombre }}</option>
                                </select>
                                <div v-if="afectacionCambiada(item)" class="small text-warning mt-1">
                                    Por defecto: {{ nombreAfectacion(afeDefecto(item.product)) }} ·
                                    <button type="button" class="btn btn-link btn-sm p-0 align-baseline" @click="restaurar(item, i)">restaurar</button>
                                </div>
                            </td>
                            <td class="text-end fw-semibold text-nowrap">{{ simbolo }} {{ importe(item).toFixed(2) }}</td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-link text-muted fs-5 py-0" aria-label="Quitar"
                                    @click="emit('quitar', i)">×</button>
                            </td>
                        </tr>
                        <tr v-if="preciosAbierto === i" class="table-light">
                            <td colspan="6">
                                <span class="small text-muted me-2">Precio para esta línea:</span>
                                <button v-for="op in opcionesPrecio(item)" :key="op.nombre" type="button"
                                    class="btn btn-sm me-2" :class="op.activo ? 'btn-success' : 'btn-outline-secondary'"
                                    @click="elegirPrecio(i, op.nombre)">
                                    {{ op.nombre }} <b>{{ simbolo }} {{ op.valor.toFixed(2) }}</b>
                                </button>
                                <span class="small text-muted">o escribe otro en la casilla del precio.</span>
                            </td>
                        </tr>
                        <tr v-if="descuentoAbierto === i" class="table-light">
                            <td colspan="6">
                                <label class="small me-2">Descuento de la línea</label>
                                <input type="number" class="form-control form-control-sm d-inline-block text-end" style="width:80px"
                                    min="0" :max="item.product.max_discount || 0" :value="item.discount_percent"
                                    @change="cambiarDescuento(item, i, $event)" /> %
                                <span class="small text-muted ms-2">máximo permitido para este producto: {{ item.product.max_discount || 0 }}%</span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <ModalDescripcionLinea v-model="modalDescripcion" :titulo-catalogo="lineaDescripcion?.product.title ?? ''"
            :descripcion-actual="lineaDescripcion?.descripcion_linea" @aplicar="aplicarDescripcion" />
        <div v-if="items.length" class="d-flex justify-content-between small text-muted px-3 py-2 border-top">
            <span>{{ items.length }} línea{{ items.length > 1 ? 's' : '' }} · {{ unidades }} unidad{{ unidades === 1 ? '' : 'es' }}</span>
            <span v-if="items.length > 8">Desplázate dentro de la lista para ver todo</span>
        </div>
    </div>
</template>

<script setup lang="ts">
// Lista de líneas de la venta (T-106), con desplazamiento interno y encabezado fijo. Reutilizable en edit.vue (T-110).
// Las líneas (`items`) son los objetos reactivos de la pantalla: este componente cambia cantidad, precio,
// descuento y afectación sobre ellos y avisa con `cambio` para que la pantalla recalcule (updateItem).
// No calcula impuestos: eso sigue en register.vue.
import { computed, ref, watch } from 'vue';
import type { SaleDetail } from '@/types/sales';
import type { Product } from '@/types/products';
import ModalDescripcionLinea from '@/components/Sales/ModalDescripcionLinea.vue';
import { admiteDecimales, cantidadEnVenta, controlaStock as productoControlaStock, normalizarCantidad, precioCatalogo } from '@/helpers/ventaRetail';

export type LineaVenta = SaleDetail & { uid?: number; precio_manual?: boolean };

const props = defineProps<{
    items: LineaVenta[];
    moneda: string;
    /** Afectación IGV que corresponde al producto según destino/exportación (resolverTipAfeIgv). */
    afeDefecto: (producto: Product) => string;
    /** Cliente elegido: si es mayorista, sus líneas llevan el precio mayorista (T-115). */
    cliente?: { es_mayorista?: boolean } | null;
    /** Permiso cambiar_precio_venta (T-109): sin él la casilla es de solo lectura y no hay ▾. */
    puedeCambiarPrecio?: boolean;
    /** Índice de la línea recién agregada: se resalta y se lleva a la vista. */
    resaltado?: number | null;
    /** Edición (T-110): unidades por producto que la venta ya tenía descontadas del stock; se suman a lo disponible. */
    yaDescontado?: Record<string, number>;
}>();

const emit = defineEmits<{
    (e: 'cambio', indice: number): void;
    (e: 'quitar', indice: number): void;
    (e: 'elegir-precio', indice: number, nombre: 'General' | 'Mayorista'): void;
}>();

const AFECTACIONES = [
    { codigo: '10', nombre: 'Gravado (10)' },
    { codigo: '20', nombre: 'Exonerado (20)' },
    { codigo: '30', nombre: 'Inafecto (30)' },
    { codigo: '17', nombre: 'IVAP (17)' },
    { codigo: '40', nombre: 'Exportación (40)' },
    { codigo: '11', nombre: 'Gratuito (11)' },
];
const nombreAfectacion = (codigo: string) => AFECTACIONES.find((a) => a.codigo === codigo)?.nombre ?? codigo;

const simbolo = computed(() => (props.moneda === 'USD' ? 'US$' : 'S/'));
const unidades = computed(() => Number(props.items.reduce((s, it) => s + (Number(it.quantity) || 0), 0).toFixed(3)));

const preciosAbierto = ref<number | null>(null);
const descuentoAbierto = ref<number | null>(null);
watch(() => props.items.length, () => { preciosAbierto.value = null; descuentoAbierto.value = null; });

const esServicio = (it: LineaVenta) => it.product.tipo_bien_servicio === 'SERVICIO';
const controlaStock = (it: LineaVenta) => productoControlaStock(it.product);
// Se compara la suma de TODAS las líneas del producto con su stock (T-112): con 3 + 3 y stock 5 se marcan las dos filas.
const disponible = (it: LineaVenta) => Number(it.product.stock) + (props.yaDescontado?.[String(it.product.id)] ?? 0);
const sinStock = (it: LineaVenta) => controlaStock(it) && cantidadEnVenta(props.items, it.product.id) > disponible(it);
const pocoStock = (it: LineaVenta) => controlaStock(it) && disponible(it) <= 5;

const afectacionCambiada = (it: LineaVenta) => it.tip_afe_igv !== props.afeDefecto(it.product);
const importe = (it: LineaVenta) => (Number(it.subtotal) || 0) + (Number(it.total_impuestos) || 0);

// T-115: el precio depende de la suma de TODAS las líneas del producto (precio mayorista por cantidad).
const precioBruto = (it: LineaVenta) => precioCatalogo(it.product, props.cliente, cantidadEnVenta(props.items, it.product.id));
const nombrePrecio = (it: LineaVenta) => precioBruto(it).motivo;
// Con el permiso, siempre. Sin él, solo los productos «a precio variable» (catálogo en 0): el backend
// no compara contra nada en ese caso (PrecioVentaValidator).
const precioEditable = (it: LineaVenta) => !!props.puedeCambiarPrecio || precioBruto(it).valor === 0;
const tienePreciosAlternos = (it: LineaVenta) =>
    Number(it.product.price_company) > 0 && Number(it.product.price_company) !== Number(it.product.price_general);
const opcionesPrecio = (it: LineaVenta) => {
    const lista: { nombre: 'General' | 'Mayorista'; valor: number }[] = [{ nombre: 'General', valor: Number(it.product.price_general) || 0 }];
    if (Number(it.product.price_company) > 0) lista.push({ nombre: 'Mayorista', valor: Number(it.product.price_company) });
    return lista.map((o) => ({ ...o, activo: !it.precio_manual && o.nombre === precioBruto(it).nombre }));
};
const elegirPrecio = (i: number, nombre: 'General' | 'Mayorista') => {
    emit('elegir-precio', i, nombre);
    preciosAbierto.value = null;
};

const sumar = (it: LineaVenta, i: number, delta: number) => {
    it.quantity = normalizarCantidad(Number(it.quantity) + delta, it.unidad_medida);
    emit('cambio', i);
};
const cambiarCantidad = (it: LineaVenta, i: number, e: Event) => {
    it.quantity = normalizarCantidad((e.target as HTMLInputElement).value, it.unidad_medida);
    (e.target as HTMLInputElement).value = String(it.quantity);
    emit('cambio', i);
};
// T-115: escribir en la casilla ya es precio a mano, aunque el número sea el mismo (@change no se dispara si no cambia).
const marcarPrecioManual = (it: LineaVenta) => { it.precio_manual = true; };
const cambiarPrecio = (it: LineaVenta, i: number, e: Event) => {
    it.price_base = Math.max(0, Number((e.target as HTMLInputElement).value) || 0);
    it.precio_manual = true;
    emit('cambio', i);
};
const cambiarAfectacion = (it: LineaVenta, i: number, e: Event) => {
    it.tip_afe_igv = (e.target as HTMLSelectElement).value;
    emit('cambio', i);
};
const restaurar = (it: LineaVenta, i: number) => {
    it.tip_afe_igv = props.afeDefecto(it.product);
    emit('cambio', i);
};
const cambiarDescuento = (it: LineaVenta, i: number, e: Event) => {
    const max = Number(it.product.max_discount) || 0;
    it.discount_percent = Math.min(max, Math.max(0, Number((e.target as HTMLInputElement).value) || 0));
    emit('cambio', i);
};

// T-108: «Editar descripción». El texto vive en la línea (descripcion_linea) y viaja en el payload de store();
// no cambia importes, así que no hace falta recalcular.
const lineaEnEdicion = ref<number | null>(null);
const modalDescripcion = ref(false);
const tieneDescripcionEditada = (it: LineaVenta) => !!it.descripcion_linea?.trim();
const editarDescripcion = (i: number) => {
    lineaEnEdicion.value = i;
    modalDescripcion.value = true;
};
const lineaDescripcion = computed(() => (lineaEnEdicion.value === null ? undefined : props.items[lineaEnEdicion.value]));
const aplicarDescripcion = (descripcion: string | null) => {
    if (lineaDescripcion.value) lineaDescripcion.value.descripcion_linea = descripcion;
};

// Lleva la línea resaltada a la vista dentro de la lista con desplazamiento.
const filas: Record<number, HTMLElement | null> = {};
const fijarFila = (i: number, el: HTMLElement | null) => { filas[i] = el; };
watch(() => props.resaltado, (i) => {
    if (i == null) return;
    setTimeout(() => filas[i]?.scrollIntoView({ block: 'nearest' }), 0);
});
</script>

<style scoped>
.tabla-lineas { max-height: max(300px, calc(100vh - 380px)); overflow: auto; }
.tabla-lineas thead th { position: sticky; top: 0; z-index: 2; background: var(--bs-body-bg, #fff); }
.precio { max-width: 92px; }
.descripcion-editada { white-space: pre-line; overflow-wrap: anywhere; }
.afe { min-width: 140px; font-size: .8rem; }
.afe.cambiada { border-color: var(--bs-warning); background: var(--bs-warning-bg-subtle); font-weight: 600; }
tr.recien td { background: var(--bs-success-bg-subtle); }
@media (max-width: 920px) { .tabla-lineas { max-height: 60vh; } }
</style>
