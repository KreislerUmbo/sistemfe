<template>
    <DefaultLayout>

        <!-- ═══════════════════════════════════════════════════
             CABECERA — estado de la venta + «Datos de la venta» (T-106)
        ═══════════════════════════════════════════════════ -->
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <h5 class="fw-bold mb-0 text-dark me-2">
                <i class="fas fa-cash-register me-2 text-primary"></i>Editar venta
            </h5>
            <button type="button" class="chip" @click="datosAbiertos = true">Fecha <b>{{ formatFecha(today) }}</b></button>
            <button type="button" class="chip" :class="{ 'chip-alerta': currency !== 'PEN' }" @click="datosAbiertos = true">
                Moneda <b>{{ currency === 'PEN' ? 'Soles' : 'Dólares' }}</b>
            </button>
            <button v-if="puedeCambiarSucursal && nombreSucursal" type="button" class="chip" @click="datosAbiertos = true">
                Sucursal <b>{{ nombreSucursal }}</b>
            </button>
            <button v-if="tipoEsFiscal && condicion_especial !== 'exportacion'" type="button" class="chip" @click="datosAbiertos = true">
                Destino <b>{{ destino === 'amazonia' ? 'Amazonía' : 'Fuera' }}</b>
            </button>
            <button v-if="etiquetaCondicion" type="button" class="chip chip-alerta" @click="datosAbiertos = true">{{ etiquetaCondicion }}</button>
            <button type="button" class="btn btn-link btn-sm fw-semibold" @click="datosAbiertos = !datosAbiertos">
                Datos de la venta
                <span v-if="noHabituales" class="badge bg-warning-subtle text-warning ms-1">{{ noHabituales }} no habitual{{ noHabituales > 1 ? 'es' : '' }}</span>
                <i class="fas ms-1" :class="datosAbiertos ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
            </button>
            <button type="button" class="btn btn-link btn-sm text-secondary ms-auto" @click="router.push({ name: 'sale.list' })">
                Ver ventas →
            </button>
        </div>

        <DatosVentaPanel v-if="datosAbiertos" v-model:moneda="currency" v-model:fecha="today"
            v-model:sucursal-id="branch_id_seleccionado" v-model:destino="destino" v-model:condicion="condicion_especial"
            v-model:codigo-detraccion="codigo_detraccion_sel" v-model:n-transaccion="n_transaction"
            :puede-cambiar-sucursal="puedeCambiarSucursal" :sucursal-bloqueada="yaReservoCorrelativo" mostrar-n-transaccion
            :sucursales="branches" :es-fiscal="tipoEsFiscal" :codigos-detraccion="codigos_detraccion"
            @detraccion-cambio="onDetractionCodeChange" @listo="cerrarDatos" />

        <!-- T-110: estructura de la pantalla nueva (T-106): productos a la izquierda, datos y cobro a la derecha.
             Los bloques de dentro se van reemplazando por los componentes de components/Sales/ paso a paso. -->
        <div class="grid-venta">
            <div class="card border-0 shadow-sm">
                <div class="p-3 border-bottom">
                    <BuscadorProductosVenta ref="buscador" :moneda="currency" @agregar="agregarProducto"
                        @crear="openQuickProductModal" />
                </div>
                <div>
                <div class="card-body p-0">
                <TablaLineasVenta :items="sale_details" :moneda="currency" :afe-defecto="resolverTipAfeIgv"
                    :cliente="client_selected" :puede-cambiar-precio="puedeCambiarPrecio" :resaltado="lineaResaltada"
                    :ya-descontado="cantidadOriginal" @cambio="alCambiarLinea" @quitar="removeItem"
                    @elegir-precio="elegirPrecioLinea" />

                <div class="p-3 border-top">
                    <label class="form-label small fw-semibold text-secondary mb-1" for="venta-obs">Observaciones</label>
                    <textarea id="venta-obs" v-model="description" rows="2" class="form-control form-control-sm"
                        placeholder="Notas internas que no salen en el comprobante (opcional)"></textarea>
                </div>
            </div>
        </div>
            </div>
            <div class="panel-cobro">
        <div class="card border-0 shadow-sm mb-3">
            <div class="p-3 border-bottom">
                <PanelClienteComprobante :cliente="client_selected" v-model:direccion-id="client_address_id"
                    v-model:tipo="tipo_comprobante_codigo" :tipos="tiposComprobanteVisibles"
                    :serie="serieResuelta" :error-serie="errorSeriePreview" :tipo-bloqueado="yaReservoCorrelativo"
                    :serie-fija="yaReservoCorrelativo ? serieActual : undefined" @seleccionar="selectClient"
                    @limpiar="clearClientSelection" @registrar="openQuickClientModalWithData" />
            </div>
            <div class="p-3 border-bottom">
                <TotalesVenta v-model:descuento-global="discount_global" :filas="filasTotales" :total="sale_total"
                    :simbolo="simbolo" etiqueta-total="Monto a pagar" :puede-descuento-global="puedeCambiarPrecio" />
            </div>
            <div class="p-3 border-bottom">
                <CobroEdicion v-model:type-payment="type_payment" v-model:pagos="sale_payments"
                    v-model:cronograma="cronograma" v-model:num-cuotas="num_cuotas" v-model:periodicidad="periodicidad"
                    v-model:aplica-mora="aplica_mora" v-model:tasa-mora="tasa_mora" v-model:tipo-mora="tipo_mora"
                    v-model:n-comprobante-anticipo="n_comprobante_anticipo" v-model:sale-anticipo="sale_anticipo"
                    :sale-id="sale_id" :metodos="paymentMethods" :moneda="currency" :fecha-base="today"
                    :total-venta="totalACobrar()" :guardada-a-credito="guardada_a_credito" :total-pagado="total_payments" :monto-a-financiar="montoAFinanciar()"
                    :tiene-cobros-formales="tiene_cobros_formales" :condicion-especial="condicion_especial"
                    @cambio="sumDetails" />
            </div>
            <div class="p-3">
                <button type="button" class="btn btn-success fw-bold py-2 w-100" @click="store()">
                    <i class="fas fa-check-circle me-2"></i>Guardar cambios
                </button>
            </div>
        </div>
            </div>
        </div>

        <AvisoVenta ref="aviso" />

        <!-- MODALES clientes y productos -->
        <Teleport to="body">
            <b-modal v-model="showQuickClientModal" title="Registrar Cliente Rápido" hide-footer centered size="lg"
                @hidden="showQuickClientModal = false">
                <ClientFormQuick :initial-data="quickClientData" @saved="onClientCreated"
                    @cancel="showQuickClientModal = false" />
            </b-modal>
        </Teleport>

        <Teleport to="body">
            <b-modal v-model="showQuickProductModal" title="Registrar Producto Rápido" hide-footer centered size="lg"
                @hidden="showQuickProductModal = false">
                <ProductFormQuick :initial-data="quickProductData" @saved="onProductCreated"
                    @cancel="showQuickProductModal = false" />
            </b-modal>
        </Teleport>

    </DefaultLayout>
</template>

<script setup lang="ts">

import { ref, watch, onMounted, computed, nextTick } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { AxiosResponse } from 'axios';
import httpClient from '@/helpers/http-client';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import type { SaleConfig, SaleResponse, SalePayment } from '@/types/sales';
import type { PaymentMethod, PaymentMethods } from '@/types/cash';
import type { Client } from '@/types/clients';
import type { Product } from '@/types/products';
import ClientFormQuick from '@/components/Sales/ClientFormQuick.vue';
import ProductFormQuick from '@/components/Sales/ProductFormQuick.vue';
import AvisoVenta from '@/components/Sales/AvisoVenta.vue';
import CobroEdicion from '@/components/Sales/CobroEdicion.vue';
import BuscadorProductosVenta from '@/components/Sales/BuscadorProductosVenta.vue';
import PanelClienteComprobante from '@/components/Sales/PanelClienteComprobante.vue';
import DatosVentaPanel from '@/components/Sales/DatosVentaPanel.vue';
import TotalesVenta from '@/components/Sales/TotalesVenta.vue';
import TablaLineasVenta, { type LineaVenta } from '@/components/Sales/TablaLineasVenta.vue';
import { imprimirComprobante } from '@/composables/usePrintComprobante';
import { getPrecioBaseSinIgv } from '@/composables/useProductPricing';
import { useAuthStore } from '@/stores/auth';
import { resolverTipAfeIgv as resolverTipAfeIgvPuro } from '@/utils/resolverTipAfeIgv';
import { cantidadEnVenta, faltaStockEnEdicion, precioCatalogo } from '@/helpers/ventaRetail';
import { formatFecha } from '@/helpers/fecha';
import type { Branch } from '@/types/cash-session';
import type { TipoComprobante } from '@/types/series-comprobante';

const authStore = useAuthStore();

// Mismo mapa que register.vue/SaleController::PERMISOS_EMISION — ver ese
// archivo para el porqué de excluir '07'/'08' (NC/ND, flujo de Notas aparte).
const PERMISOS_EMISION: Record<string, string> = {
    '01': 'emitir_factura',
    '03': 'emitir_boleta',
    'NV': 'emitir_nota_venta',
};


type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const route = useRoute();
const router = useRouter();

const sale_id = ref<string>(route.params.id as string);

// ── Estado de la pantalla (T-110, igual que register.vue) ───────────
const datosAbiertos = ref<boolean>(false); // panel «Datos de la venta»
const aviso = ref<InstanceType<typeof AvisoVenta> | null>(null);
let siguienteUid = 1; // identifica cada línea en pantalla; no viaja en el payload
const avisar = (texto: string, acciones: { texto: string; fn: () => void }[] = [], ms = 6000) =>
    aviso.value?.mostrar(texto, acciones, ms);
//const sale_id_edit = ref<string | string[]>("");//id de la venta a edit


// ── Datos maestros ──────────────────────────────────────────────────
const client_selected = ref<Client | undefined>(undefined);
const client_address_id = ref<number | null>(null); // T-107: null = dirección fiscal

// ── Modales rápidos ────────────────────────────────────────────────
const showQuickClientModal = ref<boolean>(false);
const quickClientData = ref<any>(null);
const showQuickProductModal = ref<boolean>(false);
const quickProductData = ref<any>(null);

// ── Datos de la transacción ────────────────────────────────────────
const is_exportacion = ref<number>(0);
const n_transaction = ref<string>('');
const today = ref<string>('');
// Módulo de series de comprobantes — reemplaza el 'serie' de texto libre de
// antes (ver register.vue para el detalle del diseño). yaReservoCorrelativo
// espeja el guard real de SaleController::update() (resolverSerieComprobanteUpdate):
// una vez que la venta tiene un correlativo (enviada a SUNAT o nota de venta
// ya numerada), el tipo de documento/sucursal quedan bloqueados.
const tipo_comprobante_codigo = ref<string>('03');
const tiposComprobante = ref<TipoComprobante[]>([]);
const branches = ref<Branch[]>([]);
const branch_id_seleccionado = ref<number | undefined>(undefined);
const yaReservoCorrelativo = ref<boolean>(false);

const puedeCambiarSucursal = computed(() => authStore.isPermitedRoute('can_switch_branch'));

const tiposComprobanteVisibles = computed(() =>
    tiposComprobante.value.filter((t) => {
        const permiso = PERMISOS_EMISION[t.codigo];
        return permiso ? authStore.isPermitedRoute(permiso) : false;
    })
);

const tipoEsFiscal = computed(() => {
    const tipo = tiposComprobante.value.find((t) => t.codigo === tipo_comprobante_codigo.value);
    return tipo ? tipo.es_documento_sunat : true;
});

// Serie ya persistida (venta con correlativo reservado, no editable) vs.
// preview en vivo (venta aún editable) — mismo criterio que register.vue.
const serieActual = ref<string>('');
const serieResuelta = ref<{ serie: string; siguiente_correlativo: number } | null>(null);
const errorSeriePreview = ref<string>('');
let previewSerieTimeout: any = null;

const previsualizarSerie = async () => {
    if (yaReservoCorrelativo.value || !tipo_comprobante_codigo.value) {
        serieResuelta.value = null;
        return;
    }

    try {
        const params = new URLSearchParams();
        params.set('tipo_comprobante_codigo', tipo_comprobante_codigo.value);
        params.set('currency', currency.value);
        if (puedeCambiarSucursal.value && branch_id_seleccionado.value) {
            params.set('branch_id', String(branch_id_seleccionado.value));
        }

        const res = await httpClient.get<{ serie: string; siguiente_correlativo: number }>(
            `sales/serie-preview?${params.toString()}`
        );
        serieResuelta.value = res.data;
        errorSeriePreview.value = '';
    } catch (error: any) {
        serieResuelta.value = null;
        errorSeriePreview.value = error.response?.data?.message ?? 'No se pudo resolver la serie para este tipo de documento.';
    }
};

const destino = ref<string>('amazonia');
const currency = ref<string>('PEN');

// Bloquea los watchers de recálculo tributario mientras los datos se
// asignan de forma programática (carga inicial de la venta, o reversión
// de un cambio de destino que el usuario canceló en el diálogo de aviso)
const isHydrating = ref(false);

// ── Condiciones especiales ─────────────────────────────────────────
const condicion_especial = ref<string>('0');
const n_comprobante_anticipo = ref<string>('');
const sale_anticipo = ref<number>(0);
const codigo_detraccion_sel = ref<string>('');
const porcentaje_detraccion_sel = ref<number>(0);
const monto_detraccion = ref<number>(0);
const porcentaje_retencion_sel = ref<number>(0.03);
const monto_retencion = ref<number>(0);
const porcentaje_percepcion_sel = ref<number>(0.02);
const monto_percepcion = ref<number>(0);

// ── Parámetros tributarios ─────────────────────────────────────────
const parametros_tributarios = ref<Record<string, any[]>>({});
const codigos_detraccion = ref<any[]>([]);

const getIgvGeneralTasa = (): number => {
    const igv_default = parametros_tributarios.value['IGV']?.find((t: any) => t.es_default);
    return Number(igv_default?.tasa_porcentaje ?? 18);
};
const getIvapTasa = (): number =>
    Number(parametros_tributarios.value['IVAP']?.[0]?.tasa_porcentaje ?? 4);
const getIcbperMonto = (): number =>
    Number(parametros_tributarios.value['ICBPER']?.[0]?.tasa_porcentaje ?? 0.50);
const getRetencionTasa = (): number =>
    Number(parametros_tributarios.value['RETENCION']?.[0]?.tasa_porcentaje ?? 3) / 100;
const getPercepcionTasa = (): number =>
    Number(parametros_tributarios.value['PERCEPCION']?.[0]?.tasa_porcentaje ?? 2) / 100;

// ── Detalle y pagos ────────────────────────────────────────────────
const sale_details = ref<LineaVenta[]>([]);
// T-113: cantidad por producto que la venta tenía al abrirla (ya descontada del stock).
const cantidadOriginal = ref<Record<string, number>>({});
const discount_total = ref<number>(0);
const discount_global = ref<number>(0);
const description = ref<string>('');

// Pagos
const type_payment = ref<number>(1);
const sale_payments = ref<SalePayment[]>([]);

// Módulo Caja — Fase 0 (plan-modulo-caja.md §3): picker dinámico en vez del
// <option> hardcodeado. method_payment sigue enviándose igual que antes.
const paymentMethods = ref<PaymentMethod[]>([]);

// ── Configuración de Crédito (edición previa a envío a SUNAT) ──────
// Solo credit_type='cuotas_fijas', mismo alcance que register.vue.
// Cualquier edición de una venta a crédito exige regenerar el
// cronograma completo (ver SaleController::update()) — no se prellena
// con las cuotas viejas, se fuerza "Generar cronograma sugerido" antes
// de guardar, igual que en register.vue.
const credit_type = ref<string>('cuotas_fijas');
const num_cuotas = ref<number>(3);
const periodicidad = ref<string>('mensual');
const cronograma = ref<{ numero_cuota: number; monto_programado: number; fecha_vencimiento: string }[]>([]);
const aplica_mora = ref<boolean>(false);
const tasa_mora = ref<number>(0);
const tipo_mora = ref<string>('porcentaje_diario');
// true si la venta ya tiene cuotas cobradas o un adelanto aplicado —
// bloquea la edición de productos/pago inicial (SaleResource::tiene_cobros_formales).
const tiene_cobros_formales = ref<boolean>(false);
// T-116: condición con la que la venta está guardada (no la elegida en pantalla). Si es crédito,
// sale_payments rechaza POST/DELETE y los pagos se guardan con «Guardar cambios».
const guardada_a_credito = ref<boolean>(false);

// Totales
const igv_total = ref<number>(0);
const sale_total = ref<number>(0);
const sale_subtotal = ref<number>(0);
const icbper_total = ref<number>(0);
const isc_total = ref<number>(0);
const total_payments = ref<number>(0);
// Subtotal bruto de las líneas cobrables (excluye gratuitas) — lo calcula
// sumDetails() y lo reutiliza store() para netear mto_oper_* del descuento
// global con la misma proporción que ya usa el IGV.
const subtotalBrutoCobro = ref<number>(0);


// ── Cliente ───────────────────────────────────────────────────────
// La búsqueda vive en PanelClienteComprobante.vue; acá solo lo que pasa al elegir o quitar un cliente.
const selectClient = (client: Client) => {
    client_selected.value = client;

    // ── Autocompletar tipo de documento según tipo de documento del cliente ──
    // Nunca si la venta ya tiene un correlativo reservado (yaReservoCorrelativo)
    // — cambiar de cliente en una venta ya numerada no debe arrastrar consigo
    // un cambio de tipo de documento bloqueado.
    if (!yaReservoCorrelativo.value) {
        const tipoSugerido = client.type_document === 'RUC' ? '01' : '03';
        if (tiposComprobanteVisibles.value.some((t) => t.codigo === tipoSugerido)) {
            tipo_comprobante_codigo.value = tipoSugerido;
        }
    }

    // ── Sugerir destino según zona del cliente ─────────────────────
    // Si el cliente es de zona Amazonía, pre-seleccionar 'amazonia'
    // El vendedor puede cambiarlo si el pedido se envía fuera
    if (client.es_amazonia) {
        destino.value = 'amazonia';
    }
    // Si no es Amazonía, no cambiar automáticamente — puede ser
    // un cliente de Lima que compra acá o uno local que envía fuera

    recalcularPreciosPorCliente();
};
const clearClientSelection = () => {
    client_selected.value = undefined;
    if (!yaReservoCorrelativo.value) {
        tipo_comprobante_codigo.value = '03'; // resetear a boleta por defecto
    }
    recalcularPreciosPorCliente();
};

// ── Resolver tip_afe_igv según las reglas de negocio ─────────────
// Lógica pura extraída a src/utils/resolverTipAfeIgv.ts (testeada ahí
// con Vitest) — acá solo se cierra sobre los refs de esta vista.
const resolverTipAfeIgv = (product: Product): string =>
    resolverTipAfeIgvPuro(product, destino.value, is_exportacion.value);

// ── Recalcula tip_afe_igv y sus montos dependientes en una sola operación ──
// Nunca deben actualizarse por separado: si tip_afe_igv cambia sin recalcular
// igv/mto_base_igv/mto_valor_venta/subtotal (o viceversa), los totales quedan
// descuadrados frente a lo que se envía a SUNAT.
const recalcularItemTipAfeIgv = (item: LineaVenta, index: number) => {
    item.tip_afe_igv = resolverTipAfeIgv(item.product);
    updateItem(index);
};

// ── T-115: precio mayorista por cantidad ─────────────────────────
// Las líneas NUEVAS (sin id) y sin precio a mano se recalculan al cambiar la cantidad de cualquier línea
// del producto, porque el precio mayorista cuenta la suma. Las líneas ya guardadas conservan su precio:
// PrecioVentaValidator las acepta mientras no cambien de precio. Si no se recalculara, una línea nueva que
// sube a ≥ N quedaría al general y el backend la rechazaría sin el permiso de precio.
const recalcularPreciosNuevos = (): number => {
    let cambiadas = 0;
    sale_details.value.forEach((d, i) => {
        if (d.id || d.precio_manual) return;
        const bruto = precioCatalogo(d.product, client_selected.value, cantidadEnVenta(sale_details.value, d.product.id)).valor;
        const nuevo = Number(getPrecioBaseSinIgv(bruto, d.product, { igvGeneral: getIgvGeneralTasa(), ivap: getIvapTasa() }));
        if (Math.abs(nuevo - Number(d.price_base)) > 0.0001) {
            d.price_base = nuevo;
            updateItem(i);
            cambiadas++;
        }
    });
    return cambiadas;
};
// T-110: al cambiar de cliente las líneas NUEVAS sin precio a mano se reprecian (las guardadas conservan su precio).
const recalcularPreciosPorCliente = () => {
    const cambiadas = recalcularPreciosNuevos();
    if (cambiadas) avisar(`Se actualizó el precio de ${cambiadas} producto${cambiadas > 1 ? 's' : ''} según el cliente.`);
};
// La tabla avisa que una línea cambió (cantidad, precio, descuento o afectación).
const alCambiarLinea = (index: number) => {
    updateItem(index);
    recalcularPreciosNuevos();
};

// Precio elegido con el ▾ de la línea (General / Mayorista): queda como precio puesto a mano.
const elegirPrecioLinea = (i: number, nombre: 'General' | 'Mayorista') => {
    const d = sale_details.value[i];
    if (!d) return;
    const bruto = nombre === 'Mayorista' ? Number(d.product.price_company) : Number(d.product.price_general);
    d.price_base = Number(getPrecioBaseSinIgv(bruto || 0, d.product, { igvGeneral: getIgvGeneralTasa(), ivap: getIvapTasa() }));
    d.precio_manual = true;
    updateItem(i);
};

// ── Agregar un producto a la venta ─────────────────────────────────
const lineaResaltada = ref<number | null>(null);
const buscador = ref<InstanceType<typeof BuscadorProductosVenta> | null>(null);
// T-109: sin este permiso el precio de cada línea es el del sistema (el backend lo valida igual).
const puedeCambiarPrecio = computed(() => authStore.isPermitedRoute('cambiar_precio_venta'));

const agregarProducto = (product: Product) => {
    // T-113: mismo criterio que el backend (controlaStock) y lo disponible incluye lo que esta
    // venta ya tenía descontado de ese producto (el backend valida solo la diferencia).
    const pedido = cantidadEnVenta(sale_details.value, product.id) + 1;
    const disponible = faltaStockEnEdicion(product, pedido, cantidadOriginal.value[String(product.id)] ?? 0);
    if (disponible !== null) {
        avisar(disponible <= 0
            ? `No hay stock disponible de «${product.title}».`
            : `Solo hay ${disponible} unidades para esta venta de «${product.title}».`);
        return;
    }

    // Si el producto ya está en la lista (y esa línea no tiene descripción editada, T-108), se incrementa la cantidad.
    let indice = sale_details.value.findIndex(d => d.product.id === product.id && !d.descripcion_linea?.trim());
    if (indice >= 0) {
        sale_details.value[indice].quantity = Number(sale_details.value[indice].quantity) + 1;
        updateItem(indice);
        recalcularPreciosNuevos();
    } else {
        // T-115: mayorista por cliente o por cantidad (la suma incluye la línea nueva), no por type_client.
        const rawPrice = precioCatalogo(product, client_selected.value, cantidadEnVenta(sale_details.value, product.id) + 1).valor;
        // El precio crudo del catálogo puede venir CON IGV incluido (product.include_igv) — hay que revertir
        // ese cálculo para obtener el P.BASE real, igual que hace el registro de producto.
        const price = getPrecioBaseSinIgv(rawPrice, product, { igvGeneral: getIgvGeneralTasa(), ivap: getIvapTasa() });

        sale_details.value.push({
            uid: siguienteUid++,
            product: product,
            unidad_medida: product.unidad_medida || 'NIU',
            price_base: Number(price),
            quantity: 1,
            discount_percent: 0,
            tip_afe_igv: resolverTipAfeIgv(product),
            subtotal: 0,
            igv: 0,
            isc: 0,
            icbper: 0,
            discount: 0,
            price_final: 0,
            mto_valor_venta: 0,
            mto_base_igv: 0,
            porcentaje_igv: 0,
            total_impuestos: 0,
            tipo_isc: product.tipo_isc ?? '01',
            monto_isc_fijo: product.monto_isc_fijo ?? 0,
            contenido_neto_litros: product.contenido_neto_litros ?? undefined,
            per_icbper: 0,
        });
        indice = sale_details.value.length - 1;
        updateItem(indice);
        recalcularPreciosNuevos();
        sumDetails();
    }

    lineaResaltada.value = null;
    nextTick(() => {
        lineaResaltada.value = indice;
        setTimeout(() => { if (lineaResaltada.value === indice) lineaResaltada.value = null; }, 900);
    });
};

// ── ACTUALIZAR ÍTEM ──────────────────────────────────────────────────
const updateItem = (index: number) => {
    const item = sale_details.value[index];
    if (!item) return;

    const qty = Number(item.quantity) || 0;
    const price_base = Number(item.price_base) || 0;
    const disc_pct = Number(item.discount_percent) || 0;

    // mto_valor_venta = precio_base × cantidad (ANTES del descuento)
    // Greenter: SaleDetail→setMtoValorVenta()
    const mto_valor_venta = qty * price_base;

    // Descuento de la línea en monto
    const descuento_linea = mto_valor_venta * (disc_pct / 100);

    // mto_base_igv = base neta sobre la que se calcula el IGV
    // = mto_valor_venta - descuento_linea
    // Greenter: SaleDetail→setMtoBaseIgv()
    const mto_base_igv = mto_valor_venta - descuento_linea;

    // ISC según régimen — solo si el producto tiene is_isc=true.
    // No basta con mirar percentage_isc/monto_isc_fijo > 0: un producto
    // puede tener datos residuales (bug conocido) sin estar afecto a ISC.
    let isc = 0;
    if (item.product.is_isc && (item.product.percentage_isc > 0 || item.monto_isc_fijo > 0)) {
        const tipo_isc = item.tipo_isc ?? '01';
        switch (tipo_isc) {
            case '01': // Al valor (% sobre base)
                isc = mto_base_igv * (item.product.percentage_isc / 100);
                break;
            case '02': // Específico (monto fijo — por litro si el producto
                       // tiene contenido_neto_litros, si no, directo por unidad)
                // PENDIENTE: contenido_neto_litros solo cubre productos líquidos.
                // Cigarrillos/combustibles (también Específico) necesitan un
                // factor de contenido no-líquido — ver nota en product/register.vue.
                isc = qty * (item.contenido_neto_litros || 1) * (item.monto_isc_fijo || 0);
                break;
            case '03': // Al valor según PVP
                isc = (price_base * qty) * (item.product.percentage_isc / 100);
                break;
        }
    }

    // Tasa de IGV según tip_afe_igv — lee de tax_params, no hardcode
    let tasa_igv = 0;
    let porcentaje_igv = 0;
    if (['10', '11'].includes(item.tip_afe_igv)) {
        tasa_igv = getIgvGeneralTasa();
        porcentaje_igv = tasa_igv;
    } else if (item.tip_afe_igv === '17') {
        tasa_igv = getIvapTasa();
        porcentaje_igv = tasa_igv;
    }
    // tip_afe_igv '20', '30', '40' → tasa 0

    // IGV se aplica sobre (mto_base_igv + ISC)
    const igv = (mto_base_igv + isc) * (tasa_igv / 100);

    // ICBPER — monto por unidad desde tax_params
    let icbper = 0;
    let per_icbper = 0;
    if (item.product.is_icbper === 2) {
        per_icbper = getIcbperMonto();   // S/ 0.50 por bolsa (desde BD)
        icbper = qty * per_icbper;
    }

    // Precio final unitario con IGV incluido
    const price_final = qty > 0
        ? (mto_base_igv + igv + isc + icbper) / qty
        : 0;

    // Total de impuestos de la línea = IGV + ISC + ICBPER
    const total_impuestos = igv + isc + icbper;

    // Guardar en el ítem
    item.mto_valor_venta = Number(mto_valor_venta.toFixed(6));
    item.mto_base_igv = Number(mto_base_igv.toFixed(6));
    item.porcentaje_igv = porcentaje_igv;
    item.subtotal = Number(mto_base_igv.toFixed(4));   // base neta = subtotal
    item.discount = Number(descuento_linea.toFixed(4));
    item.isc = Number(isc.toFixed(4));
    item.igv = Number(igv.toFixed(4));
    item.icbper = Number(icbper.toFixed(4));
    item.per_icbper = per_icbper;
    item.price_final = Number(price_final.toFixed(4));
    item.total_impuestos = Number(total_impuestos.toFixed(4));

    sumDetails();
};

const removeItem = (index: number) => {
    sale_details.value.splice(index, 1);
    recalcularPreciosNuevos();
    sumDetails();
};

// ── Cálculo de totales ────────────────────────────────────────────
const getSubTotalSale = (): number => {
    const descuento = Number(discount_global.value) || 0;
    return Number((sale_subtotal.value - descuento).toFixed(4));
};

const getIgvTotal = (): number => {
    // igv_total ya viene recalculado en sumDetails() sobre la base
    // neta del descuento global (prorrateado por línea)
    return Number(igv_total.value.toFixed(4));
};

const getTotalFactura = (): number => {
    return getSubTotalSale() + getIgvTotal() + icbper_total.value + isc_total.value;
};

const getTotalSales = (): number => sale_total.value;

const getBaseForRegimen = (): number => getTotalFactura();

// ── Subtotal de línea neto del descuento global (prorrateado) ────────
// Único punto de cálculo para "subtotal de línea ya con el descuento
// global aplicado" — lo usan tanto el IGV (sumDetails()) como los
// mto_oper_* que se mandan a SUNAT (store()). Antes cada uno lo
// calculaba por separado y solo el IGV restaba el descuento; ese
// desfase entre el total (neto) y mto_oper_exoneradas/gravadas/etc.
// (brutos) causaba el error SUNAT 3275 en ventas con descuento global.
const subtotalNetoDescuentoGlobal = (d: LineaVenta, subtotalBruto: number): number => {
    const descuento = Number(discount_global.value) || 0;
    const proporcion = subtotalBruto > 0 ? d.subtotal / subtotalBruto : 0;
    return d.subtotal - (descuento * proporcion);
};

const sumDetails = () => {
    // Asegurar arrays

    const pagos = sale_payments.value || [];

    if (isNaN(discount_global.value) || discount_global.value === null) {
        discount_global.value = 0;
    }

    // Excluir operaciones gratuitas (tip_afe_igv = 11) de los totales
    const lineas_cobro = sale_details.value.filter(d => d.tip_afe_igv !== '11');

    // ── IGV recalculado sobre la base neta del descuento global ──────
    // El descuento global se prorratea por línea según su peso en el
    // subtotal bruto, y el IGV se recalcula sobre esa base ya descontada
    // respetando la tasa propia de cada línea — necesario porque una
    // misma venta puede mezclar líneas gravadas, exoneradas e IVAP.
    const subtotal_bruto = lineas_cobro.reduce((s, d) => s + d.subtotal, 0);
    subtotalBrutoCobro.value = subtotal_bruto;
    const descuento_global_actual = Number(discount_global.value) || 0;
    igv_total.value = Number(lineas_cobro.reduce((s, d) => {
        const base_neta = subtotalNetoDescuentoGlobal(d, subtotal_bruto);
        return s + (base_neta * (d.porcentaje_igv / 100));
    }, 0).toFixed(4));
    // ICBPER: monto fijo por unidad (bolsa), no depende del valor de venta
    // → nunca se prorratea con el descuento global. Confirmado con el usuario.
    icbper_total.value = Number(lineas_cobro.reduce((s, d) => s + d.icbper, 0).toFixed(4));

    // ISC: solo se prorratea con el descuento global si el régimen del
    // producto es porcentual sobre el valor de venta ('01' Al valor, '03'
    // Al valor según PVP) — mismo criterio que el IGV. El régimen '02'
    // (Específico, monto fijo x unidad) se comporta como ICBPER: no se
    // prorratea. PENDIENTE DE VALIDAR CON DATOS REALES: no hay productos
    // con ISC activo en pruebas todavía (ver conversación 2026-07-09).
    isc_total.value = Number(lineas_cobro.reduce((s, d) => {
        if (!d.product?.is_isc || d.tipo_isc === '02') {
            return s + d.isc;
        }
        const proporcion = subtotal_bruto > 0 ? d.subtotal / subtotal_bruto : 0;
        const descuento_linea = descuento_global_actual * proporcion;
        const percentage = d.product?.percentage_isc ?? 0;
        if (d.tipo_isc === '03') {
            return s + ((d.mto_valor_venta - descuento_linea) * (percentage / 100));
        }
        return s + ((d.subtotal - descuento_linea) * (percentage / 100)); // '01'
    }, 0).toFixed(4));
    sale_subtotal.value = Number(lineas_cobro.reduce((s, d) => s + d.subtotal, 0).toFixed(4));
    discount_total.value = Number(lineas_cobro.reduce((s, d) => s + d.discount, 0).toFixed(4));
    // Pagos — usar Number() en cada amount
    const totalPayments = pagos.reduce((sum, p) => sum + Number(p.amount || 0), 0);
    total_payments.value = Number(totalPayments.toFixed(2));

    // ── Calcular montos de regímenes especiales ───────────────────
    // Las tasas vienen de tax_params (BD), no hardcodeadas
    const base_regimen = getBaseForRegimen();

    monto_retencion.value = 0;
    monto_detraccion.value = 0;
    monto_percepcion.value = 0;

    // Actualizar tasas desde BD cada vez que se recalcula
    porcentaje_retencion_sel.value = getRetencionTasa();
    porcentaje_percepcion_sel.value = getPercepcionTasa();

    const cond = condicion_especial.value;
    if (cond === '1') {
        // Retención: solo aplica si supera el mínimo (S/ 700 por defecto)
        const minimo_retencion = Number(
            parametros_tributarios.value['RETENCION']?.[0]?.monto_minimo ?? 700
        );
        if (base_regimen > minimo_retencion) {
            monto_retencion.value = Number(
                (base_regimen * porcentaje_retencion_sel.value).toFixed(2)
            );
        }
    } else if (cond === '2' && porcentaje_detraccion_sel.value > 0) {
        // Detracción: solo aplica si tiene código seleccionado y supera el mínimo
        const codigo_sel = codigos_detraccion.value.find(d => d.codigo === codigo_detraccion_sel.value);
        const minimo_detraccion = Number(codigo_sel?.monto_minimo ?? 700);
        if (base_regimen > minimo_detraccion) {
            monto_detraccion.value = Number(
                (base_regimen * porcentaje_detraccion_sel.value).toFixed(2)
            );
        }
    } else if (cond === '3') {
        // Percepción
        const minimo_percepcion = Number(
            parametros_tributarios.value['PERCEPCION']?.[0]?.monto_minimo ?? 700
        );
        if (base_regimen > minimo_percepcion) {
            monto_percepcion.value = Number(
                (base_regimen * porcentaje_percepcion_sel.value).toFixed(2)
            );
        }
    }

    // Total final = base + percepción - retención - detracción
    sale_total.value = Number((
        base_regimen
        + monto_percepcion.value
        - monto_retencion.value
        - monto_detraccion.value
    ).toFixed(4));
};

// ── Cuando cambia el código de detracción ─────────────────────────
const onDetractionCodeChange = () => {
    const codigo_sel = codigos_detraccion.value
        .find(d => d.codigo === codigo_detraccion_sel.value);

    // hay que dividir entre 100 para obtener 0.12
    porcentaje_detraccion_sel.value = codigo_sel
        ? Number(codigo_sel.tasa_porcentaje) / 100
        : 0;

    sumDetails();
};

// ── Configuración de Crédito (edición) ────────────────────────────
// Mismo cálculo que register.vue::montoAFinanciar(), adaptado al
// mecanismo de anticipo que usa esta pantalla (sale_anticipo, no el
// módulo Adelantos de register.vue — edit.vue no tiene ese selector).
// T-116: el anticipo (comprobante previo) ya está pagado: los pagos, el estado de pago, la deuda y
// el cronograma se miden contra lo que queda por cobrar. Mismo criterio que el backend: sin
// comprobante no hay anticipo (SaleController::anticipoDeLaEdicion()).
const anticipoAplicado = (): number =>
    condicion_especial.value === 'anticipo' && n_comprobante_anticipo.value.trim() ? Number(sale_anticipo.value) || 0 : 0;

const totalACobrar = (): number => Math.max(0, Number((getTotalSales() - anticipoAplicado()).toFixed(2)));

const montoAFinanciar = (): number => Math.max(0, totalACobrar() - total_payments.value);

const sumaCronograma = (): number =>
    cronograma.value.reduce((s, c) => s + (Number(c.monto_programado) || 0), 0);

// ── Guardar venta ─────────────────────────────────────────────────
const store = async () => {
    if (!client_selected.value) {
        (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Selecciona un cliente.' }); return;
    }
    if (sale_details.value.length === 0) {
        (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Agrega al menos un producto.' }); return;
    }
    // T-116: igual que register.vue, a crédito no hace falta pago inicial (el cronograma financia todo).
    if (sale_payments.value.length === 0 && getTotalSales() > 0 && type_payment.value != 2) {
        (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Agrega al menos un pago para la venta.' }); return;
    }
    // Re-validar acá (no solo en addPayment()): el total pudo bajar después
    // de agregar un pago (se quitó una línea, cambió un descuento/régimen),
    // dejando un pago ya agregado por encima del total sin que nada lo avise.
    if (total_payments.value > totalACobrar() + 0.009) {
        (Swal as TVueSwalInstance).fire({
            icon: 'error', title: 'Error',
            text: `Los pagos (${currency.value} ${total_payments.value.toFixed(2)}) superan lo que queda por cobrar ` +
                `(${currency.value} ${totalACobrar().toFixed(2)}${anticipoAplicado() > 0 ? ', ya restado el anticipo' : ''}). ` +
                'Ajusta los pagos antes de guardar.',
        }); return;
    }
    if (condicion_especial.value === '2' && !codigo_detraccion_sel.value) {
        (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Selecciona el código de detracción.' }); return;
    }

    // ── Validación de Configuración de Crédito ────────────────────────
    // Espeja la validación de validarConfiguracionCredito() en el backend
    // (SaleController.php) para dar feedback inmediato.
    if (type_payment.value == 2) {
        if (tiene_cobros_formales.value) {
            (Swal as TVueSwalInstance).fire({
                icon: 'error', title: 'Error',
                text: 'Esta venta ya tiene cuotas cobradas y/o un adelanto aplicado — no se puede editar ' +
                    'desde aquí. Anula el cobro primero desde Cuentas por Cobrar o Adelantos.',
            }); return;
        }
        if (credit_type.value !== 'cuotas_fijas') {
            (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Solo se soporta crédito con cuotas fijas por ahora.' }); return;
        }
        if (cronograma.value.length === 0) {
            (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Genera el cronograma de cuotas antes de guardar.' }); return;
        }
        if (Math.abs(sumaCronograma() - montoAFinanciar()) > 0.01) {
            (Swal as TVueSwalInstance).fire({
                icon: 'error', title: 'Error',
                text: `La suma del cronograma (${currency.value} ${sumaCronograma().toFixed(2)}) no calza con el monto ` +
                    `a financiar (${currency.value} ${montoAFinanciar().toFixed(2)}).`,
            }); return;
        }
        if (aplica_mora.value && (!tasa_mora.value || tasa_mora.value <= 0)) {
            (Swal as TVueSwalInstance).fire({ icon: 'error', title: 'Error', text: 'Si aplica mora, la tasa debe ser mayor a 0.' }); return;
        }
    }

    // Estado de pago derivado automáticamente
    let estado_pago = 1; // pendiente
    if (Math.abs(total_payments.value - totalACobrar()) < 0.01) estado_pago = 3;      // pagado completo
    else if (total_payments.value > 0) estado_pago = 2;      // pago parcial

    // Mapear condicion_especial → retencion_igv (campo de la BD)
    const retencion_igv_bd = condicion_especial.value === 'anticipo' ? 0
        : ['1', '2', '3'].includes(condicion_especial.value) ? Number(condicion_especial.value)
            : 0;

    const data = {
        // Identificación
        tipo_comprobante_codigo: tipo_comprobante_codigo.value,
        ...(puedeCambiarSucursal.value && branch_id_seleccionado.value
            ? { branch_id: branch_id_seleccionado.value }
            : {}),
        n_transaction: n_transaction.value,
        date: today.value,

        // Cliente
        client_id: client_selected.value.id,
        client_address_id: client_address_id.value,
        type_client: client_selected.value.type_client,
        // Tipo de documento según Catálogo 06 SUNAT — ya calculado en el cliente
        cod_tipo_doc_cliente: client_selected.value.cod_tipo_doc_sunat ?? '1',

        // Configuración de la operación
        currency: currency.value,         // 'PEN' o 'USD' (código ISO)
        is_exportacion: is_exportacion.value,
        destino: destino.value,          // 'amazonia' o 'nacional'
        type_payment: type_payment.value,

        // Regímenes especiales
        retencion_igv: retencion_igv_bd,
        codigo_detraccion: codigo_detraccion_sel.value || null,
        porcentaje_detraccion: porcentaje_detraccion_sel.value,
        monto_detraccion: monto_detraccion.value,
        porcentaje_percepcion: porcentaje_percepcion_sel.value,
        monto_percepcion: monto_percepcion.value,
        porcentaje_retencion: porcentaje_retencion_sel.value,
        monto_retencion: monto_retencion.value,

        // Anticipo
        n_comprobante_anticipo: condicion_especial.value === 'anticipo' ? n_comprobante_anticipo.value : null,
        amount_anticipo: sale_anticipo.value,

        // Totales calculados (el backend no recalcula — usa estos valores)
        subtotal: sale_subtotal.value,
        igv: igv_total.value,
        total: sale_total.value,
        discount: discount_total.value,
        discount_global: discount_global.value,
        igv_discount_general: 0,  // deprecated — mantener por compatibilidad

        // Totales por tipo de operación para Greenter — netos del descuento
        // global (prorrateado, ver subtotalNetoDescuentoGlobal()). SUNAT
        // rechaza (error 3275) si estos no cuadran con el total, que ya
        // sale neto del descuento (ver getSubTotalSale()).
        mto_oper_gravadas: sale_details.value.filter(d => d.tip_afe_igv === '10')
            .reduce((s, d) => s + subtotalNetoDescuentoGlobal(d, subtotalBrutoCobro.value), 0),
        mto_oper_exoneradas: sale_details.value.filter(d => d.tip_afe_igv === '20')
            .reduce((s, d) => s + subtotalNetoDescuentoGlobal(d, subtotalBrutoCobro.value), 0),
        mto_oper_inafectas: sale_details.value.filter(d => d.tip_afe_igv === '30')
            .reduce((s, d) => s + subtotalNetoDescuentoGlobal(d, subtotalBrutoCobro.value), 0),
        mto_oper_exportacion: sale_details.value.filter(d => d.tip_afe_igv === '40')
            .reduce((s, d) => s + subtotalNetoDescuentoGlobal(d, subtotalBrutoCobro.value), 0),
        // Gratuitas quedan brutas — no llevan descuento, están excluidas de
        // lineas_cobro/subtotal_bruto en sumDetails().
        mto_oper_gratuitas: sale_details.value.filter(d => d.tip_afe_igv === '11').reduce((s, d) => s + d.subtotal, 0),

        // Impuestos desagregados
        isc_total: isc_total.value,
        icbper_total: icbper_total.value,
        ivap_total: sale_details.value.filter(d => d.tip_afe_igv === '17').reduce((s, d) => s + d.igv, 0),
        total_impuestos: sale_details.value.reduce((s, d) => s + d.total_impuestos, 0),
        valor_venta: getSubTotalSale(),
        mto_imp_venta: Math.round(getTotalFactura() * 10) / 10,
        redondeo: Math.round((Math.round(getTotalFactura() * 10) / 10 - getTotalFactura()) * 100) / 100,

        // Estado y deuda
        state_payment: estado_pago,
        // Neto del anticipo: el backend valida el cronograma contra este monto.
        debt: Math.max(0, Number((totalACobrar() - total_payments.value).toFixed(2))),
        paid_out: total_payments.value,

        // Detalles — incluyen todos los campos para Greenter
        // (sin precio_manual, uid ni precio_guardado: son solo de pantalla)
        sale_details: sale_details.value.map(({ precio_manual, uid, precio_guardado, ...linea }) => linea),

        // Pagos
        payments: sale_payments.value,

        description: description.value,

        // Configuración de Crédito — solo se manda si es venta a crédito,
        // mismo criterio que el backend usa para derivar condicion_pago.
        ...(type_payment.value == 2 ? {
            credit_type: credit_type.value,
            cronograma: cronograma.value,
            aplica_mora: aplica_mora.value,
            tasa_mora: aplica_mora.value ? tasa_mora.value : null,
            tipo_mora: aplica_mora.value ? tipo_mora.value : null,
        } : {}),
    };

    try {
        const resp: AxiosResponse<SaleResponse> = await httpClient.patch(`sales/${sale_id.value}`, data);

        if (resp.data.error) {
            (Swal as TVueSwalInstance).fire('Error interno', resp.data.error.message, 'error');
            return;
        }
        if (resp.data.code === 200) {
            await (Swal as TVueSwalInstance).fire('¡Listo!', resp.data.message, 'success');
            const formatoDefault = useAuthStore().getUser()?.formato_impresion_default ?? 'a4';
            await imprimirComprobante(sale_id.value, formatoDefault);
            router.push({ name: 'sale.list' });
            return;
        }
        // T-116: update() responde HTTP 200 con code 405 si la venta ya se envió a SUNAT.
        (Swal as TVueSwalInstance).fire('No se guardó', String(resp.data.message ?? 'No se pudo guardar la venta.'), 'warning');
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire('Error', e.response?.data?.message ?? 'Error inesperado', 'error');
    }
};

// T-116: qué precio de catálogo tiene una línea guardada, comparando su propio precio sin IGV
// (misma tolerancia que PrecioVentaValidator). Si no coincide con ninguno: «Precio guardado».
const precioGuardado = (d: any): 'General' | 'Mayorista' | 'Otro' | undefined => {
    if (!d.product) return undefined;
    const tasas = { igvGeneral: getIgvGeneralTasa(), ivap: getIvapTasa() };
    const precio = Number(d.price_base ?? 0);
    const igual = (bruto: number) => bruto > 0 && Math.abs(getPrecioBaseSinIgv(bruto, d.product, tasas) - precio) <= 0.0001;
    if (igual(Number(d.product.price_general))) return 'General';
    if (igual(Number(d.product.price_company))) return 'Mayorista';
    return 'Otro';
};

// ── CARGAR DATOS DE LA VENTA ──────────────────────────────────────
const loadSale = async () => {
    // Evita que los watchers de destino/condicion_especial/is_exportacion
    // recalculen tip_afe_igv o descarten sale_details mientras hidratamos
    // los valores ya guardados en la API.
    isHydrating.value = true;
    try {
        const res: AxiosResponse<SaleResponse> = await httpClient.get(`sales/${sale_id.value}`);
        if (res.data.code === 405) {
            (Swal as TVueSwalInstance).fire('Error', res.data.message, 'error');
            return;
        }
        const sale = res.data.sale;
        if (!sale) return;

        // Asignar datos
        n_transaction.value = sale.n_transaction;
        today.value = sale.created_at_format || sale.date || '';
        currency.value = sale.currency || 'PEN';
        description.value = sale.description || '';
        discount_global.value = Number(sale.discount_global || 0);
        type_payment.value = sale.type_payment || 1;
        guardada_a_credito.value = Number(sale.type_payment) === 2;

        // Configuración de Crédito — la venta ya trae esto persistido
        // (SaleResource) si es a crédito; el cronograma en sí no se
        // prellena (se exige regenerar antes de guardar, ver store()).
        credit_type.value = sale.credit_type || 'cuotas_fijas';
        aplica_mora.value = Boolean(sale.aplica_mora);
        tasa_mora.value = Number(sale.tasa_mora || 0);
        tipo_mora.value = sale.tipo_mora || 'porcentaje_diario';
        tiene_cobros_formales.value = Boolean(sale.tiene_cobros_formales);

        // Cliente
        if (sale.client) {
            selectClient(sale.client);
        }
        // T-116: el destino guardado va DESPUÉS de selectClient(), que sugiere «amazonia» si el
        // cliente es de esa zona y pisaba el de una venta nacional.
        destino.value = sale.destino || 'nacional';

        // T-116: anticipo guardado en la venta (antes se abría vacío y se guardaba en 0).
        n_comprobante_anticipo.value = sale.n_comprobante_anticipo ?? '';
        sale_anticipo.value = Number(sale.amount_anticipo ?? 0);

        // T-116: la sucursal de la venta es la de su serie; antes arrancaba en la primera de la lista.
        if (puedeCambiarSucursal.value && res.data.branch_id) {
            branch_id_seleccionado.value = res.data.branch_id;
        }
        // T-107: se reenvía tal cual la dirección guardada (el backend la acepta aunque se haya desactivado).
        client_address_id.value = sale.client_address_id ?? null;

        // Módulo de series de comprobantes — hidratar DESPUÉS de
        // selectClient() para que el valor real persistido gane sobre
        // cualquier auto-sugerencia por tipo de cliente.
        yaReservoCorrelativo.value = sale.correlativo !== null && sale.correlativo !== undefined;
        serieActual.value = sale.serie ?? '';
        if (sale.tipo_comprobante_codigo) {
            tipo_comprobante_codigo.value = sale.tipo_comprobante_codigo;
        } else {
            // Venta creada antes de este módulo (sin backfill) — infiere del
            // prefijo de 'serie' solo para hidratar el selector visualmente,
            // igual criterio de compatibilidad que el backend
            // (FacturacionElectronicaController::enviarSunat()).
            tipo_comprobante_codigo.value = sale.serie?.startsWith('F') ? '01' : '03';
        }

        // Detalles
        if (sale.sale_details && sale.sale_details.length) {
            // Mapear para asegurar propiedades adicionales.
            // IMPORTANTE: los montos (tip_afe_igv, igv, mto_base_igv,
            // mto_valor_venta, subtotal, etc.) se toman TAL CUAL vienen de la
            // API — solo se convierten a Number por si el backend/Postgres
            // los serializa como string. No se recalculan aquí: recalcular
            // con las tasas/reglas vigentes hoy podría no coincidir con lo
            // que ya se envió a SUNAT en su momento.
            sale_details.value = sale.sale_details.map((d: any) => ({
                ...d,
                uid: siguienteUid++,
                discount_percent: Number(d.discount_percent ?? 0),
                monto_isc_fijo: d.product?.monto_isc_fijo || 0,
                tipo_isc: d.product?.tipo_isc || '01',
                per_icbper: Number(d.per_icbper ?? 0),
                total_impuestos: Number(d.total_impuestos ?? 0),
                quantity: Number(d.quantity ?? 0),
                price_base: Number(d.price_base ?? 0),
                mto_valor_venta: Number(d.mto_valor_venta ?? 0),
                mto_base_igv: Number(d.mto_base_igv ?? 0),
                porcentaje_igv: Number(d.porcentaje_igv ?? 0),
                subtotal: Number(d.subtotal ?? 0),
                discount: Number(d.discount ?? 0),
                isc: Number(d.isc ?? 0),
                igv: Number(d.igv ?? 0),
                icbper: Number(d.icbper ?? 0),
                price_final: Number(d.price_final ?? 0),
                precio_guardado: precioGuardado(d),
            }));
            cantidadOriginal.value = {};
            for (const d of sale.sale_details as any[]) {
                const id = String(d.product?.id ?? d.product_id ?? '');
                if (id) cantidadOriginal.value[id] = (cantidadOriginal.value[id] ?? 0) + Number(d.quantity ?? 0);
            }
        }

        // Pagos   
        // //convertir amount a number
        if (sale.sale_payments) {
            sale_payments.value = sale.sale_payments.map((p: any) => ({
                ...p,
                amount: Number(p.amount) || 0
            }));
        } else {
            sale_payments.value = [];
        }

        // 1. Primero asignar condicion_especial
        if (sale.retencion_igv == 1) condicion_especial.value = '1';
        else if (sale.retencion_igv == 2) condicion_especial.value = '2';
        else if (sale.retencion_igv == 3) condicion_especial.value = '3';
        else if (sale.is_exportacion == 1) condicion_especial.value = 'exportacion';
        else if (sale.n_comprobante_anticipo) condicion_especial.value = 'anticipo';

        else condicion_especial.value = '0';
        // 2. Luego cargar el código de detracción con su porcentaje
        if (sale.codigo_detraccion) {
            codigo_detraccion_sel.value = sale.codigo_detraccion;

            // Buscar el código en la lista y cargar su porcentaje
            const cod = codigos_detraccion.value
                .find(d => d.codigo === sale.codigo_detraccion);

            porcentaje_detraccion_sel.value = cod
                ? Number(cod.tasa_porcentaje) / 100
                : Number(sale.porcentaje_detraccion ?? 0); // fallback: usar lo guardado en la venta
        }

        // Recalcular totales finales
        sumDetails();
    } catch (error) {
        console.error(error);
    } finally {
        // Esperamos a que Vue procese los watchers en cola (destino,
        // condicion_especial, is_exportacion) mientras la bandera sigue
        // activa, y solo entonces la liberamos para cambios reales del usuario.
        await nextTick();
        isHydrating.value = false;
        previsualizarSerie();
    }
};


// ── Modales rápidos ───────────────────────────────────────────────
const openQuickClientModalWithData = (data: any) => {
    quickClientData.value = data;
    showQuickClientModal.value = true;
};
const onClientCreated = (client: Client) => {
    selectClient(client);
    showQuickClientModal.value = false;
};
const openQuickProductModal = (nombre = '') => {
    quickProductData.value = nombre ? { title: nombre } : null;
    showQuickProductModal.value = true;
};
const onProductCreated = (product: Product) => {
    agregarProducto(product);
    showQuickProductModal.value = false;
};

// ── CONFIGURACIÓN INICIAL ──────────────────────────────────────────
// ── CONFIGURACIÓN INICIAL ──────────────────────────────────────────
// Módulo Caja — Fase 0 (plan-modulo-caja.md §3). Endpoint propio (no
// sales/config) — este catálogo no es exclusivo de ventas.
const loadPaymentMethods = async () => {
    try {
        const res: AxiosResponse<PaymentMethods> = await httpClient.get('payment-methods?active=1');
        paymentMethods.value = res.data.payment_methods;
    } catch (error) {
        console.error(error);
    }
};

// ── Módulo de series de comprobantes ──────────────────────────────
const cargarTiposYSucursales = async () => {
    try {
        const resTipos = await httpClient.get<{ tipos_comprobante: TipoComprobante[] }>(
            'tipos-comprobante?disponibles_para_serie=1'
        );
        tiposComprobante.value = resTipos.data.tipos_comprobante;

        if (puedeCambiarSucursal.value) {
            const resBranches = await httpClient.get<{ branches: Branch[] }>('branches?active=1');
            branches.value = resBranches.data.branches;
            branch_id_seleccionado.value = branches.value[0]?.id;
        }
    } catch (error) {
        console.error(error);
    }
};

const config = async () => {
    try {
        const res: AxiosResponse<SaleConfig> = await httpClient.get('sales/config');
        // No asignamos n_transaction ni today porque ya vienen de la venta cargada
        parametros_tributarios.value = (res.data as any).parametros_tributarios ?? {};
        codigos_detraccion.value = (res.data as any).codigos_detraccion ?? [];

        porcentaje_retencion_sel.value = getRetencionTasa();
        porcentaje_percepcion_sel.value = getPercepcionTasa();

        await loadPaymentMethods();
        await cargarTiposYSucursales();

        // Cargar venta después de tener los catálogos
        await loadSale();
    } catch (error) {
        console.error(error);
    }
};


// ── Watchers ──────────────────────────────────────────────────────
watch(discount_global, () => sumDetails());

watch(condicion_especial, (valor) => {
    // Activar exportación si se seleccionó esa condición
    is_exportacion.value = valor === 'exportacion' ? 1 : 0;

    // Durante la carga inicial no se recalcula nada: is_exportacion ya
    // queda sincronizado arriba, pero los montos deben respetar lo guardado.
    if (isHydrating.value) return;

    // Recalcular todos los ítems porque el tip_afe_igv cambia
    sale_details.value.forEach((item, index) => {
        recalcularItemTipAfeIgv(item, index);
    });

    // Moneda: exportación siempre en USD por defecto
    // (el vendedor puede cambiarla si negoció en soles)
    if (valor === 'exportacion') {
        currency.value = 'USD';
    } else if (is_exportacion.value === 0) {
        currency.value = 'PEN';
    }

    sumDetails();
});


// ── Limpieza de campos fiscales al cambiar de tipo de documento ────
// Mismo principio que register.vue: regenerar en vez de conservar. Guardado
// por isHydrating — igual criterio que el resto de watchers de este
// archivo, para no destruir régimen especial/destino ya persistidos justo
// cuando loadSale() hidrata tipo_comprobante_codigo.
watch(tipo_comprobante_codigo, () => {
    if (isHydrating.value) return;

    condicion_especial.value = '0';
    destino.value = 'amazonia';
    is_exportacion.value = 0;
    codigo_detraccion_sel.value = '';
    porcentaje_detraccion_sel.value = 0;
    monto_detraccion.value = 0;
    monto_retencion.value = 0;
    monto_percepcion.value = 0;
});

// Preview en vivo de la serie — mismo debounce que register.vue, pero
// respeta isHydrating para no disparar mientras loadSale() todavía está
// asignando tipo_comprobante_codigo/currency desde la venta real.
watch([tipo_comprobante_codigo, currency, branch_id_seleccionado], () => {
    if (isHydrating.value) return;

    clearTimeout(previewSerieTimeout);
    previewSerieTimeout = setTimeout(previsualizarSerie, 200);
});

watch(type_payment, () => {
    // El formulario del pago nuevo se limpia dentro de CobroEdicion.vue.
    setTimeout(() => sumDetails(), 50);
});

watch(is_exportacion, (value) => {
    // Durante la carga inicial NO se debe vaciar sale_details: esto es lo
    // que hacía desaparecer los detalles de ventas de exportación al editar.
    if (isHydrating.value) return;

    // Código ISO para Greenter: 'PEN' = soles, 'USD' = dólares
    currency.value = value === 1 ? 'USD' : 'PEN';
    sale_details.value = [];
    discount_global.value = 0;
    sumDetails();
});

watch(destino, async (_nuevoValor, valorAnterior) => {
    if (isHydrating.value) return;

    // Si ya hay líneas cargadas, avisar antes de recalcular en vez de
    // hacerlo en silencio — cambiar el destino puede mover líneas dentro/
    // fuera de la exoneración Ley 27037 y alterar los totales de la venta.
    if (sale_details.value.length) {
        const confirmacion = await (Swal as TVueSwalInstance).fire({
            title: 'Cambiar destino de la venta',
            text: `Esto recalculará el IGV de ${sale_details.value.length} línea(s) ya agregada(s). ¿Deseas continuar?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Sí, recalcular',
            cancelButtonText: 'Cancelar',
        });

        if (!confirmacion.isConfirmed) {
            isHydrating.value = true;
            destino.value = valorAnterior;
            await nextTick();
            isHydrating.value = false;
            return;
        }
    }

    // Cuando cambia el destino, recalcular tip_afe_igv de todos los ítems
    // porque la exoneración por Ley 27037 depende del destino
    sale_details.value.forEach((item, index) => {
        recalcularItemTipAfeIgv(item, index);
    });
    sumDetails();
});


// ── Totales a mostrar ─────────────────────────────────────────────
const simbolo = computed(() => (currency.value === 'USD' ? 'US$' : 'S/'));
const dinero = (n: number) => `${simbolo.value} ${n.toFixed(2)}`;

// Filas del resumen: solo las que aplican (misma base que los mto_oper_* del payload, netas del descuento global).
const filasTotales = computed(() => {
    if (sale_details.value.length === 0) return [];
    const base = (...afectaciones: string[]) => sale_details.value
        .filter(d => afectaciones.includes(d.tip_afe_igv))
        .reduce((s, d) => s + subtotalNetoDescuentoGlobal(d, subtotalBrutoCobro.value), 0);
    const filas: { etiqueta: string; valor: string; clase?: string }[] = [];
    const desc = discount_total.value + (Number(discount_global.value) || 0);
    if (desc > 0) filas.push({ etiqueta: 'Descuentos', valor: `− ${dinero(desc)}`, clase: 'text-danger' });
    const g = base('10'), ivap = base('17'), ex = base('20'), ina = base('30'), exp = base('40'), gra = base('11');
    if (g > 0) filas.push({ etiqueta: 'Op. gravada', valor: dinero(g) });
    if (ivap > 0) filas.push({ etiqueta: 'Op. gravada IVAP', valor: dinero(ivap) });
    if (getIgvTotal() > 0) filas.push({ etiqueta: 'IGV', valor: dinero(getIgvTotal()) });
    if (ex > 0) filas.push({ etiqueta: 'Op. exonerada', valor: dinero(ex) });
    if (ina > 0) filas.push({ etiqueta: 'Op. inafecta', valor: dinero(ina) });
    if (exp > 0) filas.push({ etiqueta: 'Exportación', valor: dinero(exp) });
    if (gra > 0) filas.push({ etiqueta: 'Op. gratuita', valor: dinero(gra) });
    if (icbper_total.value > 0) filas.push({ etiqueta: 'ICBPER (bolsas)', valor: dinero(icbper_total.value) });
    if (isc_total.value > 0) filas.push({ etiqueta: 'ISC', valor: dinero(isc_total.value) });
    if (condicion_especial.value === '3') filas.push({ etiqueta: `(+) Percepción ${(porcentaje_percepcion_sel.value * 100).toFixed(1)}%`, valor: dinero(monto_percepcion.value), clase: 'text-info' });
    if (condicion_especial.value === '1') filas.push({ etiqueta: `(−) Retención ${(porcentaje_retencion_sel.value * 100).toFixed(1)}%`, valor: `− ${dinero(monto_retencion.value)}`, clase: 'text-danger' });
    if (condicion_especial.value === '2') filas.push({ etiqueta: `(−) Detracción ${(porcentaje_detraccion_sel.value * 100).toFixed(1)}% [${codigo_detraccion_sel.value}]`, valor: dinero(monto_detraccion.value), clase: 'text-warning' });
    if (anticipoAplicado() > 0) filas.push({ etiqueta: 'Anticipo (ya pagado)', valor: `− ${dinero(anticipoAplicado())}`, clase: 'text-success' });
    if (total_payments.value > 0) filas.push({ etiqueta: 'Total pagado', valor: dinero(total_payments.value), clase: 'text-success' });
    return filas;
});

// ── Cabecera con etiquetas de estado ───────────────────────────────
const nombreSucursal = computed(() => branches.value.find(b => b.id === branch_id_seleccionado.value)?.name ?? '');
const noHabituales = computed(() => (condicion_especial.value !== '0' ? 1 : 0) + (currency.value !== 'PEN' ? 1 : 0));
const etiquetaCondicion = computed(() => ({
    anticipo: 'Con adelanto', exportacion: 'Exportación', '1': 'Retención 3%', '2': 'Detracción', '3': 'Percepción',
} as Record<string, string>)[condicion_especial.value] ?? '');

const cerrarDatos = () => {
    datosAbiertos.value = false;
    buscador.value?.enfocar();
};

onMounted(() => config());
</script>

<style scoped>
.chip { display: inline-flex; align-items: center; gap: 4px; border: 1px solid var(--bs-border-color); background: var(--bs-body-bg, #fff);
    border-radius: 999px; padding: 3px 10px; font-size: .8rem; color: var(--bs-secondary-color); cursor: pointer; }
.chip b { color: var(--bs-body-color); font-weight: 600; }
.chip:hover { border-color: var(--bs-success); }
.chip-alerta { background: var(--bs-warning-bg-subtle); color: var(--bs-warning-text-emphasis); border-color: transparent; }

/* Pantalla de caja: productos a la izquierda, cobro a la derecha (T-106/T-110). */
.grid-venta { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 16px; align-items: start; }
.panel-cobro { position: sticky; top: 110px; }
@media (max-width: 920px) {
    .grid-venta { grid-template-columns: minmax(0, 1fr); }
    .panel-cobro { position: static; }
}

</style>