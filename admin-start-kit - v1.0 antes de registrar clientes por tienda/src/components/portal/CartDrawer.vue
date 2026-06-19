<!-- frontend/src/components/Portal/CartDrawer.vue -->
<template>
  <!-- Overlay -->
  <Transition name="drawer">
    <div v-if="uiStore.cartDrawerOpen" class="cart-drawer-overlay" @click="closeDrawer"></div>
  </Transition>

  <!-- Drawer -->
  <Transition name="drawer-slide">
    <div v-if="uiStore.cartDrawerOpen" class="cart-drawer position-fixed top-0 end-0 h-100 bg-dark shadow-lg d-flex flex-column" style="width: 420px; z-index: 1050;">
      
      <!-- Header fijo -->
      <div class="d-flex justify-content-between align-items-center p-4 border-bottom border-secondary flex-shrink-0">
        <div class="d-flex gap-2 align-items-center">
          <h4 class="m-0 fw-bold text-danger">MI CARRITO</h4>
          <span class="badge bg-danger rounded-pill">{{ totalItems }} items</span>
        </div>
        <button class="btn-close btn-close-white" @click="closeDrawer"></button>
      </div>

      <!-- Cupón (fijo) 
      <div class="p-3 border-bottom border-secondary flex-shrink-0">
        <div class="input-group">
          <input type="text" class="form-control bg-dark text-white border-secondary" placeholder="🏷️ Código de descuento..." v-model="couponInput">
          <button class="btn btn-outline-danger" @click="applyCouponCode">Aplicar</button>
        </div>
      </div>
-->
      <!-- Items del carrito (scrollable) -->
      <div class="flex-grow-1 overflow-auto p-3">
        <div v-if="cartStore.items.length === 0" class="text-center py-5">
          <i class="bi bi-cart-x display-1 text-muted"></i>
          <p class="mt-3">Tu carrito está vacío</p>
          <button class="btn btn-danger" @click="closeDrawer">Explorar productos</button>
        </div>
        <div v-else>
          <div v-for="item in cartStore.items" :key="item.id" class="cart-item mb-3 p-3 bg-dark-2 rounded-3 border border-secondary">
            <!-- contenido del item (igual que antes) -->
            <div class="d-flex gap-3">
              <img :src="item.imagen" class="rounded-3" style="width: 70px; height: 70px; object-fit: cover;">
              <div class="flex-grow-1">
                <div class="d-flex justify-content-between">
                  <h6 class="mb-0 fw-bold text-white">{{ item.nombre }}</h6>
                 <div><button class="btn btn-sm btn-outline-danger" @click="cartStore.eliminarProducto(item.id)">  <i class="bi bi-trash"></i></button></div> 
                </div>
                <small class="text-muted">Precio unitario: S/ {{ item.precio.toFixed(2) }}</small>
                <div class="d-flex justify-content-between align-items-center mt-2">
                  <div class="input-group input-group-sm" style="width: 100px;">
                    <button class="btn btn-outline-secondary" @click="item.cantidad > 1 && item.cantidad--">-</button>
                    <input type="number" class="form-control text-center bg-dark text-white border-secondary" v-model.number="item.cantidad" min="1" max="99">
                    <button class="btn btn-outline-secondary" @click="item.cantidad++">+</button>
                  </div>
                  <strong class="text-danger">S/ {{ (item.precio * item.cantidad).toFixed(2) }}</strong>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Footer con resumen y botones (siempre visible al final) -->
      <div class="border-top border-secondary p-3 flex-shrink-0" v-if="cartStore.items.length">
        <!-- Detalles de precios -->
        <div class="mb-2">
          <div class="d-flex justify-content-between small text-muted">
            <span>Subtotal ({{ totalItems }} productos)</span>
            <span>S/ {{ subtotal.toFixed(2) }}</span>
          </div>
          <div v-if="descuentoProductos > 0" class="d-flex justify-content-between small text-success">
            <span>🏷️ Descuento en productos</span>
            <span>- S/ {{ descuentoProductos.toFixed(2) }}</span>
          </div>
          <div v-if="uiStore.couponApplied" class="d-flex justify-content-between small text-warning">
            <span>🎟️ Cupón TECH20 (20%)</span>
            <span>- S/ {{ descuentoCupon.toFixed(2) }}</span>
          </div>
          <div class="d-flex justify-content-between small text-muted">
            <span>🚚 Envío</span>
            <span class="text-success">Gratis</span>
          </div>
          <hr class="my-2">
          <div class="d-flex justify-content-between fw-bold">
            <span>Total a pagar</span>
            <span class="h5 m-0 text-danger">S/ {{ totalConDescuento.toFixed(2) }}</span>
          </div>
          <small class="text-muted d-block text-end">Incluye IGV: S/ {{ igv.toFixed(2) }}</small>
        </div>

        <!-- Métodos de pago -->
        <div class="mb-3">
          <label class="form-label small fw-bold">Método de pago</label>
          <div class="d-flex gap-2">
            <button v-for="metodo in metodosPago" :key="metodo.id" class="btn btn-sm flex-grow-1" :class="uiStore.selectedPaymentMethod === metodo.id ? 'btn-danger' : 'btn-outline-secondary'" @click="uiStore.setPaymentMethod(metodo.id)">
              <i :class="metodo.icono"></i> {{ metodo.label }}
            </button>
          </div>
        </div>

        <!-- Botones acción -->
        <button class="btn btn-danger w-100 mb-2 py-2" @click="checkout">
          ⚡ Ir al checkout
        </button>
        <button class="btn btn-outline-light w-100 py-2" @click="closeDrawer">
          ← Seguir comprando
        </button>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useCarritoStore } from '@/stores/carrito'
import { useUiStore } from '@/stores/ui'

const cartStore = useCarritoStore()
const uiStore = useUiStore()

const router = useRouter()
const couponInput = ref('')
const metodosPago = [
  { id: 'tarjeta', label: 'Tarjeta', icono: 'bi bi-credit-card' },
  { id: 'yape', label: 'Yape/Plin', icono: 'bi bi-phone' },
  { id: 'transferencia', label: 'Transferencia', icono: 'bi bi-bank2' },
  { id: 'efectivo', label: 'Efectivo', icono: 'bi bi-cash' }
]

// Computed para resúmenes
const totalItems = computed(() => cartStore.totalItems)
const subtotal = computed(() => cartStore.totalPrecio)

// Descuento de productos (si tuvieras ofertas)
const descuentoProductos = computed(() => {
  // Aquí podrías sumar descuentos individuales de cada producto (ej. precioOriginal - precio)
  return 0
})

const descuentoCupon = computed(() => {
  if (!uiStore.couponApplied) return 0
  return (subtotal.value - descuentoProductos.value) * uiStore.couponDiscount
})

const totalConDescuento = computed(() => {
  return subtotal.value - descuentoProductos.value - descuentoCupon.value
})

const igv = computed(() => totalConDescuento.value * 0.18)

// Métodos
const applyCouponCode = () => {
  const success = uiStore.applyCoupon(couponInput.value.toUpperCase())
  if (success) {
    couponInput.value = ''
    // Podrías mostrar un toast
  } else {
    alert('Código inválido')
  }
}

const closeDrawer = () => {
  uiStore.closeCartDrawer()
}

const checkout = () => {
  // Redirigir a la página de checkout
   router.push('/checkout')
  closeDrawer()
}
</script>

<style scoped>
/* Solo transiciones y ajustes mínimos */
.cart-drawer-overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.6);
  backdrop-filter: blur(3px);
  z-index: 1040;
}

.cart-drawer {
  z-index: 1050;
  transition: transform 0.3s ease;
}

.drawer-enter-active, .drawer-leave-active {
  transition: opacity 0.3s ease;
}
.drawer-enter-from, .drawer-leave-to {
  opacity: 0;
}
.drawer-slide-enter-active, .drawer-slide-leave-active {
  transition: transform 0.3s ease;
}
.drawer-slide-enter-from, .drawer-slide-leave-to {
  transform: translateX(100%);
}
</style>