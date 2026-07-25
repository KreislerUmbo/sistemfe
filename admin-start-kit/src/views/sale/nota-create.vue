<template>
    <DefaultLayout>
        <div class="header-row d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-file-invoice me-2 text-primary"></i>
                    Emitir Nota de Crédito / Débito
                </h5>
                <small class="text-muted" v-if="sale">
                    Sobre {{ sale.serie }}-{{ String(sale.correlativo).padStart(8, '0') }} — {{ sale.client?.full_name }}
                </small>
            </div>
            <router-link :to="volverRoute" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Volver
            </router-link>
        </div>

        <div v-if="loading" class="text-center py-5">
            <span class="spinner-border text-primary"></span>
        </div>

        <!-- ═══════════════════ BÚSQUEDA DE VENTA (entrada sin :id) ═══════════════════ -->
        <div v-else-if="!sale && modoBusqueda" class="card border-0 shadow-sm">
            <div class="card-body position-relative">
                <label class="form-label fw-semibold">Buscar venta</label>
                <input type="text" class="form-control" v-model="buscarText"
                    placeholder="Buscar por cliente, DNI/RUC, o serie-correlativo..."
                    @input="onBuscarVentaInput" autocomplete="off">
                <div v-if="buscarSugerencias.length > 0" class="list-group mt-1"
                    style="max-height:260px;overflow-y:auto;box-shadow:0 4px 8px rgba(0,0,0,.1)">
                    <button type="button" class="list-group-item list-group-item-action"
                        v-for="s in buscarSugerencias" :key="s.id" @mousedown.prevent="seleccionarVenta(s)">
                        <div class="d-flex justify-content-between">
                            <span>{{ s.serie }}-{{ String(s.correlativo).padStart(8, '0') }} — {{ s.client?.full_name }}</span>
                            <small class="text-muted">{{ s.currency === 'USD' ? 'US$' : 'S/' }} {{ Number(s.total).toFixed(2) }}</small>
                        </div>
                    </button>
                </div>
                <small v-else-if="buscarText.trim().length >= 2 && !buscando" class="text-muted d-block mt-1">
                    Sin resultados. Solo se muestran ventas ya aceptadas por SUNAT.
                </small>
            </div>
        </div>

        <div v-else-if="!sale" class="alert alert-danger">
            No se pudo cargar la venta.
        </div>

        <!-- ═══════════════════ NOTAS YA EMITIDAS SOBRE ESTA VENTA ═══════════════════ -->
        <div v-if="sale && sale.notes && sale.notes.length" class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h6 class="fw-semibold mb-2">
                    <i class="fas fa-history me-1 text-secondary"></i>
                    Notas ya emitidas sobre esta venta
                </h6>
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Tipo</th>
                            <th>N° operación</th>
                            <th>Motivo</th>
                            <th>Alcance</th>
                            <th class="text-end">Total</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="n in sale.notes" :key="n.id">
                            <td>{{ n.tipo_doc === '07' ? 'NC' : 'ND' }}</td>
                            <td>{{ n.n_operacion ?? (n.serie + '-pendiente') }}</td>
                            <td>{{ n.cod_motivo }} - {{ n.des_motivo }}</td>
                            <td>{{ n.tipo_afectacion === 'total' ? 'Total' : 'Parcial' }}</td>
                            <td class="text-end">{{ moneda }} {{ Number(n.mto_imp_venta).toFixed(2) }}</td>
                            <td>
                                <span class="badge" :class="{
                                    'bg-success': n.status === 'aceptado',
                                    'bg-danger': n.status === 'rechazado',
                                    'bg-secondary': n.status === 'pendiente' || n.status === 'enviando',
                                }">{{ n.status }}</span>
                            </td>
                            <td>
                                <button v-if="n.status === 'aceptado'" type="button"
                                    class="btn btn-sm btn-outline-secondary py-0 px-2" @click="imprimirNota(n.id)"
                                    title="Imprimir">
                                    <i class="fas fa-print"></i>
                                </button>
                                <button v-else-if="n.status === 'pendiente'" type="button"
                                    class="btn btn-sm btn-primary py-0 px-2" :disabled="enviandoDesdeTabla === n.id"
                                    @click="enviarSunatDesdeTabla(n.id)" title="Enviar a SUNAT">
                                    <span v-if="enviandoDesdeTabla === n.id" class="spinner-border spinner-border-sm"></span>
                                    <span v-else>Enviar</span>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ═══════════════════ FORMULARIO (antes de crear la nota) ═══════════════════ -->
        <div v-else-if="!nota" class="card border-0 shadow-sm">
            <div class="card-body">

                <!-- Tipo de nota -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">Tipo de documento</label>
                    <div class="btn-group d-block">
                        <button type="button" class="btn btn-sm"
                            :class="tipoDoc === '07' ? 'btn-primary' : 'btn-outline-primary'"
                            @click="cambiarTipoDoc('07')">
                            Nota de Crédito
                        </button>
                        <button type="button" class="btn btn-sm ms-2"
                            :class="tipoDoc === '08' ? 'btn-primary' : 'btn-outline-primary'"
                            @click="cambiarTipoDoc('08')">
                            Nota de Débito
                        </button>
                    </div>
                </div>

                <!-- Motivo -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">Motivo</label>
                    <select class="form-select" v-model="codMotivo" @change="onMotivoChange">
                        <option value="" disabled>Seleccione un motivo</option>
                        <option v-for="m in motivosDisponibles" :key="m.codigo" :value="m.codigo">
                            {{ m.codigo }} - {{ m.label }}
                        </option>
                    </select>
                </div>

                <!-- Descripción del motivo -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">
                        Descripción
                        <span class="text-muted small" v-if="!requiereDescripcionDetallada">(opcional)</span>
                    </label>
                    <textarea class="form-control" rows="2" v-model="desMotivo"
                        :placeholder="motivoSeleccionado?.label ?? ''"></textarea>
                </div>

                <!-- Descuento global (NC04) — sin tabla de ítems, un solo monto ─── -->
                <div class="mb-3" v-if="esDescuentoGlobal">
                    <label class="form-label fw-semibold">Monto del descuento global ({{ moneda }})</label>
                    <input type="number" class="form-control" min="0.01" step="0.01" v-model.number="descuentoGlobal">
                    <small class="text-muted">
                        Se aplica sobre el total de la venta, prorrateado entre gravadas/exoneradas/inafectas.
                    </small>
                </div>

                <!-- Total / Parcial -->
                <div class="mb-3" v-if="motivoSeleccionado && !esDescuentoGlobal">
                    <label class="form-label fw-semibold">Alcance</label>
                    <div class="btn-group d-block">
                        <button type="button" class="btn btn-sm" :disabled="!motivoSeleccionado.permite_total"
                            :class="tipoAfectacion === 'total' ? 'btn-primary' : 'btn-outline-primary'"
                            @click="tipoAfectacion = 'total'">
                            Total
                        </button>
                        <button type="button" class="btn btn-sm ms-2" :disabled="!motivoSeleccionado.permite_parcial"
                            :class="tipoAfectacion === 'parcial' ? 'btn-primary' : 'btn-outline-primary'"
                            @click="tipoAfectacion = 'parcial'">
                            Parcial (por ítem)
                        </button>
                    </div>
                </div>

                <!-- Líneas parciales -->
                <div v-if="tipoAfectacion === 'parcial' && !esDescuentoGlobal" class="mb-3">
                    <label class="form-label fw-semibold">Ítems de la venta</label>
                    <table class="table table-sm table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width:36px;"></th>
                                <th>Producto</th>
                                <th style="width:100px;">Cant. vendida</th>
                                <th style="width:130px;" v-if="esModoMonto">Monto a {{ tipoDoc === '07' ? 'acreditar' : 'debitar' }} ({{ moneda }})</th>
                                <th style="width:130px;" v-else>Cant. a {{ tipoDoc === '07' ? 'acreditar' : 'debitar' }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="linea in lineasSeleccionables" :key="linea.detalle.id">
                                <td>
                                    <input type="checkbox" v-model="linea.seleccionado">
                                </td>
                                <td>{{ linea.detalle.product?.title }}</td>
                                <td>{{ linea.detalle.quantity }}</td>
                                <td v-if="esModoMonto">
                                    <input type="number" class="form-control form-control-sm" min="0" step="0.01"
                                        v-model.number="linea.monto" :disabled="!linea.seleccionado">
                                </td>
                                <td v-else>
                                    <input type="number" class="form-control form-control-sm" min="0" step="0.0001"
                                        :max="tipoDoc === '07' ? linea.detalle.quantity : undefined"
                                        v-model.number="linea.cantidad" :disabled="!linea.seleccionado">
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Conceptos libres — solo Nota de Débito -->
                    <div v-if="tipoDoc === '08'">
                        <label class="form-label fw-semibold">Conceptos libres (sin ítem de venta asociado)</label>
                        <div v-for="(c, idx) in conceptosLibres" :key="idx"
                            class="d-flex gap-2 align-items-center mb-2">
                            <select class="form-select form-select-sm" v-model.number="c.product_id" style="max-width:260px;">
                                <option value="" disabled>Producto especial</option>
                                <option v-for="p in productosEspeciales" :key="p.id" :value="p.id">
                                    {{ p.title }}
                                </option>
                            </select>
                            <input type="number" class="form-control form-control-sm" placeholder="Cantidad"
                                min="0.01" step="0.01" v-model.number="c.quantity" style="max-width:120px;">
                            <input type="number" class="form-control form-control-sm" placeholder="Monto (S/)"
                                min="0.01" step="0.01" v-model.number="c.subtotal" style="max-width:140px;">
                            <button type="button" class="btn btn-sm btn-outline-danger" @click="conceptosLibres.splice(idx, 1)">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                            @click="conceptosLibres.push({ product_id: '', quantity: 1, subtotal: 0 })">
                            <i class="fas fa-plus me-1"></i> Agregar concepto
                        </button>
                    </div>
                </div>

                <!-- Reponer stock -->
                <div class="form-check mb-3" v-if="tipoDoc === '07' && !esDescuentoGlobal">
                    <input class="form-check-input" type="checkbox" id="reponerStock" v-model="reponerStock">
                    <label class="form-check-label" for="reponerStock">
                        Reponer stock de los productos acreditados
                    </label>
                </div>

                <!-- Totales -->
                <div class="border rounded p-3 bg-light mb-3" v-if="totalesPreview">
                    <div class="d-flex justify-content-between">
                        <span>Valor venta</span>
                        <span>{{ moneda }} {{ Number(totalesPreview.valor_venta ?? 0).toFixed(2) }}</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>IGV</span>
                        <span>{{ moneda }} {{ Number(totalesPreview.mto_igv ?? 0).toFixed(2) }}</span>
                    </div>
                    <div class="d-flex justify-content-between" v-if="Number(totalesPreview.icbper) > 0">
                        <span>ICBPER</span>
                        <span>{{ moneda }} {{ Number(totalesPreview.icbper ?? 0).toFixed(2) }}</span>
                    </div>
                    <div class="d-flex justify-content-between fw-bold border-top pt-1 mt-1">
                        <span>Total</span>
                        <span>{{ moneda }} {{ Number(totalesPreview.mto_imp_venta ?? 0).toFixed(2) }}</span>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-primary" :disabled="!puedeCalcular || loadingPreview"
                        @click="calcularPreview">
                        <span v-if="loadingPreview" class="spinner-border spinner-border-sm me-1"></span>
                        Calcular totales
                    </button>
                    <button type="button" class="btn btn-primary" :disabled="!puedeCrear || loadingCrear"
                        @click="crearNota">
                        <span v-if="loadingCrear" class="spinner-border spinner-border-sm me-1"></span>
                        Crear nota
                    </button>
                </div>
            </div>
        </div>

        <!-- ═══════════════════ NOTA YA CREADA — revisar / enviar / imprimir ═══════════════════ -->
        <div v-else class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <h6 class="fw-bold mb-1">
                            {{ nota.tipo_doc === '07' ? 'Nota de Crédito' : 'Nota de Débito' }}
                            <span v-if="nota.n_operacion">{{ nota.n_operacion }}</span>
                            <span v-else class="text-muted">(pendiente de envío)</span>
                        </h6>
                        <span class="badge" :class="estadoBadgeClass">{{ estadoLabel }}</span>
                    </div>
                </div>

                <div class="border rounded p-3 bg-light mb-3">
                    <div class="d-flex justify-content-between fw-bold">
                        <span>Total</span>
                        <span>{{ moneda }} {{ Number(nota.mto_imp_venta).toFixed(2) }}</span>
                    </div>
                </div>

                <div v-if="nota.status === 'rechazado'" class="alert alert-danger">
                    {{ nota.sunat_error_message ?? 'La nota fue rechazada por SUNAT.' }}
                    <div class="mt-2">
                        <button type="button" class="btn btn-sm btn-outline-danger" @click="reiniciarFormulario">
                            Crear una nota nueva para corregir
                        </button>
                    </div>
                </div>

                <div v-else-if="nota.status === 'aceptado'" class="alert alert-success">
                    La nota fue aceptada por SUNAT.
                    <div class="mt-2">
                        <button type="button" class="btn btn-sm btn-success" @click="imprimirNota(nota!.id)">
                            <i class="fas fa-print me-1"></i> Imprimir
                        </button>
                    </div>
                </div>

                <div v-else class="d-flex gap-2">
                    <button type="button" class="btn btn-primary" :disabled="loadingEnviar" @click="enviarSunat">
                        <span v-if="loadingEnviar" class="spinner-border spinner-border-sm me-1"></span>
                        Enviar a SUNAT
                    </button>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import { computed, onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import Swal from "sweetalert2/dist/sweetalert2.js";
import httpClient from "@/helpers/http-client";
import type { Sale, SaleDetail } from "@/types/sales";
import type { Note, NoteConfig, NoteMotivo, NotaItemRequest, SaleSearchResult } from "@/types/notes";
import type { Product } from "@/types/products";
import { imprimirNota } from "@/composables/usePrintComprobante";

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const route = useRoute();
const saleId = ref<string | undefined>(route.params.id as string | undefined);

// Entrada sin :id (botón "Nueva Nota" en /nota/list) — hay que buscar la
// venta antes de mostrar el formulario. Entrando desde /sale/nota/:id
// (flujo simple, botón en la fila de una venta) esto nunca se activa.
const modoBusqueda = ref(!saleId.value);
const volverRoute = computed(() =>
    route.name === "notas.create" ? { name: "nota.list" } : { name: "sale.list" }
);

const buscarText = ref("");
const buscarSugerencias = ref<SaleSearchResult[]>([]);
const buscando = ref(false);
let buscarTimeout: ReturnType<typeof setTimeout> | undefined;

const loading = ref(true);
const sale = ref<Sale | null>(null);
const config = ref<NoteConfig | null>(null);

const tipoDoc = ref<"07" | "08">("07");
const codMotivo = ref<string>("");
const desMotivo = ref<string>("");
const tipoAfectacion = ref<"total" | "parcial">("total");
const reponerStock = ref(false);

type LineaSeleccionable = { detalle: SaleDetail; seleccionado: boolean; cantidad: number; monto: number };
const lineasSeleccionables = ref<LineaSeleccionable[]>([]);
const conceptosLibres = ref<{ product_id: number | ""; quantity: number; subtotal: number }[]>([]);

const totalesPreview = ref<Record<string, number> | null>(null);
const loadingPreview = ref(false);
const loadingCrear = ref(false);
const loadingEnviar = ref(false);
const enviandoDesdeTabla = ref<number | null>(null);

const nota = ref<Note | null>(null);

const moneda = computed(() => (sale.value?.currency === "USD" ? "US$" : "S/"));

const tipoDocAfectado = computed(() => {
    if (!sale.value) return "01";
    return sale.value.serie?.startsWith("F") ? "01" : "03";
});

const motivosDisponibles = computed<NoteMotivo[]>(() => {
    if (!config.value) return [];
    const catalogo = tipoDoc.value === "07" ? "09" : "10";
    return config.value.motivos.filter((m) => {
        if (m.catalogo !== catalogo) return false;
        if (!m.disponible_flujo_simple) return false;
        if (m.solo_factura && tipoDocAfectado.value === "03") return false;
        return true;
    });
});

const motivoSeleccionado = computed<NoteMotivo | undefined>(() =>
    motivosDisponibles.value.find((m) => m.codigo === codMotivo.value)
);

const esModoMonto = computed(() => motivoSeleccionado.value?.modo_parcial === "monto");
const esDescuentoGlobal = computed(() =>
    motivoSeleccionado.value?.catalogo === "09" && motivoSeleccionado.value?.codigo === "04"
);
const descuentoGlobal = ref<number>(0);

const requiereDescripcionDetallada = computed(() => {
    if (!motivoSeleccionado.value) return false;
    return (
        (motivoSeleccionado.value.catalogo === "09" && motivoSeleccionado.value.codigo === "10") ||
        (motivoSeleccionado.value.catalogo === "10" && motivoSeleccionado.value.codigo === "03")
    );
});

const productosEspeciales = computed<Product[]>(() => config.value?.product_notes?.data ?? []);

const puedeCalcular = computed(() => {
    if (!motivoSeleccionado.value) return false;
    if (esDescuentoGlobal.value) return descuentoGlobal.value > 0;
    return true;
});
const puedeCrear = computed(() => !!motivoSeleccionado.value && !!totalesPreview.value);

const estadoLabel = computed(() => {
    switch (nota.value?.status) {
        case "pendiente": return "Pendiente de envío";
        case "enviando": return "Enviando...";
        case "aceptado": return "Aceptado por SUNAT";
        case "rechazado": return "Rechazado por SUNAT";
        default: return "";
    }
});

const estadoBadgeClass = computed(() => {
    switch (nota.value?.status) {
        case "aceptado": return "bg-success";
        case "rechazado": return "bg-danger";
        default: return "bg-secondary";
    }
});

function cambiarTipoDoc(t: "07" | "08") {
    tipoDoc.value = t;
    codMotivo.value = "";
    tipoAfectacion.value = "total";
    totalesPreview.value = null;
    descuentoGlobal.value = 0;
}

function onMotivoChange() {
    if (!motivoSeleccionado.value) return;
    if (!motivoSeleccionado.value.permite_total && motivoSeleccionado.value.permite_parcial) {
        tipoAfectacion.value = "parcial";
    } else if (motivoSeleccionado.value.permite_total && !motivoSeleccionado.value.permite_parcial) {
        tipoAfectacion.value = "total";
    }

    // Premarcar reposición de stock solo en motivos de devolución (09-06, 09-07)
    reponerStock.value =
        motivoSeleccionado.value.catalogo === "09" &&
        ["06", "07"].includes(motivoSeleccionado.value.codigo);

    totalesPreview.value = null;
}

function armarItems(): NotaItemRequest[] {
    const items: NotaItemRequest[] = [];

    if (esModoMonto.value) {
        lineasSeleccionables.value
            .filter((l) => l.seleccionado && l.monto > 0)
            .forEach((l) => {
                items.push({
                    sale_detail_id: Number(l.detalle.id),
                    product_id: Number(l.detalle.product_id),
                    quantity: l.detalle.quantity,
                    monto: l.monto,
                });
            });
    } else {
        lineasSeleccionables.value
            .filter((l) => l.seleccionado && l.cantidad > 0)
            .forEach((l) => {
                items.push({
                    sale_detail_id: Number(l.detalle.id),
                    product_id: Number(l.detalle.product_id),
                    quantity: l.cantidad,
                });
            });
    }

    if (tipoDoc.value === "08") {
        conceptosLibres.value
            .filter((c) => c.product_id && c.quantity > 0 && c.subtotal > 0)
            .forEach((c) => {
                items.push({
                    product_id: Number(c.product_id),
                    quantity: c.quantity,
                    subtotal: c.subtotal,
                });
            });
    }

    return items;
}

async function calcularPreview() {
    loadingPreview.value = true;
    try {
        const payload: any = {
            sale_id: saleId.value,
            tipo_doc: tipoDoc.value,
            cod_motivo: codMotivo.value,
            tipo_afectacion: tipoAfectacion.value,
        };
        if (esDescuentoGlobal.value) {
            payload.descuento_global = descuentoGlobal.value;
        } else if (tipoAfectacion.value === "parcial") {
            payload.items = armarItems();
        }

        const { data } = await httpClient.post("notas/preview", payload);
        totalesPreview.value = data.totales;
    } catch (e: any) {
        totalesPreview.value = null;
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "No se pudo calcular la vista previa.",
            "error"
        );
    } finally {
        loadingPreview.value = false;
    }
}

async function crearNota() {
    loadingCrear.value = true;
    try {
        const payload: any = {
            sale_id: saleId.value,
            tipo_doc: tipoDoc.value,
            cod_motivo: codMotivo.value,
            des_motivo: desMotivo.value,
            tipo_afectacion: tipoAfectacion.value,
            reponer_stock: reponerStock.value,
        };
        if (esDescuentoGlobal.value) {
            payload.descuento_global = descuentoGlobal.value;
        } else if (tipoAfectacion.value === "parcial") {
            payload.items = armarItems();
        }

        const { data } = await httpClient.post("notas", payload);
        nota.value = data.note;
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "No se pudo crear la nota.",
            "error"
        );
    } finally {
        loadingCrear.value = false;
    }
}

// ── Enviar una nota 'pendiente' directamente desde la tabla de "notas ya
// emitidas sobre esta venta" — para cuando el usuario creó la nota, salió
// de la página, y volvió después sin pasar por la pantalla de revisión
// (donde vivía nota.value, que es estado local y se pierde al navegar).
async function enviarSunatDesdeTabla(notaId: number) {
    if (!sale.value) return;
    enviandoDesdeTabla.value = notaId;
    try {
        const { data } = await httpClient.post("notas/enviar-sunat", { note_id: notaId });

        const actualizada = data.note;
        const idx = sale.value.notes.findIndex((n) => n.id === notaId);
        if (idx !== -1 && actualizada) {
            sale.value.notes[idx] = {
                ...sale.value.notes[idx],
                status: actualizada.status,
                n_operacion: actualizada.n_operacion,
                mto_imp_venta: Number(actualizada.mto_imp_venta),
            };
        }

        if (data.response?.error) {
            (Swal as TVueSwalInstance).fire(
                "Rechazado por SUNAT",
                data.response.error.message ?? "La nota fue rechazada.",
                "error"
            );
        } else {
            (Swal as TVueSwalInstance).fire("¡Enviado a SUNAT!", "La nota fue aceptada.", "success");
        }
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "Error inesperado al enviar la nota.",
            "error"
        );
    } finally {
        enviandoDesdeTabla.value = null;
    }
}

async function enviarSunat() {
    if (!nota.value) return;
    loadingEnviar.value = true;
    try {
        const { data } = await httpClient.post("notas/enviar-sunat", { note_id: nota.value.id });

        if (data.response?.error) {
            nota.value = data.note ?? nota.value;
            (Swal as TVueSwalInstance).fire(
                "Rechazado por SUNAT",
                data.response.error.message ?? "La nota fue rechazada.",
                "error"
            );
            // Refrescar estado (puede haber quedado 'rechazado')
            if (!data.note) {
                await recargarNota();
            }
            return;
        }

        nota.value = data.note;
        (Swal as TVueSwalInstance).fire("¡Enviado a SUNAT!", "La nota fue aceptada.", "success");
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "Error inesperado al enviar la nota.",
            "error"
        );
        await recargarNota();
    } finally {
        loadingEnviar.value = false;
    }
}

async function recargarNota() {
    if (!nota.value) return;
    try {
        const { data } = await httpClient.get(`notas/${nota.value.id}`);
        nota.value = data;
    } catch {
        // best-effort
    }
}

function reiniciarFormulario() {
    nota.value = null;
    totalesPreview.value = null;
    codMotivo.value = "";
    desMotivo.value = "";
    lineasSeleccionables.value.forEach((l) => { l.seleccionado = false; l.cantidad = 0; l.monto = 0; });
    conceptosLibres.value = [];
    descuentoGlobal.value = 0;
}

// ── Búsqueda de venta (entrada por notas.create, sin :id en la ruta) ──────
// Mismo patrón que el buscador de cliente en advances/create.vue: debounce
// 300ms + GET con "q" + dropdown de resultados.
function onBuscarVentaInput() {
    clearTimeout(buscarTimeout);
    const q = buscarText.value.trim();
    if (q.length < 2) {
        buscarSugerencias.value = [];
        return;
    }
    buscarTimeout = setTimeout(async () => {
        buscando.value = true;
        try {
            const { data } = await httpClient.get("notas/buscar-venta", { params: { q } });
            buscarSugerencias.value = data.sales;
        } catch {
            buscarSugerencias.value = [];
        } finally {
            buscando.value = false;
        }
    }, 300);
}

async function seleccionarVenta(s: SaleSearchResult) {
    buscarSugerencias.value = [];
    buscarText.value = `${s.serie}-${String(s.correlativo).padStart(8, "0")}`;
    saleId.value = String(s.id);
    await cargarVenta();
}

async function cargarVenta() {
    if (!saleId.value) return;
    loading.value = true;
    try {
        const { data } = await httpClient.get(`sales/${saleId.value}`);
        sale.value = data.sale ?? data;

        lineasSeleccionables.value = (sale.value?.sale_details ?? []).map((detalle) => ({
            detalle,
            seleccionado: false,
            cantidad: Number(detalle.quantity),
            monto: 0,
        }));

        modoBusqueda.value = false;
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "No se pudo cargar la información de la venta.",
            "error"
        );
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    loading.value = true;
    try {
        const { data } = await httpClient.get("notas/config");
        config.value = data;
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            e.response?.data?.message ?? "No se pudo cargar la configuración de notas.",
            "error"
        );
    }

    if (saleId.value) {
        await cargarVenta();
    } else {
        loading.value = false;
    }
});
</script>
