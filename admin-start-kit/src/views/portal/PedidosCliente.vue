<!-- frontend/src/views/portal/PedidoCliente.vue -->
<template>
  <div class="card bg-dark text-white border-secondary">
    <div class="card-header bg-black border-secondary">
      <h5 class="mb-0">Mis pedidos</h5>
    </div>
    <div class="card-body">
      <div v-if="loading" class="text-center py-4">
        <div class="spinner-border text-danger"></div>
      </div>
      <div v-else-if="pedidos.length === 0" class="alert alert-info">
        No has realizado ningún pedido aún.
      </div>
      <div v-else>
        <div v-for="pedido in pedidos" :key="pedido.id"
          class="pedido-item mb-3 p-3 bg-dark-2 rounded-3 border border-secondary">
          <div class="d-flex justify-content-between align-items-start flex-wrap">
            <div>
              <strong>ORDEN N°-000{{ pedido.id }}</strong>
              <span class="badge ms-2" :class="estadoBadge(pedido.estado)">{{ pedido.estado }}</span>
              <div class="small text-muted">{{ formatDate(pedido.created_at) }}</div>
            </div>
            <div class="text-end">
              <div class="fw-bold text-danger">S/ {{ Number(pedido.total).toFixed(2) }}</div>
              <button class="btn btn-sm btn-outline-danger mt-1" @click="DescargarComprobante(pedido.id)">📄
                Comprobante</button>
            </div>
          </div>
          <div class="container">
            <div class="row align-items-start">
              <strong>Productos:</strong>
              <div class="col-1   ">Cant.</div>
              <div class="col-3   ">Descripción</div>
            </div>
            <div class="row align-items-center" v-for="item in pedido.items" :key="item.id">
              <div class=" col col-1 ">
                <span class="badge bg-dark">{{ item.cantidad }}</span>
              </div>
              <div class="col col-3 "><span class="badge bg-dark">{{ item.product?.title || item.nombre }}</span>
              </div>
            </div>
          </div>

          <hr class="border-secondary my-2">
          <div class="small text-muted">
            {{ pedido.items?.length || 0 }} producto(s) - Método de pago: {{ pedido.metodo_pago }}
          </div>
        </div>

        <!-- Paginación si es necesario -->
        <nav v-if="lastPage > 1" class="mt-3">
          <ul class="pagination justify-content-center">
            <li class="page-item" :class="{ disabled: currentPage === 1 }">
              <button class="page-link bg-dark border-secondary"
                @click="cambiarPagina(currentPage - 1)">Anterior</button>
            </li>
            <li class="page-item" v-for="p in lastPage" :key="p" :class="{ active: p === currentPage }">
              <button class="page-link bg-dark border-secondary" @click="cambiarPagina(p)">{{ p }}</button>
            </li>
            <li class="page-item" :class="{ disabled: currentPage === lastPage }">
              <button class="page-link bg-dark border-secondary"
                @click="cambiarPagina(currentPage + 1)">Siguiente</button>
            </li>
          </ul>
        </nav>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useClientAuthStore } from '@/stores/clientAuth'
import publicHttpClient from '@/helpers/publicHttpClient'

interface PedidoItem {
  id: number
  cantidad: number
  nombre?: string
  product?: { title: string }
}

interface Pedido {
  id: number
  estado: string
  created_at: string
  total: number
  metodo_pago: string
  items: PedidoItem[]
}


const clientAuth = useClientAuthStore()
const router = useRouter()
const pedidos = ref<Pedido[]>([])
const loading = ref(true)
const currentPage = ref(1)
const lastPage = ref(1)

const cargarPedidos = async (page = 1) => {
  loading.value = true
  try {
    const response = await publicHttpClient.get(`/portal/orders?page=${page}`)
    pedidos.value = response.data.orders.data as Pedido[]
    
    currentPage.value = response.data.orders.current_page
    lastPage.value = response.data.orders.last_page
  } catch (error) {
    console.error(error)
  } finally {
    loading.value = false
  }
}

const cambiarPagina = (page: number) => {
  if (page >= 1 && page <= lastPage.value) {
    cargarPedidos(page)
  }
}

const DescargarComprobante = (id: number) => {
  //router.push(`/micuenta/pedidos/${id}`)
  window.open(`/api/sales-pdf/${id}`, '_blank')
}

const formatDate = (date: string) => {
  return new Date(date).toLocaleDateString('es-PE')
}

const estadoBadge = (estado: string) : string => {
  const map: Record<string, string> = {
    pendiente: 'bg-warning text-dark',
    pagado: 'bg-success',
    cancelado: 'bg-danger',
    entregado: 'bg-info'
  }
  return map[estado] || 'bg-secondary'
}

onMounted(() => {
  if (!clientAuth.isAuthenticated) {
    router.push('/login')
  } else {
    cargarPedidos()
  }
})
</script>