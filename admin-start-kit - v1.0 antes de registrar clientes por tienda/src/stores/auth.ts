// stores/auth.js
import { defineStore } from 'pinia'
import httpClient from '@/helpers/http-client'
import publicHttpClient from '@/helpers/publicHttpClient'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    token: localStorage.getItem('token') || null,
    loading: false,
  }),
  getters: {
    isAuthenticated: (state) => !!state.token,
    userName: (state) => state.user?.name || state.user?.nombres || '',
  },
  actions: {
    async login(credentials) {
      this.loading = true
      try {
        const response = await publicHttpClient.post('/auth/login', credentials)
       this.token = response.data.access_token
        localStorage.setItem('token', this.token)
        httpClient.defaults.headers.common['Authorization'] = `Bearer ${this.token}`
        await this.fetchUser()
        return true
      } catch (error) {
        console.error(error)
        throw error
      } finally {
        this.loading = false
      }
    },
    async register(userData) {
      this.loading = true
      try {
        const response = await publicHttpClient.post('/auth/register', userData)
        this.token = response.data.access_token
        localStorage.setItem('token', this.token)
        httpClient.defaults.headers.common['Authorization'] = `Bearer ${this.token}`
        await this.fetchUser()
        return true
      } catch (error) {
        console.error(error)
        throw error
      } finally {
        this.loading = false
      }
    },
    async fetchUser() {
      if (!this.token) return
      try {
        const response = await httpClient.get('/auth/me')
        this.user = response.data
      } catch (error) {
        this.logout()
      }
    },
    logout() {
      this.user = null
      this.token = null
      localStorage.removeItem('token')
      delete httpClient.defaults.headers.common['Authorization']
    }
  }
})