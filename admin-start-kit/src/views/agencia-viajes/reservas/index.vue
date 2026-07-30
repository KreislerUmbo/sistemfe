<template>
    <DefaultLayout>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-suitcase-rolling me-2 text-primary"></i>
                    Reservas
                </h5>
                <small class="text-muted">{{ total }} registro(s) encontrado(s)</small>
            </div>
            <router-link to="/agencia-viajes/venta-directa" class="btn btn-primary fw-semibold shadow-sm">
                <i class="fas fa-bolt me-2"></i>Venta Directa
            </router-link>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-12 col-md-8">
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control" placeholder="Buscar por código, destino, cliente o documento..." v-model="search" @keyup.enter="list">
                            <button class="btn btn-primary" @click="list"><i class="fas fa-search"></i></button>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <select class="form-select form-select-sm" v-model="estado" @change="list">
                            <option value="">Todos los estados</option>
                            <option value="activa">Activa</option>
                            <option value="cancelada">Cancelada</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr class="small text-secondary text-uppercase">
                                <th class="ps-3">Cotización</th>
                                <th>Cliente</th>
                                <th>Destino</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center pe-3">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading">
                                <td colspan="5" class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Cargando...</td>
                            </tr>
                            <tr v-else-if="reservas.length === 0">
                                <td colspan="5" class="text-center py-5 text-muted fst-italic">Sin reservas registradas.</td>
                            </tr>
                            <tr v-for="reserva in reservas" :key="reserva.id">
                                <td class="ps-3 fw-semibold">{{ reserva.alternativa?.cotizacion?.codigo }}</td>
                                <td>{{ reserva.alternativa?.cotizacion?.cliente?.full_name }}</td>
                                <td>{{ reserva.alternativa?.cotizacion?.destino }}</td>
                                <td class="text-center">
                                    <span class="badge" :class="reserva.estado === 'activa' ? 'bg-success' : 'bg-danger'">
                                        {{ reserva.estado === 'activa' ? 'Activa' : 'Cancelada' }}
                                    </span>
                                </td>
                                <td class="text-center pe-3">
                                    <router-link :to="`/agencia-viajes/reservas/${reserva.id}`" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-arrow-right me-1"></i>Abrir
                                    </router-link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import { reservaService } from '@/services/admin/reservaService';
import type { Reserva } from '@/types/agencia-viajes';

const reservas = ref<Reserva[]>([]);
const search = ref<string>('');
const estado = ref<'' | 'activa' | 'cancelada'>('');
const total = ref<number>(0);
const loading = ref<boolean>(false);

const list = async () => {
    loading.value = true;
    try {
        const res = await reservaService.listar({ search: search.value || undefined, estado: (estado.value || undefined) as any });
        reservas.value = res.reservas;
        total.value = res.total;
    } finally {
        loading.value = false;
    }
};

onMounted(() => list());
</script>
