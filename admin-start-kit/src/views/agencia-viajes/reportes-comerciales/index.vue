<template>
    <DefaultLayout>
        <!-- CRM C6b (T-062): qué canal trae ventas, quién convierte más y por qué se pierde.
             Cuenta las cotizaciones del Cotizador creadas en el rango (días de Perú). -->
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-chart-line me-2 text-primary"></i>Reportes comerciales
                </h5>
                <small class="text-muted">Cotizaciones creadas del {{ formatDia(filtros.desde) }} al {{ formatDia(filtros.hasta) }}</small>
            </div>
            <button type="button" class="btn btn-outline-success btn-sm" :disabled="exportando || !datos" @click="exportarExcel">
                <span v-if="exportando" class="spinner-border spinner-border-sm me-1"></span>
                <i v-else class="fas fa-file-excel me-1"></i> Exportar Excel
            </button>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-2">
                <div class="row g-2 align-items-end">
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Desde</label>
                        <CampoFecha v-model="filtros.desde" etiqueta="Desde" />
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Hasta</label>
                        <CampoFecha v-model="filtros.hasta" etiqueta="Hasta" :min="filtros.desde || undefined" :max="hastaMaximo" />
                    </div>
                    <div class="col-12 col-md-auto">
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary" @click="rango('mes')">Este mes</button>
                            <button type="button" class="btn btn-outline-secondary" @click="rango('anterior')">Mes anterior</button>
                            <button type="button" class="btn btn-outline-secondary" @click="rango('anio')">Este año</button>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Vendedor</label>
                        <select v-model="filtros.vendedor_id" class="form-select form-select-sm">
                            <option value="">Todos</option>
                            <option v-for="v in vendedores" :key="v.id" :value="String(v.id)">{{ v.nombre }}{{ v.inactivo ? ' (inactivo)' : '' }}</option>
                            <option value="sin">Sin vendedor</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1 small fw-semibold text-secondary">Origen</label>
                        <select v-model="filtros.origen_contacto_id" class="form-select form-select-sm">
                            <option value="">Todos</option>
                            <option v-for="o in origenes" :key="o.id" :value="String(o.id)">{{ o.nombre }}{{ o.inactivo ? ' (desactivado)' : '' }}</option>
                            <option value="sin">Sin origen</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="cargando && !datos" class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span>Cargando…</div>
        <div v-else-if="!datos" class="text-center text-muted py-5">No se pudo cargar el reporte.</div>
        <div v-else :class="{ 'opacity-50': cargando }">
            <!-- Embudo -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-2">
                    <h6 class="fw-semibold mb-0">Embudo</h6>
                </div>
                <div class="card-body">
                    <div class="row g-2 text-center">
                        <div v-for="e in etapas" :key="e.clave" class="col-6 col-md">
                            <div class="border rounded py-2 h-100">
                                <div class="small text-muted">{{ e.etiqueta }}</div>
                                <div class="fs-4 fw-bold" :class="e.clase">{{ e.cantidad }}</div>
                                <div class="small text-muted">{{ e.pct }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-4 mt-3 small">
                        <div><span class="text-muted">Conversión (ganadas / consultas):</span> <strong>{{ pct(datos.embudo.conversion_pct) }}</strong></div>
                        <div><span class="text-muted">Vendido:</span> <strong>{{ montos(datos.embudo.monto) }}</strong></div>
                        <div>
                            <span class="text-muted">Tiempo de respuesta (mediana hasta el primer envío):</span>
                            <strong>{{ horas(datos.tiempo_respuesta.mediana_horas) }}</strong>
                            <span class="text-muted">· {{ datos.tiempo_respuesta.cotizaciones_con_envio }} con envío</span>
                        </div>
                    </div>
                    <div class="small text-muted mt-1">Anuladas = se ganaron pero la reserva se canceló; no suman al monto vendido.</div>
                </div>
            </div>

            <!-- Por origen / por vendedor -->
            <div v-for="g in grupos" :key="g.titulo" class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-2">
                    <h6 class="fw-semibold mb-0">{{ g.titulo }}</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>{{ g.columna }}</th>
                                    <th class="text-end">Consultas</th>
                                    <th class="text-end">Ganadas</th>
                                    <th class="text-end">Perdidas</th>
                                    <th class="text-end">Abiertas</th>
                                    <th class="text-end">Anuladas</th>
                                    <th class="text-end">Conversión</th>
                                    <th class="text-end">Vendido S/</th>
                                    <th class="text-end">Vendido US$</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!g.filas.length"><td colspan="9" class="text-muted fst-italic small">Sin cotizaciones en el rango.</td></tr>
                                <tr v-for="f in g.filas" :key="String(f.id)">
                                    <td>
                                        <span :class="{ 'text-muted fst-italic': f.id === null }">{{ f.nombre }}</span>
                                        <span v-if="f.inactivo" class="badge bg-secondary-subtle text-secondary ms-1">{{ g.inactivo }}</span>
                                    </td>
                                    <td class="text-end">{{ f.consultas }}</td>
                                    <td class="text-end text-success">{{ f.ganadas }}</td>
                                    <td class="text-end text-danger">{{ f.perdidas }}</td>
                                    <td class="text-end">{{ f.abiertas }}</td>
                                    <td class="text-end text-muted">{{ f.anuladas }}</td>
                                    <td class="text-end fw-semibold">{{ pct(f.conversion_pct) }}</td>
                                    <td class="text-end">{{ monto(f.monto.PEN) }}</td>
                                    <td class="text-end">{{ monto(f.monto.USD) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Motivos -->
            <div class="row g-3 mb-3">
                <div v-for="m in bloquesMotivos" :key="m.titulo" class="col-12 col-lg-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white py-2">
                            <h6 class="fw-semibold mb-0" :class="m.clase">{{ m.titulo }}</h6>
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-sm mb-0 align-middle">
                                <thead class="table-light">
                                    <tr><th>Motivo</th><th class="text-end">Cantidad</th><th class="text-end">%</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-if="!m.filas.length"><td colspan="3" class="text-muted fst-italic small">Sin datos en el rango.</td></tr>
                                    <tr v-for="f in m.filas" :key="String(f.id)">
                                        <td :class="{ 'text-muted fst-italic': f.id === null }">{{ f.nombre }}</td>
                                        <td class="text-end">{{ f.cantidad }}</td>
                                        <td class="text-end">{{ pct(f.pct) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import CampoFecha from '@/components/CampoFecha.vue';
import { useToast } from '@/composables/useToast';
import { hoyPeru, sumarDiasISO } from '@/helpers/fecha';
import {
    reporteComercialService,
    type FiltrosReporteComercial, type MontosPorMoneda, type OpcionFiltro, type ReporteComercialDatos,
} from '@/services/admin/reporteComercialService';

const toast = useToast();

const hoy = hoyPeru();
const filtros = ref<Required<FiltrosReporteComercial>>({ desde: `${hoy.slice(0, 7)}-01`, hasta: hoy, vendedor_id: '', origen_contacto_id: '' });
const datos = ref<ReporteComercialDatos | null>(null);
const vendedores = ref<OpcionFiltro[]>([]);
const origenes = ref<OpcionFiltro[]>([]);
const cargando = ref(true);
const exportando = ref(false);

// Mismo tope que el backend (12 meses): el último día permitido es desde + 12 meses − 1 día.
const hastaMaximo = computed(() => {
    if (!filtros.value.desde) return undefined;
    const [a, m, d] = filtros.value.desde.split('-').map(Number);
    const ultimoDelMes = new Date(Date.UTC(a + 1, m, 0)).getUTCDate();
    const tope = new Date(Date.UTC(a + 1, m - 1, Math.min(d, ultimoDelMes))).toISOString().slice(0, 10);
    return sumarDiasISO(tope, -1);
});

const rango = (cual: 'mes' | 'anterior' | 'anio') => {
    if (cual === 'mes') {
        filtros.value.desde = `${hoy.slice(0, 7)}-01`;
        filtros.value.hasta = hoy;
    } else if (cual === 'anterior') {
        const finAnterior = sumarDiasISO(`${hoy.slice(0, 7)}-01`, -1);
        filtros.value.desde = `${finAnterior.slice(0, 7)}-01`;
        filtros.value.hasta = finAnterior;
    } else {
        filtros.value.desde = `${hoy.slice(0, 4)}-01-01`;
        filtros.value.hasta = hoy;
    }
};

const formatDia = (ymd: string) => (ymd ? ymd.split('-').reverse().join('/') : '—');
const pct = (n: number) => `${Number(n).toLocaleString('es-PE', { maximumFractionDigits: 1 })} %`;
const monto = (texto: string) => Number(texto).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const montos = (m: MontosPorMoneda) => `S/ ${monto(m.PEN)} · US$ ${monto(m.USD)}`;
const horas = (h: number | null) => {
    if (h === null) return '—';
    return h < 48 ? `${Number(h).toLocaleString('es-PE', { maximumFractionDigits: 1 })} h` : `${(h / 24).toLocaleString('es-PE', { maximumFractionDigits: 1 })} días`;
};

const etapas = computed(() => {
    const e = datos.value!.embudo;
    return [
        { clave: 'consultas', etiqueta: 'Consultas', cantidad: e.consultas, pct: '100 %', clase: 'text-dark' },
        { clave: 'enviadas', etiqueta: 'Enviadas', cantidad: e.enviadas, pct: pct(e.pct.enviadas), clase: 'text-primary' },
        { clave: 'ganadas', etiqueta: 'Ganadas', cantidad: e.ganadas, pct: pct(e.pct.ganadas), clase: 'text-success' },
        { clave: 'perdidas', etiqueta: 'Perdidas', cantidad: e.perdidas, pct: pct(e.pct.perdidas), clase: 'text-danger' },
        { clave: 'abiertas', etiqueta: 'Abiertas', cantidad: e.abiertas, pct: pct(e.pct.abiertas), clase: 'text-warning' },
        { clave: 'anuladas', etiqueta: 'Anuladas', cantidad: e.anuladas, pct: pct(e.pct.anuladas), clase: 'text-muted' },
    ];
});

const grupos = computed(() => [
    { titulo: 'Por origen', columna: 'Origen', inactivo: 'desactivado', filas: datos.value!.por_origen },
    { titulo: 'Por vendedor', columna: 'Vendedor', inactivo: 'inactivo', filas: datos.value!.por_vendedor },
]);

const bloquesMotivos = computed(() => [
    { titulo: 'Motivos de ganancia', clase: 'text-success', filas: datos.value!.motivos.ganada },
    { titulo: 'Motivos de pérdida', clase: 'text-danger', filas: datos.value!.motivos.perdida },
]);

const mensajeDeError = async (e: any, porDefecto: string): Promise<string> => {
    let data = e?.response?.data;
    if (data instanceof Blob) {
        try { data = JSON.parse(await data.text()); } catch { data = null; }
    }
    return data?.message ?? porDefecto;
};

const cargar = async () => {
    if (!filtros.value.desde || !filtros.value.hasta) return;
    cargando.value = true;
    try {
        datos.value = await reporteComercialService.obtener(filtros.value);
    } catch (e: any) {
        toast.error(await mensajeDeError(e, 'No se pudo cargar el reporte.'));
    } finally {
        cargando.value = false;
    }
};

const exportarExcel = async () => {
    exportando.value = true;
    try {
        const blob = await reporteComercialService.exportarExcel(filtros.value);
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        // Mismo nombre que arma el backend (el navegador no toma el del header en un blob).
        const dmy = (ymd: string) => ymd.split('-').reverse().join('-');
        link.download = `reportes_comerciales_del_${dmy(filtros.value.desde)}_al_${dmy(filtros.value.hasta)}.xlsx`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
    } catch (e: any) {
        toast.error(await mensajeDeError(e, 'No se pudo generar el Excel.'));
    } finally {
        exportando.value = false;
    }
};

watch(filtros, cargar, { deep: true });

onMounted(async () => {
    const [, opciones] = await Promise.all([cargar(), reporteComercialService.opciones().catch(() => null)]);
    if (opciones) {
        vendedores.value = opciones.vendedores;
        origenes.value = opciones.origenes;
    }
});
</script>
