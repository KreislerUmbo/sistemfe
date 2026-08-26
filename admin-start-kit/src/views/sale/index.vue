<template>
    <DefaultLayout>

        <!-- ═══════════════════════════════════════════════════
             HEADER: Título + Nueva Venta
        ═══════════════════════════════════════════════════ -->
        <div class="header-row d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-cash-register me-2 text-primary"></i>
                    Ventas
                </h5>
                <small class="text-muted">
                    {{ totalPages }} registro(s) encontrado(s)
                </small>
            </div>
            <router-link to="/sale/register" class="btn btn-primary fw-semibold shadow-sm">
                <i class="fas fa-plus me-2"></i>Nueva Venta
            </router-link>
        </div>

        <!-- ═══════════════════════════════════════════════════
             BARRA SUPERIOR: Filtros + Periodo + Búsqueda rápida
        ═══════════════════════════════════════════════════ -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body p-0 d-flex align-items-stretch flex-wrap">

                <!-- Toggle Filtros Avanzados -->
                <div class="d-flex align-items-center gap-2 px-3 py-2 flex-grow-1"
                    style="cursor:pointer; min-width:240px;" @click="toggleFilters">
                    <div class="ico bg-light rounded d-flex align-items-center justify-content-center"
                        style="width:32px;height:32px;">
                        <i class="fas fa-sliders-h text-primary"></i>
                    </div>
                    <div>
                        <div class="fw-semibold small">Filtros avanzados <span class="badge bg-primary ms-1"
                                v-if="filterCount > 0">{{ filterCount }}</span></div>
                        <div class="text-muted small" v-if="filterSummary">
                            <span v-for="(chip, idx) in filterChips" :key="idx" class="badge bg-light text-dark me-1">
                                {{ chip }} <i class="fas fa-times ms-1" style="cursor:pointer;"
                                    @click.stop="removeChip(idx)"></i>
                            </span>
                        </div>
                    </div>
                    <i class="fas fa-chevron-down ms-auto" :class="{ 'rotate-180': showFilters }"></i>
                </div>

                <div class="vr d-none d-md-block"></div>

                <!-- Periodo -->
                <div class="d-flex align-items-center px-3 py-2" style="cursor:pointer; min-width:200px;"
                    @click="togglePeriod">
                    <i class="fas fa-calendar-alt text-primary me-2"></i>
                    <div>
                        <div class="text-uppercase small text-muted fw-bold" style="font-size:10px;">Ver por</div>
                        <span class="fw-semibold small">{{ periodoLabel }}</span>
                    </div>
                    <i class="fas fa-chevron-down ms-auto" :class="{ 'rotate-180': showPeriod }"></i>
                </div>

                <div class="vr d-none d-md-block"></div>

                <!-- Búsqueda rápida -->
                <div class="d-flex align-items-center flex-grow-1 px-3 py-2" style="min-width:180px;">
                    <i class="fas fa-search text-muted me-2"></i>
                    <input type="text" class="form-control form-control-sm border-0 shadow-none"
                        placeholder="Buscar por N° de venta..." v-model="search" @keyup.enter="list">
                </div>
            </div>

            <!-- Panel de Periodo (colapsable) -->
            <div v-show="showPeriod" class="border-top p-3">
                <div class="d-flex flex-wrap align-items-end gap-3">
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-sm"
                            :class="periodActive === 'today' ? 'btn-primary' : 'btn-outline-secondary'"
                            @click="setPeriod('today')">Hoy</button>
                        <button class="btn btn-sm"
                            :class="periodActive === 'yesterday' ? 'btn-primary' : 'btn-outline-secondary'"
                            @click="setPeriod('yesterday')">Ayer</button>
                        <button class="btn btn-sm"
                            :class="periodActive === 'thisWeek' ? 'btn-primary' : 'btn-outline-secondary'"
                            @click="setPeriod('thisWeek')">Esta semana</button>
                        <button class="btn btn-sm"
                            :class="periodActive === 'thisMonth' ? 'btn-primary' : 'btn-outline-secondary'"
                            @click="setPeriod('thisMonth')">Este mes</button>
                        <button class="btn btn-sm"
                            :class="periodActive === 'lastMonth' ? 'btn-primary' : 'btn-outline-secondary'"
                            @click="setPeriod('lastMonth')">Mes anterior</button>
                    </div>
                    <div class="d-flex gap-2 align-items-end">
                        <div>
                            <label class="form-label small mb-0">Desde</label>
                            <input type="date" class="form-control form-control-sm" v-model="start_date">
                        </div>
                        <div>
                            <label class="form-label small mb-0">Hasta</label>
                            <input type="date" class="form-control form-control-sm" v-model="end_date">
                        </div>
                        <button class="btn btn-outline-secondary btn-sm" @click="reset"><i
                                class="fas fa-undo me-1"></i>Limpiar</button>
                        <button class="btn btn-primary btn-sm" @click="applyPeriod">Aplicar</button>
                    </div>
                </div>
            </div>

            <!-- Panel de Filtros Avanzados (colapsable) -->
            <div v-show="showFilters" class="border-top p-3">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold"><i class="fas fa-box me-1"></i> Producto /
                            SKU</label>
                        <input type="text" class="form-control form-control-sm" v-model="search_product"
                            placeholder="Buscar por nombre o SKU..." @keyup.enter="list">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold"><i class="fas fa-tag me-1"></i> Categoría</label>
                        <select class="form-select form-select-sm" v-model="categorie_id">
                            <option value="">Todas</option>
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.title }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold"><i class="fas fa-user me-1"></i> Cliente</label>
                        <input type="text" class="form-control form-control-sm" v-model="search_client"
                            placeholder="Nombre, DNI, RUC..." @keyup.enter="list">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Tipo de Pago</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="type_payment_f" id="pf-todos" value="0"
                                :checked="type_payment == 0" @click="type_payment = 0">
                            <label class="btn btn-outline-secondary btn-sm" for="pf-todos">Todos</label>
                            <input type="radio" class="btn-check" name="type_payment_f" id="pf-contado" value="1"
                                :checked="type_payment == 1" @click="type_payment = 1">
                            <label class="btn btn-outline-success btn-sm" for="pf-contado"><i
                                    class="fas fa-money-bill-wave me-1"></i>Contado</label>
                            <input type="radio" class="btn-check" name="type_payment_f" id="pf-credito" value="2"
                                :checked="type_payment == 2" @click="type_payment = 2">
                            <label class="btn btn-outline-warning btn-sm" for="pf-credito"><i
                                    class="fas fa-calendar-alt me-1"></i>Crédito</label>
                        </div>
                    </div>
                    <div class="col-12 d-flex justify-content-end gap-2">
                        <button class="btn btn-outline-secondary btn-sm" @click="reset"><i
                                class="fas fa-undo me-1"></i>Limpiar</button>
                        <button class="btn btn-primary btn-sm" @click="list"><i class="fas fa-search me-1"></i>Aplicar
                            filtros</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════
             TABLA DE VENTAS
        ═══════════════════════════════════════════════════ -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr class="small text-secondary text-uppercase">
                                <th class="ps-3">#</th>
                                <th>Cliente</th>
                                <th>Comprobante</th>
                                <th class="text-center">Pago</th>
                                <th class="text-center">Estado</th>
                                <th class="text-end">IGV</th>
                                <th class="text-end">Total</th>
                                <th class="text-center">Fecha</th>
                                <th class="text-center">SUNAT</th>
                                <th class="text-center pe-3">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Cargando -->
                            <tr v-if="loading">
                                <td colspan="10" class="text-center py-5 text-muted">
                                    <div class="spinner-border spinner-border-sm me-2"></div>
                                    Cargando ventas...
                                </td>
                            </tr>
                            <!-- Sin datos -->
                            <tr v-else-if="sale_list.length === 0">
                                <td colspan="10" class="text-center py-5 text-muted fst-italic">
                                    <i class="fas fa-inbox opacity-50 fs-4 mb-2 d-block"></i>
                                    No se encontraron ventas con los filtros seleccionados.
                                </td>
                            </tr>
                            <!-- Filas -->
                            <tr v-for="(sale, index) in sale_list" :key="sale.id">
                                <td class="ps-3 text-muted small fw-semibold">{{ sale.id }}</td>

                                <!-- Cliente -->
                                <td>
                                    <div class="fw-semibold">{{ sale.client?.full_name ?? '—' }}</div>
                                    <small class="text-muted">{{ sale.client?.n_document }}</small>
                                </td>

                                <!-- Comprobante -->
                                <td>
                                    <span v-if="sale.n_operacion"
                                        class="badge bg-success-subtle text-success border border-success-subtle fw-semibold">
                                        {{ sale.n_operacion }}
                                    </span>
                                    <span v-else class="text-muted small">{{ sale.serie || '—' }}</span>

                                    <!-- Condición tributaria: detracción, percepción, retención, exoneración, exportación -->
                                    <div v-if="condicionesTributarias(sale).length" class="d-flex flex-wrap gap-1 mt-1">
                                        <span v-for="cond in condicionesTributarias(sale)" :key="cond.tipo"
                                            class="tax-chip" :class="`tax-${cond.tipo}`" :title="cond.detalle">
                                            {{ cond.label }}
                                        </span>
                                    </div>
                                </td>

                                <!-- Pago -->
                                <td class="text-center">
                                    <span class="badge"
                                        :class="sale.type_payment == 1 ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-warning-subtle text-warning border border-warning-subtle'">
                                        <i class="fas me-1"
                                            :class="sale.type_payment == 1 ? 'fa-money-bill-wave' : 'fa-calendar-alt'"></i>
                                        {{ sale.type_payment == 1 ? 'Contado' : 'Crédito' }}
                                    </span>
                                </td>

                                <!-- Estado de pago -->
                                <td class="text-center">
                                    <span class="badge" :class="{
                                        'bg-danger-subtle text-danger border border-danger-subtle': sale.state_payment == 1,
                                        'bg-warning-subtle text-warning border border-warning-subtle': sale.state_payment == 2,
                                        'bg-success-subtle text-success border border-success-subtle': sale.state_payment == 3,
                                    }">
                                        <i class="fas me-1" :class="{
                                            'fa-clock': sale.state_payment == 1,
                                            'fa-hourglass-half': sale.state_payment == 2,
                                            'fa-check-circle': sale.state_payment == 3,
                                        }"></i>
                                        {{ getStatePayment(sale.state_payment) }}
                                    </span>
                                </td>

                                <!-- IGV -->
                                <td class="text-end small text-muted">{{ sale.currency }} {{ Number(sale.igv_general ??
                                    0).toFixed(2) }}</td>

                                <!-- Total -->
                                <td class="text-end fw-bold">{{ sale.currency }} {{ Number(sale.total ?? 0).toFixed(2)
                                    }}</td>

                                <!-- Fecha -->
                                <td class="text-center small text-muted">{{ sale.created_at_format }}</td>

                                <!-- SUNAT -->
                                <td class="text-center">
                                    <div class="d-flex align-items-center justify-content-center gap-1">
                                        <!-- Botón enviar/reintentar — antes solo se mostraba con
                                             !sale.correlativo, lo que dejaba sin forma de reintentar una
                                             venta cuyo correlativo ya se quemó en un envío rechazado
                                             (xml/cdr null pero correlativo seteado). -->
                                        <button v-if="!(sale.xml && sale.cdr)" type="button"
                                            class="btn btn-sm btn-outline-primary py-0 px-2" @click="enviarSunat(sale)"
                                            :disabled="loadingSunat === sale.id"
                                            :title="sale.correlativo ? 'Reintentar envío a SUNAT' : 'Enviar a SUNAT'">
                                            <span v-if="loadingSunat === sale.id">
                                                <span class="spinner-border spinner-border-sm"></span>
                                            </span>
                                            <span v-else><img :src="sunatLg" width="28" alt="SUNAT"></span>
                                        </button>

                                        <!-- Con correlativo: XML y CDR -->
                                        <a v-if="sale.correlativo && sale.xml" :href="getFileUrl(sale.xml)"
                                            target="_blank" class="btn btn-sm btn-outline-success py-0 px-2"
                                            title="Descargar XML">
                                            <img :src="xmlLg" width="22" alt="XML">
                                        </a>
                                        <a v-if="sale.correlativo && sale.cdr" :href="getFileUrl(sale.cdr)"
                                            target="_blank" class="btn btn-sm btn-outline-warning py-0 px-2"
                                            title="Descargar CDR">
                                            <img :src="cdrLg" width="22" alt="CDR">
                                        </a>

                                        <!-- Nota de crédito/débito -->
                                        <!-- sale.correlativo por sí solo NO basta: queda seteado incluso
                                             cuando SUNAT rechaza la venta (se reserva antes de enviar).
                                             Requerir xml && cdr asegura que la venta esté realmente aceptada. -->
                                        <button v-if="sale.correlativo && sale.xml && sale.cdr" type="button"
                                            class="btn btn-sm btn-outline-secondary py-0 px-2 position-relative"
                                            @click="notaSale(sale)"
                                            :title="sale.notes?.length ? `${sale.notes.length} nota(s) emitida(s) — click para ver/emitir` : 'Emitir Nota de Crédito/Débito'">
                                            <img :src="notaCLg" width="20" alt="Nota">
                                            <span v-if="sale.notes?.length"
                                                class="badge rounded-pill bg-primary position-absolute top-0 start-100 translate-middle"
                                                style="font-size:9px;">
                                                {{ sale.notes.length }}
                                            </span>
                                        </button>

                                        <!-- Estado simplificado — antes se mostraba "Aceptado" con solo
                                             sale.correlativo, que queda seteado incluso cuando SUNAT
                                             rechaza el envío (se reserva antes de mandar). -->
                                        <span v-if="sale.xml && sale.cdr"
                                            class="badge bg-success-subtle text-success border border-success-subtle small">Aceptado</span>
                                        <span v-else-if="sale.correlativo"
                                            class="badge bg-danger-subtle text-danger border border-danger-subtle small"
                                            :title="sale.sunat_error_message ?? 'Sin detalle del motivo'">
                                            Rechazado
                                        </span>
                                    </div>
                                </td>

                                <!-- Acciones -->
                                <td class="text-center pe-3">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown"
                                            aria-expanded="false">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                            <li><a class="dropdown-item" href="#" @click.prevent="showPdf(sale)"><i
                                                        class="fas fa-file-pdf me-2"></i>Ver PDF</a></li>
                                            <li><a class="dropdown-item" href="#" @click.prevent="editSale(sale)"><i
                                                        class="fas fa-edit me-2"></i>Editar</a></li>
                                            <li>
                                                <hr class="dropdown-divider">
                                            </li>
                                            <li><a class="dropdown-item" href="#" @click.prevent="reimprimir(sale)"><i
                                                        class="fas fa-print me-2"></i>Reimprimir</a></li>
                                            <li><a class="dropdown-item" href="#"
                                                    @click.prevent="enviarWhatsApp(sale)"><i
                                                        class="fab fa-whatsapp me-2"></i>WhatsApp</a></li>
                                            <li><a class="dropdown-item" href="#"
                                                    @click.prevent="verEstadoSunat(sale)"><i
                                                        class="fas fa-search me-2"></i>Ver estado SUNAT</a></li>
                                            <li><a class="dropdown-item" href="#" @click.prevent="clonarVenta(sale)"><i
                                                        class="fas fa-copy me-2"></i>Clonar venta</a></li>
                                            <li>
                                                <hr class="dropdown-divider">
                                            </li>
                                            <li v-if="!sale.correlativo">
                                                <a class="dropdown-item text-danger" href="#"
                                                    @click.prevent="deleteSale(sale)"><i
                                                        class="fas fa-trash-alt me-2"></i>Eliminar</a>
                                            </li>
                                            <li v-else><span class="dropdown-item text-muted"><i
                                                        class="fas fa-lock me-2"></i>Bloqueado</span></li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Paginación -->
                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
                    <small class="text-muted">Mostrando {{ sale_list.length }} de {{ totalPages }} registro(s)</small>
                    <b-pagination v-model="currentPage" :total-rows="totalPages" :per-page="perPageRows"
                        prev-text="Anterior" next-text="Siguiente" class="mb-0" />
                </div>
            </div>
        </div>

    </DefaultLayout>
</template>

<script setup lang="ts">
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import type { ProductCategorie, ProductConfigResponse } from "@/types/products";
import type { Sale, Sales } from "@/types/sales";
import { onMounted, ref, watch, computed } from "vue";
import Swal from "sweetalert2/dist/sweetalert2.js";
import sunatLg from "@/assets/images/sunat-logo.png";
import xmlLg from "@/assets/images/logo-xml.png";
import cdrLg from "@/assets/images/logo-cdr.png";
import notaCLg from "@/assets/images/nota-c.png";
import type { AxiosResponse } from "axios";
import httpClient from "@/helpers/http-client";
import router from "@/router";
import { imprimirComprobante } from "@/composables/usePrintComprobante";
import { useAuthStore } from "@/stores/auth";

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

// ── Estado de UI ──────────────────────────────────────────────────
const sale_list = ref<Sale[]>([]);
const currentPage = ref<number>(1);
const totalPages = ref<number>(0);
const perPageRows = ref<number>(25);
const loading = ref<boolean>(false);
const loadingSunat = ref<string | number | null>(null);

// ── Colapsables ──────────────────────────────────────────────────
const showFilters = ref<boolean>(false);
const showPeriod = ref<boolean>(false);

// ── Filtros ───────────────────────────────────────────────────────
const search_product = ref<string>("");
const categorie_id = ref<string>("");
const search = ref<string>("");          // N° de venta (búsqueda rápida)
const search_client = ref<string>("");
const type_payment = ref<number>(0);     // 0=todos, 1=contado, 2=crédito
const start_date = ref<string>("");
const end_date = ref<string>("");
const categories = ref<ProductCategorie[]>([]);

// ── Periodo activo (para resaltar chips) ──────────────────────
const periodActive = ref<string>("");

// ── Computed: resumen de filtros activos ──────────────────────
const filterCount = computed(() => {
    let count = 0;
    if (search_product.value) count++;
    if (categorie_id.value) count++;
    if (search_client.value) count++;
    if (type_payment.value !== 0) count++;
    if (start_date.value || end_date.value) count++;
    return count;
});

const filterChips = computed(() => {
    const chips: string[] = [];
    if (search_product.value) chips.push(`Producto: ${search_product.value}`);
    if (categorie_id.value) {
        const cat = categories.value.find(c => c.id === Number(categorie_id.value));
        if (cat) chips.push(`Categoría: ${cat.title}`);
    }
    if (search_client.value) chips.push(`Cliente: ${search_client.value}`);
    if (type_payment.value === 1) chips.push('Pago: Contado');
    else if (type_payment.value === 2) chips.push('Pago: Crédito');
    if (start_date.value || end_date.value) {
        const from = start_date.value || '...';
        const to = end_date.value || '...';
        chips.push(`Fecha: ${from} → ${to}`);
    }
    return chips;
});

const filterSummary = computed(() => filterChips.value.length > 0);

// ── Label del periodo ────────────────────────────────────────────
const periodoLabel = computed(() => {
    if (start_date.value && end_date.value) {
        return `${start_date.value} — ${end_date.value}`;
    }
    return 'Seleccionar fechas';
});

// ── Toggle colapsables ──────────────────────────────────────────
const toggleFilters = () => { showFilters.value = !showFilters.value; };
const togglePeriod = () => { showPeriod.value = !showPeriod.value; };

// ── Métodos de periodo ──────────────────────────────────────────
const setPeriod = (key: string) => {
    periodActive.value = key;
    const now = new Date();
    let from = new Date();
    let to = new Date();

    switch (key) {
        case 'today':
            from = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            to = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            break;
        case 'yesterday':
            from = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
            to = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
            break;
        case 'thisWeek': {
            const day = now.getDay(); // 0=domingo
            const diff = now.getDate() - day + (day === 0 ? -6 : 1); // lunes
            from = new Date(now.getFullYear(), now.getMonth(), diff);
            to = new Date(now.getFullYear(), now.getMonth(), diff + 6);
            break;
        }
        case 'thisMonth':
            from = new Date(now.getFullYear(), now.getMonth(), 1);
            to = new Date(now.getFullYear(), now.getMonth() + 1, 0);
            break;
        case 'lastMonth': {
            const prev = new Date(now.getFullYear(), now.getMonth() - 1, 1);
            from = new Date(prev.getFullYear(), prev.getMonth(), 1);
            to = new Date(prev.getFullYear(), prev.getMonth() + 1, 0);
            break;
        }
        default: return;
    }
    // Formato YYYY-MM-DD
    const format = (d: Date) => d.toISOString().split('T')[0];
    start_date.value = format(from);
    end_date.value = format(to);
    // Cerrar panel de periodo
    showPeriod.value = false;
    list();
};

const applyPeriod = () => {
    periodActive.value = '';
    showPeriod.value = false;
    list();
};

// ── Cargar lista de ventas ────────────────────────────────────────
const list = async () => {
    loading.value = true;
    try {
        const res: AxiosResponse<Sales> = await httpClient.post(
            `sales/index?page=${currentPage.value}`,
            {
                search: search.value ?? '',
                search_product: search_product.value ?? '',
                categorie_id: categorie_id.value ?? '',
                search_client: search_client.value ?? '',
                type_payment: type_payment.value ?? '',
                start_date: start_date.value ?? '',
                end_date: end_date.value ?? '',
            }
        );
        sale_list.value = res.data.sales.data;
        totalPages.value = res.data.total;
        perPageRows.value = res.data.paginate;
    } catch (error) {
        console.error(error);
    } finally {
        loading.value = false;
    }
};

// ── Cargar categorías para el filtro ─────────────────────────────
const config = async () => {
    try {
        const res: AxiosResponse<ProductConfigResponse> = await httpClient.get('products/config');
        categories.value = res.data.categories;
    } catch (error) {
        console.error(error);
    }
};

// ── Limpiar filtros ───────────────────────────────────────────────
const reset = () => {
    search.value = "";
    search_product.value = "";
    categorie_id.value = "";
    search_client.value = "";
    type_payment.value = 0;
    start_date.value = "";
    end_date.value = "";
    periodActive.value = "";
    currentPage.value = 1;
    // Cerrar paneles
    showFilters.value = false;
    showPeriod.value = false;
    list();
};

// ── Eliminar chip de filtro ──────────────────────────────────────
const removeChip = (idx: number) => {
    const chip = filterChips.value[idx];
    if (chip.includes('Producto')) search_product.value = '';
    else if (chip.includes('Categoría')) categorie_id.value = '';
    else if (chip.includes('Cliente')) search_client.value = '';
    else if (chip.includes('Pago: Contado')) type_payment.value = 0;
    else if (chip.includes('Pago: Crédito')) type_payment.value = 0;
    else if (chip.includes('Fecha')) {
        start_date.value = '';
        end_date.value = '';
        periodActive.value = '';
    }
    list();
};

// ── Estado de pago en texto ───────────────────────────────────────
const getStatePayment = (state_payment: number): string => {
    switch (state_payment) {
        case 1: return "Pendiente";
        case 2: return "Parcial";
        case 3: return "Pagado";
        default: return "—";
    }
};

// ── Condición tributaria: detracción, percepción, retención, exoneración, exportación ──
// El backend ya calcula y guarda estos campos en Sale. Aquí solo se interpretan
// para pintarlos; ninguna regla tributaria se decide en el frontend.
//
// IMPORTANTE: retencion_igv es un SELECTOR ÚNICO, no un flag independiente:
//   0 = ninguno, 1 = retención, 2 = detracción, 3 = percepción
// Es decir, una venta solo puede tener UNO de estos tres regímenes, nunca dos a la vez.
// Exoneración y exportación sí son independientes y pueden coexistir con cualquiera de los tres.
type CondicionTributaria = { tipo: string; label: string; detalle: string };

const condicionesTributarias = (sale: Sale): CondicionTributaria[] => {
    const condiciones: CondicionTributaria[] = [];

    switch (Number(sale.retencion_igv)) {

        case 1:
            condiciones.push({
                tipo: 'retencion',
                label: 'Retención',
                detalle: `Retención: ${sale.currency} ${Number(sale.monto_retencion ?? 0).toFixed(2)}`,
            });
            break;
        case 2:
            condiciones.push({
                tipo: 'detraccion',
                label: 'Detracción',
                detalle: `Detracción: ${sale.currency} ${Number(sale.monto_detraccion ?? 0).toFixed(2)}`,
            });
            break;
        case 3:
            condiciones.push({
                tipo: 'percepcion',
                label: 'Percepción',
                detalle: `Percepción: ${sale.currency} ${Number(sale.monto_percepcion ?? 0).toFixed(2)}`,
            });
            break;
        // 0 o cualquier otro valor: ninguno de los tres, no se agrega chip
    }

    if (Number(sale.mto_oper_exoneradas) > 0) {
        condiciones.push({ tipo: 'exonerado', label: 'Exonerado', detalle: 'Exoneración Ley 27037 (Amazonía)' });
    }

    if (Number(sale.is_exportacion) === 1) {
        condiciones.push({ tipo: 'exportacion', label: 'Exportación', detalle: 'Operación de exportación' });
    }
    if (Number(sale.retencion_igv == 0) && sale.type == 'advance') {
        condiciones.push({ tipo: 'anticipo', label: 'Anticipo', detalle: 'Operación de anticipos' });
    }
    if (Number(sale.mto_oper_gravadas) > 0) {
        condiciones.push({ tipo: 'gravado', label: 'Gravado', detalle: 'Operación Gravado' });
    }

    return condiciones;
};

// ── Acciones ──────────────────────────────────────────────────────
const showPdf = async (sale: Sale) => {
    const formatoDefault = useAuthStore().getUser()?.formato_impresion_default ?? 'a4';
    const { data } = await httpClient.get(`sales-pdf-url/${sale.id}`, { params: { format: formatoDefault } });
    window.open(data.url, '_blank');
};

const editSale = (sale: Sale) => {
    if (sale.is_locked == true) {
        (Swal as TVueSwalInstance).fire({
            title: "Venta bloqueada",
            text: `La venta N° ${sale.id} no se puede editar porque ya fue enviada a SUNAT.`,
            icon: "warning",
            confirmButtonText: "Aceptar",
        });
        return;
    }
    router.push({ name: "sale.edit", params: { id: sale.id } });
};

const deleteSale = async (sale: Sale) => {
    const result = await (Swal as TVueSwalInstance).fire({
        title: "¿Eliminar esta venta?",
        text: `Se eliminará la venta N° ${sale.id}. Esta acción no se puede deshacer.`,
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#dc2626",
        cancelButtonColor: "#6b7280",
        confirmButtonText: "Sí, eliminar",
        cancelButtonText: "Cancelar",
    });

    if (result.isConfirmed) {
        try {
            await httpClient.delete("sales/" + sale.id);
            const idx = sale_list.value.findIndex(s => s.id === sale.id);
            if (idx !== -1) sale_list.value.splice(idx, 1);
            (Swal as TVueSwalInstance).fire("Eliminada", `La venta N° ${sale.id} fue eliminada.`, "success");
        } catch (error: any) {
            (Swal as TVueSwalInstance).fire("Error", error.response?.data?.message ?? "No se pudo eliminar.", "error");
        }
    }
};

// ── Enviar a SUNAT ─────────────────────────────────────────────────
const enviarSunat = async (sale: Sale) => {
    loadingSunat.value = sale.id;
    try {
        const res: AxiosResponse<any> = await httpClient.post("enviarSunat", { sale_id: sale.id });

        if (res.data.response?.error) {
            (Swal as TVueSwalInstance).fire(
                "Error SUNAT",
                `(${res.data.response.error.code}) ${res.data.response.error.message}`,
                "error"
            );
            return;
        }

        if (res.data.sale) {
            const idx = sale_list.value.findIndex(s => s.id === res.data.sale.id);
            if (idx !== -1) sale_list.value[idx] = res.data.sale;
            (Swal as TVueSwalInstance).fire(
                "¡Enviado a SUNAT!",
                "El comprobante fue aceptado. Ya puedes descargar el XML y el CDR.",
                "success"
            );
        }
    } catch (error: any) {
        (Swal as TVueSwalInstance).fire(
            "Error",
            error.response?.data?.message ?? "Error inesperado al enviar a SUNAT.",
            "error"
        );
    } finally {
        loadingSunat.value = null;
    }
};

// ── Nota de crédito/débito ──────────────────────────────────────────
const notaSale = (sale: Sale) => {
    router.push({ name: "sale.nota", params: { id: sale.id } });
};

// ── Nuevas acciones del menú ──────────────────────────────────────
const reimprimir = (sale: Sale) => {
    const formatoDefault = useAuthStore().getUser()?.formato_impresion_default ?? 'a4';
    imprimirComprobante(sale.id, formatoDefault);
};

const enviarWhatsApp = (sale: Sale) => {
    const phone = sale.client?.phone || '';
    if (phone) {
        const msg = encodeURIComponent(`Hola, su comprobante N° ${sale.id} está listo.`);
        window.open(`https://wa.me/${phone}?text=${msg}`, '_blank');
    } else {
        (Swal as TVueSwalInstance).fire("Sin teléfono", "El cliente no tiene número registrado.", "warning");
    }
};

const verEstadoSunat = (sale: Sale) => {
    (Swal as TVueSwalInstance).fire("Estado SUNAT", `Venta N° ${sale.id}: ${sale.correlativo ? 'Aceptado' : 'Pendiente de envío'}`, "info");
};

const clonarVenta = (sale: Sale) => {
    (Swal as TVueSwalInstance).fire("Clonar venta", `Clonando venta N° ${sale.id}... (próximamente)`, "info");
};

// ── URL para descargar XML/CDR ────────────────────────────────────
const getFileUrl = (path: string): string => {
    if (!path) return '#';
    return import.meta.env.VITE_STORAGE_URL + path;
};

// ── Lifecycle ─────────────────────────────────────────────────────
onMounted(() => {
    list();
    config();
});

watch(currentPage, () => list());

// Re-buscar al cambiar filtros de radio automáticamente
watch([type_payment], () => {
    currentPage.value = 1;
    list();
});

// Cerrar paneles al hacer clic fuera (opcional: se puede añadir)
</script>

<style scoped>
.rotate-180 {
    transform: rotate(180deg);
    transition: transform 0.2s ease;
}

.ico {
    background: #eafaf0;
    color: #15803d;
}

/* Condición tributaria: detracción, percepción, retención, exoneración, exportación */
.tax-chip {
    font-size: 9.5px;
    font-weight: 700;
    letter-spacing: .02em;
    padding: 2px 6px;
    border-radius: 5px;
    color: #fff;
    display: inline-block;
    line-height: 1.4;
}

.tax-gravado {
    background: hsl(288, 83%, 53%);
}

.tax-detraccion {
    background: #2563eb;
}

.tax-percepcion {
    background: #f59e0b;
}

.tax-retencion {
    background: #8b5cf6;
}

.tax-exonerado {
    background: #16a34a;
}

.tax-exportacion {
    background: #64748b;
}

.tax-anticipo {
    background: hsl(54, 100%, 63%);
}
</style>