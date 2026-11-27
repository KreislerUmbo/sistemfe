<template>
    <!-- T-137 — a quién se le debe: una fila por RUC y, dentro, una línea por moneda. Al abrirla se
         ven sus fichas. Los montos llegan calculados: acá solo se formatean. -->
    <DefaultLayout>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h4 class="card-title mb-0">Cuentas por pagar</h4>
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <input v-model="filtros.search" type="search" class="form-control form-control-sm" style="width: 220px"
                        placeholder="Nombre o RUC" aria-label="Buscar proveedor" @input="buscarConPausa">
                    <div class="input-group input-group-sm" style="width: 190px">
                        <label class="input-group-text" for="filtro-dias">Vence en</label>
                        <input id="filtro-dias" v-model="filtros.dias" type="number" min="1" max="365" step="1"
                            class="form-control text-end" @change="buscar">
                        <span class="input-group-text">días</span>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input id="filtro-vencido" v-model="filtros.solo_vencido" class="form-check-input" type="checkbox" @change="buscar">
                        <label class="form-check-label small" for="filtro-vencido">Solo con vencido</label>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-success" :disabled="descargando" @click="excel">
                        <i class="far fa-file-excel me-1"></i>{{ descargando ? 'Generando…' : 'Excel' }}
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>

                <div class="table-responsive">
                    <table class="table table-sm table-centered small mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Proveedor</th>
                                <th>Moneda</th>
                                <th class="text-end">Pendiente</th>
                                <th class="text-end">Vencido</th>
                                <th class="text-end">Vence en {{ diasMostrados }} días</th>
                                <th class="text-end" title="Vencido de 1 a 30 días">1-30</th>
                                <th class="text-end" title="Vencido de 31 a 60 días">31-60</th>
                                <th class="text-end" title="Vencido de 61 a 90 días">61-90</th>
                                <th class="text-end" title="Vencido hace más de 90 días">+90</th>
                                <th class="text-end">Por aprobar</th>
                                <th class="text-end">A cuenta</th>
                                <th class="text-end">A favor</th>
                                <th class="text-end">Neto</th>
                            </tr>
                        </thead>
                        <tbody v-for="g in grupos" :key="g.clave">
                            <tr v-for="(m, i) in g.monedas" :key="m.moneda">
                                <td v-if="i === 0" :rowspan="g.monedas.length">
                                    <button type="button" class="btn btn-sm btn-link p-0 text-start text-decoration-none"
                                        :aria-expanded="abierta === g.clave" @click="alternar(g.clave)">
                                        <i class="fas me-1" :class="abierta === g.clave ? 'fa-chevron-down' : 'fa-chevron-right'"></i>{{ g.razon_social }}
                                    </button>
                                    <small class="d-block text-muted">
                                        {{ g.documento ?? 'Sin documento' }}<template v-if="g.fichas.length > 1"> · {{ g.fichas.length }} fichas</template>
                                    </small>
                                </td>
                                <td>{{ m.moneda }}</td>
                                <td v-for="(c, j) in celdas(m)" :key="j" class="text-end text-nowrap" :class="c.clase">
                                    {{ c.texto }}
                                    <span v-if="c.aFavor" class="badge bg-info ms-1">a favor</span>
                                </td>
                            </tr>
                            <tr v-if="abierta === g.clave">
                                <td colspan="13" class="bg-light-subtle">
                                    <div v-if="!detalle" class="text-muted py-2">{{ errorDetalle || 'Cargando…' }}</div>
                                    <div v-for="f in detalle?.fichas" :key="f.proveedor_id" class="border rounded p-2 mb-2">
                                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                                            <div>
                                                <strong>{{ nombreConCiudad(f.nombre_comercial || f.razon_social, f.ciudad) }}</strong>
                                                <div v-for="m in f.monedas" :key="m.moneda" class="text-muted">
                                                    Pendiente {{ formatMonto(m.pendiente, m.moneda) }} · vencido {{ formatMonto(m.vencido, m.moneda) }}
                                                    · a cuenta {{ formatMonto(m.a_cuenta, m.moneda) }} · neto {{ formatMonto(m.neto, m.moneda) }}
                                                </div>
                                            </div>
                                            <div v-if="puede('compras.pagar')" class="d-flex gap-1">
                                                <button v-for="m in f.monedas" :key="m.moneda" type="button" class="btn btn-sm btn-outline-success"
                                                    @click="pagar(f, m.moneda)">
                                                    <i class="far fa-plus-square me-1"></i>Registrar pago {{ m.moneda }}
                                                </button>
                                            </div>
                                        </div>

                                        <div v-if="f.compras.length" class="table-responsive">
                                            <table class="table table-sm mb-2">
                                                <thead>
                                                    <tr>
                                                        <th>Comprobante</th>
                                                        <th>Emisión</th>
                                                        <th>Vencimiento</th>
                                                        <th class="text-end">Atraso</th>
                                                        <th class="text-end">Total</th>
                                                        <th class="text-end">Notas</th>
                                                        <th class="text-end">Aplicado</th>
                                                        <th class="text-end">Por aprobar</th>
                                                        <th class="text-end">Saldo</th>
                                                        <th>Estado</th>
                                                        <th></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr v-for="c in f.compras" :key="c.id">
                                                        <td>
                                                            <router-link :to="{ name: 'compras.index', query: { ver: c.id } }">
                                                                {{ c.serie }}-{{ c.numero }}
                                                            </router-link>
                                                        </td>
                                                        <td>{{ formatFecha(c.fecha_emision) }}</td>
                                                        <td>{{ formatFecha(c.vencimiento) }}</td>
                                                        <td class="text-end">{{ c.dias_atraso ? `${c.dias_atraso} d` : '—' }}</td>
                                                        <td class="text-end">{{ formatMonto(c.total, c.moneda) }}</td>
                                                        <td class="text-end">{{ formatMonto(c.notas, c.moneda) }}</td>
                                                        <td class="text-end">{{ formatMonto(c.aplicado, c.moneda) }}</td>
                                                        <td class="text-end">{{ formatMonto(c.por_aprobar, c.moneda) }}</td>
                                                        <td class="text-end">{{ formatMonto(c.saldo, c.moneda) }}</td>
                                                        <td>
                                                            <span v-if="esSaldoAFavor(c.saldo)" class="badge bg-info">A favor</span>
                                                            <span v-else class="badge" :class="ESTADOS_PAGO[c.estado_pago].clase">
                                                                {{ ESTADOS_PAGO[c.estado_pago].texto }}
                                                            </span>
                                                        </td>
                                                        <td class="text-end">
                                                            <button v-if="puede('compras.pagar') && esSaldoAFavor(c.saldo)" type="button"
                                                                class="btn btn-sm btn-link p-0 text-nowrap" @click="reembolsarCompra(c)">
                                                                Registrar reembolso
                                                            </button>
                                                            <button v-if="puede('compras.pagar') && admiteAplicar(c)" type="button"
                                                                class="btn btn-sm btn-link p-0 text-nowrap" @click="aplicar(c)">
                                                                Aplicar pago a cuenta
                                                            </button>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>

                                        <template v-if="f.pagos.length">
                                            <div class="fw-semibold">Pagos a cuenta o por aprobar</div>
                                            <div v-for="p in f.pagos" :key="p.id">
                                                {{ formatFecha(p.fecha) }} ·
                                                {{ p.origen === 'caja' ? `Caja · ${p.metodo_pago ?? ''}` : `${p.cuenta_bancaria ?? 'Banco'} · Op. ${p.numero_operacion}` }}
                                                · pago de {{ formatMonto(p.monto, p.moneda) }}
                                                <template v-if="p.estado === 'pendiente_aprobacion'">
                                                    · <span class="badge" :class="ESTADOS_DEL_PAGO[p.estado].clase">{{ ESTADOS_DEL_PAGO[p.estado].texto }}</span>
                                                </template>
                                                <template v-else> · a cuenta {{ formatMonto(p.a_cuenta, p.moneda) }}</template>
                                                <button v-if="puede('compras.pagar') && p.estado === 'vigente' && enCentimos(p.a_cuenta) > 0"
                                                    type="button" class="btn btn-sm btn-link p-0 ms-2 align-baseline"
                                                    @click="reembolsarPago(p)">Registrar reembolso</button>
                                            </div>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                        <tbody v-if="!grupos.length">
                            <tr>
                                <td colspan="13" class="text-center text-muted py-4">
                                    {{ cargando ? 'Cargando…' : 'No hay deudas con estos filtros.' }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot v-if="totales.length" class="table-light fw-semibold">
                            <tr v-for="(m, i) in totales" :key="m.moneda">
                                <td v-if="i === 0" :rowspan="totales.length">Total</td>
                                <td>{{ m.moneda }}</td>
                                <td v-for="(c, j) in celdas(m)" :key="j" class="text-end text-nowrap" :class="c.clase">
                                    {{ c.texto }}
                                    <span v-if="c.aFavor" class="badge bg-info ms-1">a favor</span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <b-pagination v-model="pagina" :total-rows="total" :per-page="porPagina" prev-text="Anterior" next-text="Siguiente"
                    class="mt-2" />
            </div>
        </div>

        <RegistrarPago v-model="registrando" :proveedor="paraPagar" @guardado="listar" />
        <AplicarPagoACuenta v-model="aplicando" :compra="paraAplicar" @guardado="listar" />
        <RegistrarReembolso v-model="reembolsando" :destino="paraReembolsar" @guardado="listar" />
    </DefaultLayout>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import AplicarPagoACuenta from '@/components/Compras/AplicarPagoACuenta.vue';
import RegistrarPago from '@/components/Compras/RegistrarPago.vue';
import RegistrarReembolso from '@/components/Compras/RegistrarReembolso.vue';
import httpClient from '@/helpers/http-client';
import { enCentimos, formatMonto } from '@/helpers/compras';
import { descargarArchivo, leerMensajeErrorBlob } from '@/helpers/descargarArchivo';
import { formatFecha, hoyPeru } from '@/helpers/fecha';
import { ESTADOS_DEL_PAGO, ESTADOS_PAGO, esSaldoAFavor } from '@/helpers/pagosProveedor';
import { nombreConCiudad } from '@/helpers/proveedor';
import { useAuthStore } from '@/stores/auth';
import type { CompraProveedor } from '@/types/compras';
import type {
    CifrasPorPagar, CompraPorPagar, DestinoReembolso, DetallePorPagar, FichaPorPagar, GrupoPorPagar, PagoAbierto,
} from '@/types/pagosProveedor';

const authStore = useAuthStore();
const puede = (permiso: string) => authStore.isPermitedRoute(permiso);

const filtros = reactive({ search: '', dias: 7 as number | string, solo_vencido: false });
const grupos = ref<GrupoPorPagar[]>([]);
const totales = ref<CifrasPorPagar[]>([]);
const total = ref(0);
const porPagina = ref(15);
const pagina = ref(1);
const diasMostrados = ref(7);
const cargando = ref(false);
const descargando = ref(false);
const error = ref('');

const abierta = ref<string | null>(null);
const detalle = ref<DetallePorPagar | null>(null);
const errorDetalle = ref('');

const registrando = ref(false);
const paraPagar = ref<{ proveedor: CompraProveedor; moneda: 'PEN' | 'USD' } | null>(null);
const aplicando = ref(false);
const paraAplicar = ref<CompraPorPagar | null>(null);

// Una respuesta vieja no pisa a la nueva.
let pedido = 0;
let pedidoDetalle = 0;
let espera: ReturnType<typeof setTimeout> | undefined;

const parametros = () => ({
    search: filtros.search.trim() || undefined,
    dias: filtros.dias || undefined,
    solo_vencido: filtros.solo_vencido ? 1 : undefined,
});

/** Las columnas de montos, en el orden del encabezado. */
const celdas = (m: CifrasPorPagar) => {
    const celda = (valor: string, clase = '', aFavor = false) => ({
        texto: formatMonto(valor, m.moneda),
        clase: enCentimos(valor) === 0 ? 'text-muted' : clase,
        aFavor,
    });
    return [
        celda(m.pendiente),
        celda(m.vencido, 'text-danger'),
        celda(m.por_vencer),
        celda(m.antiguedad.d1_30),
        celda(m.antiguedad.d31_60),
        celda(m.antiguedad.d61_90),
        celda(m.antiguedad.mas_90),
        celda(m.por_aprobar),
        celda(m.a_cuenta),
        celda(m.a_favor),
        celda(m.neto, 'fw-semibold', esSaldoAFavor(m.neto)),
    ];
};

/** Lo que la compra todavía admite: su saldo menos lo que ya espera aprobación. */
const admiteAplicar = (c: CompraPorPagar) => enCentimos(c.saldo) - enCentimos(c.por_aprobar) > 0;

const cargarDetalle = async () => {
    const actual = ++pedidoDetalle;
    errorDetalle.value = '';
    if (!abierta.value) return;
    try {
        const { data } = await httpClient.get('compras/cuentas-por-pagar/detalle', {
            params: { clave: abierta.value, dias: filtros.dias || undefined },
        });
        if (actual === pedidoDetalle) detalle.value = data.grupo;
    } catch (e: any) {
        if (actual !== pedidoDetalle) return;
        detalle.value = null;
        errorDetalle.value = e.response?.data?.message ?? 'No se pudo cargar el detalle.';
    }
};

const alternar = (clave: string) => {
    abierta.value = abierta.value === clave ? null : clave;
    detalle.value = null;
    cargarDetalle();
};

const listar = async () => {
    const actual = ++pedido;
    cargando.value = true;
    error.value = '';
    try {
        const { data } = await httpClient.get('compras/cuentas-por-pagar', {
            params: { ...parametros(), page: pagina.value },
        });
        if (actual !== pedido) return;
        grupos.value = data.grupos;
        totales.value = data.totales;
        total.value = data.total;
        porPagina.value = data.paginate;
        diasMostrados.value = data.dias;
        // El grupo abierto se recarga; si ya no está (quedó en cero), se cierra.
        if (!grupos.value.some((g) => g.clave === abierta.value)) {
            abierta.value = null;
            detalle.value = null;
        }
        cargarDetalle();
    } catch (e: any) {
        if (actual !== pedido) return;
        grupos.value = [];
        totales.value = [];
        total.value = 0;
        error.value = e.response?.data?.message ?? 'No se pudieron cargar las cuentas por pagar.';
    } finally {
        if (actual === pedido) cargando.value = false;
    }
};

const buscar = () => {
    if (pagina.value === 1) listar();
    else pagina.value = 1;
};

const buscarConPausa = () => {
    clearTimeout(espera);
    espera = setTimeout(buscar, 300);
};

const excel = async () => {
    descargando.value = true;
    error.value = '';
    const query = new URLSearchParams();
    Object.entries(parametros()).forEach(([clave, valor]) => {
        if (valor !== undefined) query.set(clave, String(valor));
    });
    try {
        await descargarArchivo(`compras/cuentas-por-pagar/excel?${query}`, `cuentas-por-pagar-${hoyPeru()}.xlsx`);
    } catch (e) {
        error.value = await leerMensajeErrorBlob(e, 'No se pudo generar el Excel.');
    } finally {
        descargando.value = false;
    }
};

const pagar = (ficha: FichaPorPagar, moneda: 'PEN' | 'USD') => {
    paraPagar.value = {
        proveedor: {
            id: ficha.proveedor_id,
            razon_social: ficha.razon_social,
            nombre_comercial: ficha.nombre_comercial,
            ciudad: ficha.ciudad,
            numero_documento: ficha.numero_documento,
        },
        moneda,
    };
    registrando.value = true;
};

const aplicar = (compra: CompraPorPagar) => {
    paraAplicar.value = compra;
    aplicando.value = true;
};

// T-140
const reembolsando = ref(false);
const paraReembolsar = ref<DestinoReembolso | null>(null);

const reembolsarCompra = (c: CompraPorPagar) => {
    paraReembolsar.value = {
        proveedor_id: c.proveedor_id,
        compra_id: c.id,
        moneda: c.moneda,
        maximo: Math.abs(Number(c.saldo)).toFixed(2),
        titulo: `Saldo a favor de ${c.serie}-${c.numero}`,
    };
    reembolsando.value = true;
};

const reembolsarPago = (p: PagoAbierto) => {
    paraReembolsar.value = {
        proveedor_id: p.proveedor_id,
        pago_proveedor_id: p.id,
        moneda: p.moneda,
        maximo: p.a_cuenta,
        titulo: `Lo a cuenta del pago del ${formatFecha(p.fecha)}`,
    };
    reembolsando.value = true;
};

watch(pagina, listar);
onMounted(listar);
</script>
