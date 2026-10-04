<template>
  <div>
    <div class="d-flex flex-wrap gap-2 mb-2">
      <button type="button" class="btn btn-sm btn-outline-primary" :disabled="ubicando || !gpsDisponible" @click="usarMiUbicacion">
        <span v-if="ubicando" class="spinner-border spinner-border-sm me-1"></span>
        <i v-else class="fas fa-location-arrow me-1"></i>Usar mi ubicación
      </button>
      <a v-if="enlace" :href="enlace" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-external-link-alt me-1"></i>Abrir en Google Maps
      </a>
      <button v-if="tienePunto" type="button" class="btn btn-sm btn-outline-secondary ms-auto" @click="quitar">
        <i class="fas fa-times me-1"></i>Quitar punto
      </button>
    </div>
    <div ref="contenedor" class="mapa rounded border" role="application" aria-label="Mapa: toca para marcar la ubicación del cliente"></div>
    <small class="text-muted d-block mt-1">
      <template v-if="!gpsDisponible">El GPS del navegador solo funciona con conexión segura (https). </template>
      Toca el mapa para marcar el punto; puedes arrastrarlo para ajustarlo.
      <template v-if="tienePunto"> {{ modelo.latitud }}, {{ modelo.longitud }}</template>
    </small>
    <div v-if="errorGps" class="small text-danger mt-1">{{ errorGps }}</div>
  </div>
</template>

<script setup lang="ts">
// Ubicación del cliente para cobranza (Fase 4c): OpenStreetMap (sin API key ni costo).
// Las coordenadas viajan como texto con 7 decimales, igual que credito_cliente_fichas.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import L from 'leaflet'
import iconoUrl from 'leaflet/dist/images/marker-icon.png'
import icono2xUrl from 'leaflet/dist/images/marker-icon-2x.png'
import sombraUrl from 'leaflet/dist/images/marker-shadow.png'
import { enlaceMapa } from '@/helpers/creditos/cobranza'

export interface Coordenadas { latitud: string | null; longitud: string | null }

const modelo = defineModel<Coordenadas>({ required: true })
const props = withDefaults(defineProps<{ direccion?: string | null }>(), { direccion: null })

// Perú completo mientras no haya punto; con punto, nivel de calle.
const CENTRO_PERU: L.LatLngTuple = [-9.19, -75.0152]
const ZOOM_PAIS = 5
const ZOOM_CALLE = 17
// Vite no resuelve las rutas del ícono por defecto de Leaflet: se importan las imágenes.
const ICONO = L.icon({
  iconUrl: iconoUrl, iconRetinaUrl: icono2xUrl, shadowUrl: sombraUrl,
  iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41],
})

const contenedor = ref<HTMLDivElement | null>(null)
let mapa: L.Map | null = null
let marcador: L.Marker | null = null
let observador: ResizeObserver | null = null
const ubicando = ref(false)
const errorGps = ref<string | null>(null)
const gpsDisponible = typeof window !== 'undefined' && window.isSecureContext && 'geolocation' in navigator

const punto = computed<L.LatLngTuple | null>(() => {
  const lat = Number.parseFloat(modelo.value.latitud ?? '')
  const lng = Number.parseFloat(modelo.value.longitud ?? '')
  return Number.isFinite(lat) && Number.isFinite(lng) ? [lat, lng] : null
})
const tienePunto = computed(() => punto.value !== null)
const enlace = computed(() => enlaceMapa({ latitud: modelo.value.latitud, longitud: modelo.value.longitud, direccion_cobro: props.direccion }))

function fijar(lat: number, lng: number) {
  modelo.value = { latitud: lat.toFixed(7), longitud: lng.toFixed(7) }
}

function dibujar(centrar: boolean) {
  if (!mapa) return
  if (!punto.value) {
    marcador?.remove()
    marcador = null
    return
  }
  if (!marcador) {
    marcador = L.marker(punto.value, { draggable: true, icon: ICONO, keyboard: true, title: 'Ubicación del cliente' }).addTo(mapa)
    marcador.on('dragend', () => {
      const { lat, lng } = marcador!.getLatLng()
      fijar(lat, lng)
    })
  } else {
    marcador.setLatLng(punto.value)
  }
  if (centrar) mapa.setView(punto.value, Math.max(mapa.getZoom(), ZOOM_CALLE))
}

onMounted(() => {
  if (!contenedor.value) return
  mapa = L.map(contenedor.value, { scrollWheelZoom: false }).setView(punto.value ?? CENTRO_PERU, punto.value ? ZOOM_CALLE : ZOOM_PAIS)
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
  }).addTo(mapa)
  mapa.on('click', (e: L.LeafletMouseEvent) => fijar(e.latlng.lat, e.latlng.lng))
  dibujar(false)
  // El mapa puede montarse dentro de una sección oculta o que cambia de ancho.
  observador = new ResizeObserver(() => mapa?.invalidateSize())
  observador.observe(contenedor.value)
})

onBeforeUnmount(() => {
  observador?.disconnect()
  mapa?.remove()
  mapa = null
  marcador = null
})

watch(punto, (nuevo, anterior) => dibujar(anterior === null && nuevo !== null))

function usarMiUbicacion() {
  errorGps.value = null
  ubicando.value = true
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      ubicando.value = false
      fijar(pos.coords.latitude, pos.coords.longitude)
      mapa?.setView([pos.coords.latitude, pos.coords.longitude], ZOOM_CALLE)
    },
    (err) => {
      ubicando.value = false
      errorGps.value = err.code === err.PERMISSION_DENIED
        ? 'El navegador no dio permiso para usar la ubicación.'
        : 'No se pudo obtener la ubicación. Marca el punto en el mapa.'
    },
    { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
  )
}

function quitar() {
  modelo.value = { latitud: null, longitud: null }
}
</script>

<style scoped>
.mapa {
  height: 280px;
  z-index: 0;
}
</style>
