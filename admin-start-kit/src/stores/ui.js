
// frontend/src/stores/ui.js
import { defineStore } from 'pinia'

export const useUiStore = defineStore('ui', {
  state: () => ({
    cartDrawerOpen: false,
    couponCode: '',
    couponApplied: false,
    couponDiscount: 0, // porcentaje o monto fijo
    selectedPaymentMethod: 'tarjeta'
  }),
  actions: {
    toggleCartDrawer() {
      this.cartDrawerOpen = !this.cartDrawerOpen
    },
    openCartDrawer() {
      this.cartDrawerOpen = true
    },
    closeCartDrawer() {
      this.cartDrawerOpen = false
    },
    applyCoupon(code) {
      if (code === 'TECH20' && !this.couponApplied) {
        this.couponApplied = true
        this.couponDiscount = 0.20 // 20%
        return true
      }
      return false
    },
    clearCoupon() {
      this.couponApplied = false
      this.couponDiscount = 0
      this.couponCode = ''
    },
    setPaymentMethod(method) {
      this.selectedPaymentMethod = method
    }
  }
})