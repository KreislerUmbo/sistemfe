<template>
  <div class="card bg-dark text-white border-secondary">
    <div class="card-header bg-black border-secondary d-flex justify-content-between align-items-center">
      <h5 class="mb-0">Pedido #{{ pedido.order_number || pedido.id }}</h5>
      <button class="btn btn-sm btn-outline-danger" @click="$router.back()">Volver</button>
    </div>
    <div class="card-body">
      <div v-if="loading" class="text-center py-4">
        <div class="spinner-border text-danger"></div>
      </div>
      <div v-else>
        <div class="row mb-4">
          <div class="col-md-6">
            <p><strong>Fecha:</strong> {{ new Date(pedido.created_at).toLocaleDateString() }}</p>
            <p><strong>Estado:</strong> <span class="badge" :class="estadoClass(pedido.estado)">{{ pedido.estado }}</span></p>
            <p><strong>Método de pago:</strong> {{ pedido.metodo_pago }}</p>
            <p><strong>Comprobante:</strong> {{ pedido.comprobante_tipo }}</p>
          </div>
          <div class="col-md-6 text-md-end">
            <p><strong>Total:</strong> <span class="text-danger fs-4">S/ {{ pedido.total.toFixed(2) }}</span></p>
            <button class="btn btn-sm btn-outline-success mt-2" @click="descargarComprobante">📄 Descargar comprobante</button>
          </div>
        </div>
        <h6>Productos</h6>
        <div class="table-responsive">
          <table class="table table-dark">
            <thead>
              <tr><th>Producto</th><th>Cantidad</th><th>Precio</th><th>Subtotal</th></tr>
            </thead>
            <tbody>
              <tr v-for="item in pedido.items" :key="item.id">
                <td>{{ item.product?.title || item.nombre }}</td>
                <td>{{ item.cantidad }}</td>
                <td>S/ {{ item.precio_unitario?.toFixed(2) }}</td>
                <td>S/ {{ item.subtotal?.toFixed(2) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="pedido.direccion" class="mt-3">
          <h6>Dirección de envío</h6>
          <p>{{ pedido.direccion }}<br>{{ pedido.distrito }}, {{ pedido.provincia }}, {{ pedido.departamento }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useClientAuthStore } from '@/stores/clientAuth'

const route = useRoute()
const clientAuth = useClientAuthStore()
const pedido = ref<any>({})
const loading = ref(true)

const estadoClass = (estado: string) => {
  const clases: Record<string, string> = {
    pendiente: 'bg-warning text-dark',
    pagado: 'bg-success',
    cancelado: 'bg-danger',
    entregado: 'bg-info'
  }
  return clases[estado] || 'bg-secondary'
}

const cargarDetalle = async () => {
  const id = Number(route.params.id)
  try {
    pedido.value = await clientAuth.fetchOrderDetail(id)
  } catch (error) {
    console.error(error)
  } finally {
    loading.value = false
  }
}

const descargarComprobante = () => {
  // Redirigir a la ruta del PDF
  window.open(`/api/sales-pdf/${pedido.value.id}`, '_blank')
}

onMounted(() => {
  cargarDetalle()
})
</script>