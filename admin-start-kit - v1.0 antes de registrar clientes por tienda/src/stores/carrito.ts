// stores/carrito.js
import { defineStore } from 'pinia'

export const useCarritoStore = defineStore('carrito', {
  state: () => ({
    items: [] // cada item: { id, nombre, precio, imagen, cantidad }
  }),
  getters: {
    totalItems: (state) => state.items.reduce((sum, item) => sum + item.cantidad, 0),
    totalPrecio: (state) => state.items.reduce((sum, item) => sum + (item.precio * item.cantidad), 0),
    isInCart: (state) => (id) => state.items.some(item => item.id === id) // ← nuevo
  },
  actions: {
    agregarProducto(producto, cantidad = 1) {
      const existente = this.items.find(item => item.id === producto.id)
      if (existente) {
        existente.cantidad += cantidad
      } else {
        this.items.push({ ...producto, cantidad })
      }
    },
    eliminarProducto(id) {
      this.items = this.items.filter(item => item.id !== id)
    },
    vaciarCarrito() {
      this.items = []
    }
  }
})