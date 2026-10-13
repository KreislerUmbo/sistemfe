<template>
    <DefaultLayout>
        <div class="header-row d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-cash-register me-2 text-primary"></i>
                    Dashboard de Caja
                </h5>
                <small class="text-muted">Estado de todas las cajas activas</small>
            </div>
            <router-link :to="{ name: 'cash.history' }" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-history me-1"></i> Ver historial completo
            </router-link>
        </div>

        <!-- ═══════ Resumen ═══════ -->
        <div class="row g-3 mb-3">
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm text-center py-3">
                    <div class="fs-4 fw-bold">{{ dashboard?.summary.total_active_registers ?? 0 }}</div>
                    <small class="text-muted">Cajas activas</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm text-center py-3">
                    <div class="fs-4 fw-bold text-success">{{ dashboard?.summary.with_open_session ?? 0 }}</div>
                    <small class="text-muted">Con sesión abierta</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm text-center py-3">
                    <div class="fs-4 fw-bold" :class="(dashboard?.summary.stale ?? 0) > 0 ? 'text-danger' : ''">
                        {{ dashboard?.summary.stale ?? 0 }}
                    </div>
                    <small class="text-muted">Sesiones abiertas &gt; 24h</small>
                </div>
            </div>
        </div>

        <div v-if="(dashboard?.summary.stale ?? 0) > 0" class="alert alert-danger d-flex align-items-center gap-2">
            <i class="fas fa-triangle-exclamation"></i>
            Hay {{ dashboard?.summary.stale }} caja(s) con una sesión abierta hace más de 24 horas — revisa si el
            cajero olvidó cerrar el turno.
            <span v-if="puedeCerrarAjenas">Puedes cerrarla con el botón "Cerrar" de su fila.</span>
        </div>

        <!-- ═══════ Cajas ═══════ -->
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Sede</th>
                            <th>Caja</th>
                            <th>Estado</th>
                            <th>Cajero</th>
                            <th>Apertura</th>
                            <th class="text-end">Tiempo abierto</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="loading">
                            <td colspan="7" class="text-center py-4"><span class="spinner-border text-primary"></span></td>
                        </tr>
                        <tr v-else-if="!dashboard?.registers.length">
                            <td colspan="7" class="text-center text-muted py-4">No hay cajas activas configuradas.</td>
                        </tr>
                        <tr v-for="r in dashboard?.registers" :key="r.cash_register_id" :class="{ 'table-danger': r.is_stale }">
                            <td>{{ r.branch?.name ?? '-' }}</td>
                            <td class="fw-semibold">{{ r.cash_register_name }}</td>
                            <td>
                                <span class="badge" :class="r.has_open_session ? 'bg-success' : 'bg-secondary'">
                                    {{ r.has_open_session ? 'Abierta' : 'Cerrada' }}
                                </span>
                                <span v-if="r.is_stale" class="badge bg-danger ms-1">
                                    <i class="fas fa-triangle-exclamation me-1"></i>Sin cerrar &gt; 24h
                                </span>
                            </td>
                            <td>{{ r.opened_by_user?.name ?? '—' }}</td>
                            <td>{{ r.opened_at ? formatFechaHora(r.opened_at) : '—' }}</td>
                            <td class="text-end">{{ r.elapsed_hours !== null ? `${r.elapsed_hours} h` : '—' }}</td>
                            <td class="text-end">
                                <button v-if="puedeCerrar(r)" type="button" class="btn btn-sm"
                                    :class="r.is_stale ? 'btn-danger' : 'btn-outline-danger'"
                                    :disabled="cargandoSesionId === r.session_id" @click="abrirModalCierre(r)">
                                    <span v-if="cargandoSesionId === r.session_id" class="spinner-border spinner-border-sm me-1"></span>
                                    <i v-else class="fas fa-lock me-1"></i>Cerrar
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ═══════ Cierre de una sesión (propia o de otro cajero) ═══════ -->
        <b-modal v-model="showCloseModal" title="Cerrar caja" hide-footer centered size="lg">
            <div v-if="sesionACerrar">
                <div class="alert py-2" :class="esAjena ? 'alert-warning' : 'alert-light border'">
                    <div>
                        <strong>{{ sesionACerrar.cash_register.name }}</strong>
                        <span v-if="sesionACerrar.cash_register.branch"> · {{ sesionACerrar.cash_register.branch.name }}</span>
                    </div>
                    <div class="small">
                        Abierta por <strong>{{ nombreUsuario(sesionACerrar.opened_by_user) }}</strong>
                        el {{ formatFechaHora(sesionACerrar.opened_at) }}
                    </div>
                    <div v-if="esAjena" class="small mt-1">
                        Estás cerrando la caja de otro cajero: cuenta el efectivo físico de la caja y explica en las
                        notas por qué la cierras. Quedará registrado que la cerraste tú.
                    </div>
                </div>

                <p>Efectivo esperado: <strong>S/ {{ sesionACerrar.expected_cash_live.toFixed(2) }}</strong></p>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Efectivo contado (S/)</label>
                    <b-form-input type="number" step="0.01" min="0" size="sm" v-model.number="countedCash" />
                </div>

                <div class="mb-3" v-if="requiereMotivo">
                    <label class="form-label fw-semibold">Motivo de la diferencia (obligatorio)</label>
                    <b-form-textarea v-model="differenceReason" rows="2" />
                    <small class="text-muted">
                        La diferencia (S/ {{ diferenciaEstimada.toFixed(2) }}) supera la tolerancia
                        (S/ {{ tolerancia.toFixed(2) }}).
                    </small>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Notas de cierre {{ esAjena ? '(obligatorio)' : '(opcional)' }}</label>
                    <b-form-textarea v-model="closingNotes" rows="2" />
                </div>

                <div class="modal-footer">
                    <b-button variant="secondary" @click="showCloseModal = false">Cancelar</b-button>
                    <b-button variant="danger" :disabled="closing" @click="confirmarCierre">
                        <span v-if="closing" class="spinner-border spinner-border-sm me-1"></span>
                        Confirmar cierre
                    </b-button>
                </div>
            </div>
        </b-modal>
    </DefaultLayout>
</template>

<script setup lang="ts">
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import httpClient from '@/helpers/http-client';
import { ref, computed, onMounted } from 'vue';
import { formatFechaHora } from '@/helpers/fecha';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import { useAuthStore } from '@/stores/auth';
import type {
    CashDashboardResponse,
    CashDashboardRegister,
    CashSessionDetail,
    CashSessionDetailResponse,
    CashCloseResponse,
    SessionUser,
} from '@/types/cash-session';

type TVueSwalInstance = typeof Swal & typeof Swal.fire;

const loading = ref(true);
const dashboard = ref<CashDashboardResponse | null>(null);

async function cargar() {
    loading.value = true;
    try {
        const { data }: { data: CashDashboardResponse } = await httpClient.get('cash/dashboard');
        dashboard.value = data;
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire('Error', e.response?.data?.message ?? 'No se pudo cargar el dashboard de caja.', 'error');
    } finally {
        loading.value = false;
    }
}

// ── Cierre desde el Dashboard ──────────────────────────────────────────
// El backend (close() con cash_session_id) es quien decide: la sesión propia
// siempre, la de otro cajero solo con cash.close_others_session. Acá solo se
// evita mostrar un botón que va a fallar con 403.
const authStore = useAuthStore();
const puedeCerrarAjenas = computed(() => authStore.isPermitedRoute('cash.close_others_session'));

const puedeCerrar = (r: CashDashboardRegister) => {
    if (!r.has_open_session || !r.session_id) return false;
    return puedeCerrarAjenas.value || r.opened_by_user?.id === authStore.user?.id;
};

const nombreUsuario = (u: SessionUser | null) => (u ? [u.name, u.surname].filter(Boolean).join(' ') : '—');

const showCloseModal = ref(false);
const sesionACerrar = ref<CashSessionDetail | null>(null);
const cargandoSesionId = ref<number | null>(null);
const countedCash = ref<number>(0);
const differenceReason = ref('');
const closingNotes = ref('');
const closing = ref(false);

const tolerancia = computed(() => dashboard.value?.difference_tolerance ?? 2);
const esAjena = computed(() => !!sesionACerrar.value && sesionACerrar.value.opened_by_user?.id !== authStore.user?.id);
const diferenciaEstimada = computed(() =>
    sesionACerrar.value ? countedCash.value - sesionACerrar.value.expected_cash_live : 0
);
const requiereMotivo = computed(() => Math.abs(diferenciaEstimada.value) > tolerancia.value);

async function abrirModalCierre(r: CashDashboardRegister) {
    cargandoSesionId.value = r.session_id;
    try {
        const { data }: { data: CashSessionDetailResponse } = await httpClient.get(`cash/sessions/${r.session_id}`);
        if (data.session.status !== 'open') {
            (Swal as TVueSwalInstance).fire('Aviso', 'Esta sesión ya fue cerrada.', 'info');
            await cargar();
            return;
        }
        sesionACerrar.value = data.session;
        countedCash.value = 0;
        differenceReason.value = '';
        closingNotes.value = '';
        showCloseModal.value = true;
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire('Error', e.response?.data?.message ?? 'No se pudo cargar la sesión.', 'error');
    } finally {
        cargandoSesionId.value = null;
    }
}

async function confirmarCierre() {
    if (!sesionACerrar.value) return;
    if (requiereMotivo.value && !differenceReason.value.trim()) {
        (Swal as TVueSwalInstance).fire('Error', 'La diferencia supera la tolerancia — indica un motivo.', 'error');
        return;
    }
    if (esAjena.value && !closingNotes.value.trim()) {
        (Swal as TVueSwalInstance).fire('Error', 'Indica en las notas por qué cierras la caja de otro cajero.', 'error');
        return;
    }

    closing.value = true;
    try {
        const { data }: { data: CashCloseResponse } = await httpClient.post('cash/close', {
            cash_session_id: sesionACerrar.value.id,
            counted_cash: countedCash.value,
            difference_reason: differenceReason.value || null,
            closing_notes: closingNotes.value || null,
        });

        showCloseModal.value = false;
        const cerrada = data.session;
        (Swal as TVueSwalInstance).fire(
            'Caja cerrada',
            `Esperado: S/ ${Number(cerrada.expected_cash).toFixed(2)} — ` +
            `Contado: S/ ${Number(cerrada.counted_cash).toFixed(2)} — ` +
            `Diferencia: S/ ${Number(cerrada.difference).toFixed(2)}`,
            'success'
        );
        await cargar();
    } catch (e: any) {
        (Swal as TVueSwalInstance).fire('Error', e.response?.data?.message ?? 'No se pudo cerrar la caja.', 'error');
    } finally {
        closing.value = false;
    }
}

onMounted(cargar);
</script>
