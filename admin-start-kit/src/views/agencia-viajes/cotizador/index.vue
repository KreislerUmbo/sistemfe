<template>
    <DefaultLayout>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fas fa-route me-2 text-primary"></i>
                    Cotizaciones
                </h5>
                <small class="text-muted">{{ totalPages }} registro(s) encontrado(s)</small>
            </div>
            <router-link to="/agencia-viajes/cotizador/nueva" class="btn btn-primary fw-semibold shadow-sm">
                <i class="fas fa-plus me-2"></i>Nueva Cotización
            </router-link>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control" placeholder="Buscar por código o destino..." v-model="search" @keyup.enter="list">
                    <button class="btn btn-primary" @click="list"><i class="fas fa-search"></i></button>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr class="small text-secondary text-uppercase">
                                <th class="ps-3">Código</th>
                                <th>Cliente</th>
                                <th>Destino</th>
                                <th class="text-center">Alternativas</th>
                                <th class="text-center pe-3">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading">
                                <td colspan="5" class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Cargando...</td>
                            </tr>
                            <tr v-else-if="cotizaciones.length === 0">
                                <td colspan="5" class="text-center py-5 text-muted fst-italic">Sin cotizaciones registradas.</td>
                            </tr>
                            <tr v-for="cotizacion in cotizaciones" :key="cotizacion.id">
                                <td class="ps-3 fw-semibold">{{ cotizacion.codigo }}</td>
                                <td>{{ cotizacion.cliente?.full_name }}</td>
                                <td>{{ cotizacion.destino }}</td>
                                <td class="text-center">{{ cotizacion.alternativas_count ?? 0 }}</td>
                                <td class="text-center pe-3">
                                    <router-link :to="`/agencia-viajes/cotizador/${cotizacion.id}`" class="btn btn-sm btn-outline-primary">
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
import { cotizacionService } from '@/services/admin/cotizacionService';
import type { Cotizacion } from '@/types/agencia-viajes';

const cotizaciones = ref<Cotizacion[]>([]);
const search = ref<string>('');
const totalPages = ref<number>(0);
const loading = ref<boolean>(false);

const list = async () => {
    loading.value = true;
    try {
        const res = await cotizacionService.listar({ search: search.value || undefined });
        cotizaciones.value = res.cotizaciones;
        totalPages.value = res.total;
    } finally {
        loading.value = false;
    }
};

onMounted(() => list());
</script>
