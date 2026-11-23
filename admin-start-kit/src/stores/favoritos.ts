// stores/favoritos.ts
// Los favoritos viven en memoria (se pierden al recargar). Hoy ninguna pantalla llama a
// toggleFavorito(): la página «Mis favoritos» muestra la lista vacía.
import { defineStore } from 'pinia'

export interface FavoritoItem {
  id: number
  nombre: string
  precio: number
  imagen: string
  slug?: string
}

export const useFavoritosStore = defineStore('favoritos', {
  state: () => ({
    items: [] as FavoritoItem[]
  }),
  getters: {
    totalItems: (state) => state.items.length,
    isFavorite: (state) => (id: number) => state.items.some(item => item.id === id)
  },
  actions: {
    toggleFavorito(producto: FavoritoItem) {
      const exists = this.items.find(item => item.id === producto.id)
      if (exists) {
        this.items = this.items.filter(item => item.id !== producto.id)
      } else {
        this.items.push({ ...producto })
      }
    },
    removeFavorito(id: number) {
      this.items = this.items.filter(item => item.id !== id)
    }
  }
})
