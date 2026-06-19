<template>
<div class="container-fluid py-4">

    <div class="mb-4">
        <small class="text-danger fw-bold text-uppercase">
            Bienvenido de vuelta
        </small>

        <h1 class="fw-bold text-white">
            ¡Hola, {{ clientAuth.client?.name }}! 👋
        </h1>
    </div>

    <div class="row g-4">

        <div class="col-md-3">
            <div class="card dashboard-card h-100">
                <div class="card-body">

                    <i class="fas fa-box fs-3 text-white"></i>

                    <h2 class="text-danger mt-3">
                        {{ stats.total_pedidos }}
                    </h2>

                    <p class="text-secondary mb-0">
                        Pedidos Totales
                    </p>

                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card dashboard-card h-100">
                <div class="card-body">

                    <i class="fas fa-sack-dollar fs-3 text-white"></i>

                    <h2 class="text-success mt-3">
                        S/ {{ Number(stats.total_gastado).toFixed(2) }}
                    </h2>

                    <p class="text-secondary mb-0">
                        Total Gastado
                    </p>

                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card dashboard-card h-100">
                <div class="card-body">

                    <i class="fas fa-clock fs-3 text-white"></i>

                    <h2 class="text-warning mt-3">
                        {{ stats.pendientes }}
                    </h2>

                    <p class="text-secondary mb-0">
                        Pedidos Pendientes
                    </p>

                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card dashboard-card h-100">
                <div class="card-body">

                    <i class="fas fa-check-circle fs-3 text-white"></i>

                    <h2 class="text-info mt-3">
                        {{ stats.entregados }}
                    </h2>

                    <p class="text-secondary mb-0">
                        Pedidos Entregados
                    </p>

                </div>
            </div>
        </div>

    </div>

</div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import publicHttpClient from '@/helpers/publicHttpClient'
import { useClientAuthStore } from '@/stores/clientAuth'

const clientAuth = useClientAuthStore()

const stats = ref({
    total_pedidos: 0,
    total_gastado: 0,
    pendientes: 0,
    entregados: 0
})

const cargarDashboard = async () => {
    try {

        const response =
            await publicHttpClient.get('/portal/orders')

        stats.value.total_pedidos = response.data.total_pedidos
        stats.value.total_gastado = response.data.total_gastado
        stats.value.pendientes = response.data.pendientes
        stats.value.entregados = response.data.entregados

    } catch (error) {
        console.error(error)
    }
}

onMounted(() => {
    cargarDashboard()
})
</script>

<style scoped>
.dashboard-card{
    background:#0f0f0f;
    border:1px solid #242424;
    border-radius:20px;
    color:white;
    transition:.3s;
}

.dashboard-card:hover{
    transform:translateY(-4px);
    border-color:#dc3545;
}
</style>