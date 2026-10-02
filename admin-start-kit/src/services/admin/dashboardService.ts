// Inicio con datos reales del tenant (GET /dashboard). La caja reutiliza cash/dashboard.
import httpClient from '@/helpers/http-client'
import type { Dashboard } from '@/types/dashboard'
import type { CashDashboardResponse } from '@/types/cash-session'

export const dashboardService = {
  async inicio() {
    const { data } = await httpClient.get('/dashboard')
    return data as Dashboard
  },

  /** Solo con cash.view_all. */
  async cajas() {
    const { data } = await httpClient.get('/cash/dashboard')
    return data as CashDashboardResponse
  },
}
