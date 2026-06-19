// stores/carrito.js
import { defineStore } from 'pinia'

export interface CarritoItem {
  id: number
  nombre: string
  precio: number
  imagen: string
  cantidad: number
}

export const useCarritoStore = defineStore('carrito', {
  state: () => ({
    items: [] as CarritoItem[] // cada item: { id, nombre, precio, imagen, cantidad }
  }),
  getters: {
    totalItems: (state) => state.items.reduce((sum, item) => sum + item.cantidad, 0),
    totalPrecio: (state) => state.items.reduce((sum, item) => sum + (item.precio * item.cantidad), 0),
    isInCart: (state) => (id: number) => state.items.some(item => item.id === id) // verificar si un producto está en el carrito
  },
  actions: {
    agregarProducto(producto: CarritoItem, cantidad = 1) {
      const existente = this.items.find(item => item.id === producto.id)
      if (existente) {
        existente.cantidad += cantidad
      } else {
        this.items.push({ ...producto, cantidad })
      }
    },
    eliminarProducto(id: number) {
      this.items = this.items.filter(item => item.id !== id)
    },
    vaciarCarrito() {
      this.items = []
    }
  }
})