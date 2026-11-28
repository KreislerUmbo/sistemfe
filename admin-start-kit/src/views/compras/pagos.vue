<template>
    <DefaultLayout>
        <ul v-if="puede('cuentas_bancarias.gestionar')" class="nav nav-tabs mb-3">
            <li class="nav-item">
                <button type="button" class="nav-link" :class="{ active: pestana === 'pagos' }" @click="pestana = 'pagos'">Pagos</button>
            </li>
            <li class="nav-item">
                <button type="button" class="nav-link" :class="{ active: pestana === 'cuentas' }" @click="pestana = 'cuentas'">
                    Cuentas bancarias
                </button>
            </li>
        </ul>

        <div v-if="pestana === 'cuentas'" class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="card-title mb-0">Cuentas bancarias</h4>
                <button type="button" class="btn btn-sm btn-outline-primary" @click="cuentasRef?.abrirFormulario()">
                    <i class="fas fa-plus me-1"></i>Agregar cuenta
                </button>
            </div>
            <div class="card-body">
                <CuentasBancarias ref="cuentasRef" />
            </div>
        </div>

        <div v-else class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h4 class="card-title mb-0">Pagos a proveedores</h4>
                <button v-if="puede('compras.pagar')" type="button" class="btn btn-sm btn-success" @click="nuevo = true">
                    <i class="far fa-plus-square me-1"></i>Nuevo pago
                </button>
            </div>
            <div class="card-body">
                <div class="row g-2 mb-3 align-items-end">
                    <div class="col-12 col-md-3">
                        <label class="form-label small mb-1" for="filtro-proveedor">Proveedor</label>
                        <ProveedorSelector id="filtro-proveedor" v-model="proveedor" placeholder="Todos" @update:model-value="buscar" />
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1" for="filtro-desde">Desde</label>
                        <CampoFecha id="filtro-desde" v-model="filtros.desde" etiqueta="desde" @update:model-value="buscar" />
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1" for="filtro-hasta">Hasta</label>
                        <CampoFecha id="filtro-hasta" v-model="filtros.hasta" etiqueta="hasta" @update:model-value="buscar" />
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1" for="filtro-origen">Origen</label>
                        <select id="filtro-origen" v-model="filtros.origen" class="form-select form-select-sm" @change="buscar">
                            <option value="">Todos</option>
                            <option value="caja">Caja</option>
                            <option value="banco">Banco</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1" for="filtro-estado">Estado</label>
                        <select id="filtro-estado" v-model="filtros.estado" class="form-select form-select-sm" @change="buscar">
                            <option value="">Todos</option>
                            <option v-for="(e, clave) in ESTADOS_DEL_PAGO" :key="clave" :value="clave">{{ e.texto }}</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input id="filtro-saldo" v-model="filtros.con_saldo" class="form-check-input" type="checkbox" @change="buscar">
                            <label class="form-check-label small" for="filtro-saldo">Solo con saldo a cuenta</label>
                        </div>
                    </div>
                </div>

                <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>

                <div class="table-responsive">
                    <table class="table table-sm table-centered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Fecha</th>
                                <th>Proveedor</th>
                                <th>Origen</th>
                                <th class="text-end">Monto</th>
                                <th>Aplicado a</th>
                                <th class="text-end">A cuenta</th>
                                <th>Estado</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in pagos" :key="p.id" :class="{ 'text-muted': p.estado === 'anulado' }">
                                <td>{{ formatFecha(p.fecha) }}</td>
                                <td>{{ p.proveedor?.razon_social }}</td>
                                <td>
                                    <template v-if="p.origen === 'caja'">Caja · {{ p.metodo_pago?.name }}</template>
                                    <template v-else>
                                        {{ p.cuenta_bancaria?.banco }} {{ p.cuenta_bancaria?.numero_cuenta }}
                                        <small class="d-block text-muted">Op. {{ p.numero_operacion }}</small>
                                    </template>
                                </td>
                                <td class="text-end">{{ formatMonto(p.monto, p.moneda) }}</td>
                                <td>
                                    <div v-for="a in p.aplicaciones.filter((x) => !x.desaplicada_at)" :key="a.id" class="text-nowrap">
                                        <router-link :to="{ name: 'compras.index', query: { ver: a.compra_id } }">
                                            {{ a.compra?.serie }}-{{ a.compra?.numero }}
                                        </router-link>
                                        · {{ formatMonto(a.monto, p.moneda) }}
                                        <small v-if="a.tipo_cambio" class="text-muted">(T.C. {{ Number(a.tipo_cambio) }})</small>
                                    </div>
                                    <small v-if="p.motivo_anulacion" class="d-block text-danger">{{ p.motivo_anulacion }}</small>
                                </td>
                                <td class="text-end">{{ p.estado === 'anulado' ? '—' : formatMonto(p.a_cuenta, p.moneda) }}</td>
                                <td><span class="badge" :class="ESTADOS_DEL_PAGO[p.estado].clase">{{ ESTADOS_DEL_PAGO[p.estado].texto }}</span></td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-link p-1" title="Ver pago" @click="fichaId = p.id">
                                        <i class="las la-eye fs-20"></i>
                                    </button>
                                    <button v-if="puede('compras.anular') && p.estado !== 'anulado'" type="button"
                                        class="btn btn-sm btn-link p-1" title="Anular pago" @click="anular(p)">
                                        <i class="las la-ban text-danger fs-20"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!pagos.length">
                                <td colspan="8" class="text-center text-muted py-4">No hay pagos con estos filtros.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <b-pagination v-model="pagina" :total-rows="total" :per-page="porPagina" prev-text="Anterior" next-text="Siguiente"
                    class="mt-2" />
            </div>
        </div>

        <RegistrarPago v-model="nuevo" @guardado="listar" />
        <FichaPagoProveedor v-model="fichaId" @cambiado="listar" />
    </DefaultLayout>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import CampoFecha from '@/components/CampoFecha.vue';
import FichaPagoProveedor from '@/components/Compras/FichaPagoProveedor.vue';
import ProveedorSelector from '@/components/Compras/ProveedorSelector.vue';
import RegistrarPago from '@/components/Compras/RegistrarPago.vue';
import CuentasBancarias from '@/components/CuentasBancarias.vue';
import httpClient from '@/helpers/http-client';
import { formatMonto } from '@/helpers/compras';
import { formatFecha } from '@/helpers/fecha';
import { ESTADOS_DEL_PAGO } from '@/helpers/pagosProveedor';
import { pedirMotivo } from '@/helpers/pedirMotivo';
import { useAuthStore } from '@/stores/auth';
import type { CompraProveedor } from '@/types/compras';
import type { PagoProveedor } from '@/types/pagosProveedor';

const authStore = useAuthStore();
const puede = (permiso: string) => authStore.isPermitedRoute(permiso);

const pestana = ref<'pagos' | 'cuentas'>('pagos');
const cuentasRef = ref<InstanceType<typeof CuentasBancarias> | null>(null);

const vacios = () => ({ desde: '' as string | null, hasta: '' as string | null, origen: '', estado: '', con_saldo: false });
const filtros = reactive(vacios());
const proveedor = ref<CompraProveedor | null>(null);
const pagos = ref<PagoProveedor[]>([]);
const total = ref(0);
const porPagina = ref(15);
const pagina = ref(1);
const error = ref('');
const nuevo = ref(false);
const fichaId = ref<number | null>(null);

const listar = async () => {
    error.value = '';
    try {
        const { data } = await httpClient.get('compras/pagos', {
            params: {
                page: pagina.value,
                proveedor_id: proveedor.value?.id,
                desde: filtros.desde || undefined,
                hasta: filtros.hasta || undefined,
                origen: filtros.origen || undefined,
                estado: filtros.estado || undefined,
                con_saldo: filtros.con_saldo ? 1 : undefined,
            },
        });
        pagos.value = data.pagos;
        total.value = data.total;
        porPagina.value = data.paginate;
    } catch (e: any) {
        pagos.value = [];
        total.value = 0;
        error.value = e.response?.data?.message ?? 'No se pudieron cargar los pagos.';
    }
};

const buscar = () => {
    if (pagina.value === 1) listar();
    else pagina.value = 1;
};

const anular = async (pago: PagoProveedor) => {
    const hecho = await pedirMotivo(
        `Anular pago de ${formatMonto(pago.monto, pago.moneda)}`,
        pago.origen === 'caja'
            ? 'No se borra: se desaplica y, si el egreso ya salió, se corrige en tu turno de caja.'
            : 'No se borra: se desaplica y queda anulado.',
        'Anular',
        (motivo) => httpClient.post(`compras/pagos/${pago.id}/anular`, { motivo }),
    );
    if (hecho) listar();
};

watch(pagina, listar);
onMounted(listar);
</script>
