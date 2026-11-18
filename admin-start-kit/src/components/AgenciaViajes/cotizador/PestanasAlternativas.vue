<template>
    <CalculadoraTipoCambioModal v-if="mostrarCalculadoraTipoCambio && alternativaActiva"
        :moneda-cotizacion="alternativaActiva.moneda_cotizacion" :total-alternativa="totalLocal"
        @close="mostrarCalculadoraTipoCambio = false" />

    <!-- Pestañas de alternativas -->
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap" v-if="cotizacion">
        <span v-for="alt in cotizacion.alternativas" :key="alt.id" class="alt-pill position-relative badge rounded-pill px-3 py-2 d-inline-flex align-items-center gap-2"
            :class="[alt.id === alternativaActivaId ? 'bg-primary' : 'bg-light text-dark border', { 'alt-pill-reservada': alt.estado === 'aceptada' }]"
            style="cursor:pointer" @click="seleccionarAlternativa(alt.id)">
            <!-- T-080: indicador en la esquina, visible activa o no (el ✓ interno heredaba el color de la pestaña). -->
            <span v-if="alt.estado === 'aceptada'" class="badge rounded-pill bg-success position-absolute top-0 start-100 translate-middle alt-pill-marca" title="Alternativa reservada">
                <i class="fas fa-check"></i>
            </span>
            <span v-else-if="alt.estado === 'reemplazada'" class="badge rounded-pill bg-secondary position-absolute top-0 start-100 translate-middle alt-pill-marca" title="Alternativa reemplazada por una modificación">
                <i class="fas fa-history"></i>
            </span>
            <span>
                {{ alt.nombre }} · {{ alt.moneda_cotizacion }} {{ Number(alt.total).toFixed(0) }}
                <span v-if="alt.estado === 'aceptada' || alt.estado === 'reemplazada'" class="ms-1">
                    <template v-if="reservaDeAlternativa(alt)">
                        {{ alt.estado === 'reemplazada' ? 'Reemplazada' : 'Reservada' }} ·
                        <router-link :to="`/agencia-viajes/reservas/${reservaDeAlternativa(alt)!.id}`" class="text-reset fw-semibold" @click.stop>{{ reservaDeAlternativa(alt)!.codigo ?? `#${reservaDeAlternativa(alt)!.id}` }}</router-link>
                    </template>
                    <template v-else>{{ alt.estado === 'reemplazada' ? 'Reemplazada' : 'Reservada' }}</template>
                </span>
                <span v-else-if="alt.estado === 'descartada'" class="ms-1 opacity-50"><i class="fas fa-times-circle"></i></span>
            </span>
            <i v-if="alt.id === alternativaActivaId" class="fas fa-pen alt-pill-edit" title="Renombrar esta alternativa" @click.stop="renombrarAlternativa(alt)"></i>
            <!-- T-033: la moneda se cambia solo mientras la alternativa no tenga servicios. -->
            <select v-if="alt.id === alternativaActivaId && !modificacionInfo && !alternativaSoloLectura" class="form-select form-select-sm py-0 ps-2 pe-4 w-auto"
                style="font-size:.75rem" :value="alt.moneda_cotizacion" :disabled="(alt.items?.length ?? 0) > 0 || cambiandoMoneda"
                :title="(alt.items?.length ?? 0) > 0 ? 'Con servicios no se cambia la moneda: quítalos o crea otra alternativa' : 'Moneda de la alternativa'"
                @click.stop @change="cambiarMonedaAlternativa(alt, ($event.target as HTMLSelectElement).value as 'PEN' | 'USD')">
                <option value="PEN">PEN</option>
                <option value="USD">USD</option>
            </select>
            <span v-if="alt.id === alternativaActivaId && eliminandoAlternativa" class="spinner-border spinner-border-sm alt-pill-delete" style="opacity:1"></span>
            <!-- Una alternativa reservada o reemplazada ya generó su reserva: el servidor no la elimina. -->
            <i v-else-if="alt.id === alternativaActivaId && !['aceptada', 'reemplazada'].includes(alt.estado)" class="fas fa-trash alt-pill-delete" title="Eliminar esta alternativa" @click.stop="eliminarAlternativa"></i>
        </span>
        <!-- Punto F — siempre visible; deshabilitado con motivo al llegar al tope,
             en vez de desaparecer sin explicación. -->
        <span v-if="!modificacionInfo && !cotizacionPerdida" class="badge rounded-pill px-3 py-2"
            :class="alcanzoLimiteAlternativas ? 'bg-light text-muted border opacity-50' : 'bg-light text-dark border'"
            :style="alcanzoLimiteAlternativas ? 'cursor:not-allowed;border-style:dashed' : 'cursor:pointer;border-style:dashed'"
            :title="alcanzoLimiteAlternativas ? 'Máximo 5 alternativas por cotización' : ''"
            @click="!alcanzoLimiteAlternativas && (mostrarFormAlternativa = true)">
            <i class="fas fa-plus me-1"></i>Nueva
        </span>
    </div>

    <!-- Form nueva alternativa -->
    <div v-if="mostrarFormAlternativa" class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-3">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Nombre</label>
                    <input type="text" class="form-control form-control-sm" :class="{ 'is-invalid': erroresAlternativa.nombre }"
                        v-model="formAlternativa.nombre" :placeholder="nombreSugeridoAlternativa">
                    <div v-if="erroresAlternativa.nombre" class="invalid-feedback">{{ erroresAlternativa.nombre }}</div>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Moneda</label>
                    <select class="form-select form-select-sm" :class="{ 'is-invalid': erroresAlternativa.moneda_cotizacion }" v-model="formAlternativa.moneda_cotizacion">
                        <option value="PEN">Soles</option>
                        <option value="USD">Dólares</option>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Tipo de cambio</label>
                    <select class="form-select form-select-sm" v-model="formAlternativa.tipo_cambio_origen">
                        <option value="dia">Del día</option>
                        <option value="agencia">De la agencia</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label mb-1 small fw-semibold text-secondary">Valor nuevo (opcional)</label>
                    <input type="number" step="0.0001" class="form-control form-control-sm" :class="{ 'is-invalid': erroresAlternativa.tipo_cambio_valor }" v-model.number="formAlternativa.tipo_cambio_valor">
                    <div v-if="erroresAlternativa.tipo_cambio_valor" class="invalid-feedback">{{ erroresAlternativa.tipo_cambio_valor }}</div>
                    <small v-if="sugerenciaTipoCambioSunat && formAlternativa.tipo_cambio_origen === 'dia'" class="text-muted d-block">
                        Sugerido SUNAT ({{ sugerenciaTipoCambioSunat.fecha }}): {{ sugerenciaTipoCambioSunat.valor }}
                    </small>
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button class="btn btn-primary btn-sm w-100" @click="crearAlternativa()" :disabled="creandoAlternativa">
                        <span v-if="creandoAlternativa" class="spinner-border spinner-border-sm me-1"></span>Crear
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" @click="mostrarFormAlternativa = false"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <div v-if="erroresAlternativa.general" class="text-danger small mt-2">{{ erroresAlternativa.general }}</div>
        </div>
    </div>
</template>

<script setup lang="ts">
/*
 * Pestañas de alternativas (moneda, renombrar, eliminar), calculadora de tipo de cambio y formulario
 * «+ Nueva». Todo llega por inject desde cotizador/editar.vue (T-105).
 */
import CalculadoraTipoCambioModal from '@/components/AgenciaViajes/CalculadoraTipoCambioModal.vue';
import {
    CLAVE_NUCLEO, CLAVE_PRECIOS, CLAVE_RESULTADO, CLAVE_ALTERNATIVAS, CLAVE_PAGINA, inyectar,
} from '@/composables/agencia-viajes/cotizador/claves';

const {
    alternativaActiva, alternativaActivaId, alternativaSoloLectura, cotizacion, modificacionInfo,
} = inyectar(CLAVE_NUCLEO);
const { totalLocal } = inyectar(CLAVE_PRECIOS);
const { cotizacionPerdida } = inyectar(CLAVE_RESULTADO);
const {
    alcanzoLimiteAlternativas, cambiandoMoneda, cambiarMonedaAlternativa, creandoAlternativa,
    crearAlternativa, eliminandoAlternativa, eliminarAlternativa, erroresAlternativa, formAlternativa,
    mostrarCalculadoraTipoCambio, mostrarFormAlternativa, nombreSugeridoAlternativa, renombrarAlternativa,
    reservaDeAlternativa, sugerenciaTipoCambioSunat,
} = inyectar(CLAVE_ALTERNATIVAS);
const { seleccionarAlternativa } = inyectar(CLAVE_PAGINA);
</script>

<style scoped>
/* Tacho de la alternativa activa — oculto hasta hover del pill, para no
   saturar la fila cuando hay varias alternativas (Parte B). */
.alt-pill-delete { opacity: 0; transition: opacity .15s; }
.alt-pill:hover .alt-pill-delete { opacity: 1; }

/* T-080: pestaña de la alternativa reservada — borde verde de 2px aunque esté activa. */
.alt-pill-reservada { border: 2px solid var(--bs-success, #198754) !important; }
.alt-pill-marca { width: 1.4rem; height: 1.4rem; padding: 0; display: inline-flex; align-items: center; justify-content: center; font-size: .65rem; }

/* Lápiz de renombrar — mismo criterio de visibilidad que el tacho de
   arriba, separados con un poco de margen para no quedar pegados. */
.alt-pill-edit { opacity: 0; transition: opacity .15s; margin-left: 2px; }
.alt-pill:hover .alt-pill-edit { opacity: 1; }
</style>
