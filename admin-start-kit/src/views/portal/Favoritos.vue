<template>
  <div class="container py-5">
    <h2 class="text-white mb-4">Mis favoritos</h2>
    <div v-if="favoritosStore.items.length === 0" class="alert alert-info">
      No tienes productos favoritos aún.
    </div>
    <div class="row g-4" v-else>
      <div v-for="item in favoritosStore.items" :key="item.id" class="col-md-4 col-lg-3">
        <div class="card bg-dark text-white border-secondary h-100">
          <img :src="item.imagen" class="card-img-top" :alt="item.nombre">
          <div class="card-body">
            <h5 class="card-title">{{ item.nombre }}</h5>
            <p class="card-text text-danger fw-bold">S/ {{ item.precio }}</p>
            <div class="d-flex gap-2">
              <button class="btn btn-outline-danger btn-sm" @click="favoritosStore.removeFavorito(item.id)">
                <i class="bi bi-heart-broken"></i> Quitar
              </button>
              <button class="btn btn-danger btn-sm" @click="agregarAlCarrito(item)">
                <i class="bi bi-cart-plus"></i> Comprar
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
//import { useFavoritosStore } from '@/stores/favoritos'
import { useCarritoStore } from '@/stores/carrito'

/* const favoritosStore = useFavoritosStore()
const carritoStore = useCarritoStore()


const agregarAlCarrito = (producto) => {
  carritoStore.agregarProducto(producto)
  // opcional: notificación
} */
</script>