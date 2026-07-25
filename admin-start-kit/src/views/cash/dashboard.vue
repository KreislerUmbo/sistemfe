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
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="loading">
                            <td colspan="6" class="text-center py-4"><span class="spinner-border text-primary"></span></td>
                        </tr>
                        <tr v-else-if="!dashboard?.registers.length">
                            <td colspan="6" class="text-center text-muted py-4">No hay cajas activas configuradas.</td>
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
                            <td>{{ r.opened_at ?? '—' }}</td>
                            <td class="text-end">{{ r.elapsed_hours !== null ? `${r.elapsed_hours} h` : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import httpClient from '@/helpers/http-client';
import { ref, onMounted } from 'vue';
import Swal from 'sweetalert2/dist/sweetalert2.js';
import type { CashDashboardResponse } from '@/types/cash-session';

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

onMounted(cargar);
</script>
