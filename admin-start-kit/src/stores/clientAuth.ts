//este archivo es para hacer peticiones a la api cuando se registra, iniciar sesion o crear un nuevo cliente
import { defineStore } from 'pinia'
import publicHttpClient from '@/helpers/publicHttpClient'


export interface Client {
    full_name?: string;
    surname: string;
    name: string;
    email: string;
    phone?: string;
    n_document?: string;
    address?: string;
    ubigeo_region?: string;
    ubigeo_provincia?: string;
    ubigeo_distrito?: string;
    region?: string;
    provincia?: string;
    distrito?: string;
    birth_date?: string;
    gender?: string;
    type_client?: string;
    type_document?: string;
    state?: number;
    created_at?: string;

}

export const useClientAuthStore = defineStore('clientAuth', {

    state: () => ({
        client: JSON.parse(localStorage.getItem('client_data') || 'null'),
        token: localStorage.getItem('client_token') || null,
    }),
    getters: {
        isAuthenticated: (state) => !!state.token,
    },
    actions: {
        async login(credentials: { email: string; password: string }) {// aqui lo que hace es recibir el email y la contraseña que viene de la pagina de login
            const response = await publicHttpClient.post('/portal/login', credentials)//depues lo que hace es hacer la peticion a la api
            this.token = response.data.access_token//aqui lo que hace es guardar el token que viene de la api en la variable token
            this.client = response.data.client as Client //aqui lo que hace es convertir el objeto que viene de la api en un objeto de tipo Client
            localStorage.setItem('client_token', response.data.access_token)//aqui lo que hace es guardar el token en el localstorage
            localStorage.setItem('client_data', JSON.stringify(response.data.client))//JSON.stringify es para convertir el objeto en una cadena
            publicHttpClient.defaults.headers.common['Authorization'] = `Bearer ${this.token}`//aqui lo que hace es guardar el token en la cabecera de la peticion
            return true
        },
        async register(clientData: any) {
            const response = await publicHttpClient.post('/portal/register', clientData)
            this.token = response.data.access_token
            this.client = response.data.client
            localStorage.setItem('client_token', response.data.access_token)
            localStorage.setItem('client_data', JSON.stringify(response.data.client))
            return true
        },
        async fetchClient() {
            if (!this.token) return
            const response = await publicHttpClient.get('/portal/me', {
                headers: { Authorization: `Bearer ${this.token}` }
            })
            this.client = response.data
            localStorage.setItem(//aqui lo que hace es guardar el token en el localstorage para mantener actualizada el localstorage
                'client_data',
                JSON.stringify(response.data)
            )
        },

        async initializeAuth() {//esta funcion es para inicializar la autenticacion cuando esta iniciada la sesion
            if (!this.token) { return }

            try {
                const response = await publicHttpClient.get('/portal/me', {
                    headers: {
                        Authorization: `Bearer ${this.token}`
                    }
                })

                this.client = response.data

                localStorage.setItem(
                    'client_data',
                    JSON.stringify(response.data)
                )

            } catch (error) {

                console.log('Sesión expirada')

                this.logout()
            }
        },
        logout() {
            this.client = null
            this.token = null

            localStorage.removeItem('client_token')
            localStorage.removeItem('client_data')

            delete publicHttpClient.defaults.headers.common['Authorization']
        },
        async fetchOrders(page = 1) {//esta funcion es para hacer peticiones a la api 
            const response = await publicHttpClient.get(`/portal/orders?page=${page}`, {
                headers: { Authorization: `Bearer ${this.token}` }
            });
            return response.data;
        },
        async fetchOrderDetail(orderId: number) {
            const response = await publicHttpClient.get(`/client/orders/${orderId}`, {
                headers: { Authorization: `Bearer ${this.token}` }
            });
            return response.data;
        },
    }
})