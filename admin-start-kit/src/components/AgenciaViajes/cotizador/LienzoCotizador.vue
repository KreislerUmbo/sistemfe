<template>
    <!-- ═══ LIENZO ═══ -->
    <div v-if="alternativaActiva" class="col-12 col-lg-8">
        <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
            <button v-if="!alternativaSoloLectura" class="btn btn-primary btn-sm" @click="abrirDrawerBiblioteca">
                <i class="fas fa-plus me-1"></i>Agregar servicio
            </button>
            <button v-if="!mostrarTabsDia && !alternativaSoloLectura" class="btn btn-link btn-sm text-decoration-none" @click="agregarDia">
                <i class="fas fa-calendar-plus me-1"></i>Agregar otro día
            </button>
            <button v-if="!alternativaSoloLectura" class="btn btn-outline-secondary btn-sm ms-auto" @click="toggleFormPasajeAereo">
                <i class="fas fa-plane me-1"></i>{{ mostrarFormPasajeAereo ? 'Cerrar' : 'Pasaje aéreo suelto' }}
            </button>
        </div>

        <div v-if="alternativaSoloLectura && alternativaActiva" class="alert alert-info py-2 small mb-2">
            <i class="fas fa-lock me-1"></i>Alternativa reservada: para cambiarla usa Modificar reserva
            <template v-if="reservaDeAlternativa(alternativaActiva)">
                — <router-link :to="`/agencia-viajes/reservas/${reservaDeAlternativa(alternativaActiva)!.id}`">{{ reservaDeAlternativa(alternativaActiva)!.codigo ?? `reserva #${reservaDeAlternativa(alternativaActiva)!.id}` }}</router-link>
            </template>.
        </div>

        <div v-if="mostrarFormPasajeAereo" class="border rounded p-2 mb-2">
            <div v-if="itemPasajeAereoEnEdicion && formPasajeSoloLectura" class="d-flex justify-content-end mb-1">
                <button type="button" class="btn btn-link btn-sm p-0" @click="toggleFormPasajeAereo"><i class="fas fa-times me-1"></i>Cerrar</button>
            </div>
            <div v-if="!alternativaSoloLectura && avisoItemReservado(itemPasajeAereoEnEdicion)" class="alert alert-info py-2 small mb-2">
                <i class="fas fa-lock me-1"></i>{{ avisoItemReservado(itemPasajeAereoEnEdicion) }}
            </div>
            <PasajeAereoForm :alternativa-id="alternativaActiva.id" :dia-activo="diaActivoParaAgregar"
                :alternativa-destino-id="destinoActivoId"
                :pasajeros="cotizacion?.pasajeros ?? []" :item-existente="itemPasajeAereoEnEdicion" :solo-lectura="formPasajeSoloLectura"
                :items-alternativa="alternativaActiva.items ?? []"
                :tip-afe-igv-default="configAgencia?.tip_afe_igv_default"
                :destino-tributario-default="configAgencia?.destino_tributario_default"
                @agregado="onPasajeAereoAgregado" @actualizado="onPasajeAereoActualizado" />
        </div>

        <!-- Tabs de día — condicionales (Punto B): solo aparecen cuando ya
             existe un segundo día real, o hay ítems legado "Sin día". Con un
             solo día, el lienzo es una lista simple sin navegación de más. -->
        <ul v-if="mostrarTabsDia" class="nav nav-tabs mb-2">
            <li class="nav-item" v-for="d in diasCreados" :key="d">
                <a class="nav-link" href="#" :class="{ active: diaActivo === d }" @click.prevent="diaActivo = d">Día {{ d }}</a>
            </li>
            <li class="nav-item" v-if="itemsSinDia.length">
                <a class="nav-link" href="#" :class="{ active: diaActivo === 0 }" @click.prevent="diaActivo = 0">Sin día ({{ itemsSinDia.length }})</a>
            </li>
            <li class="nav-item">
                <a class="nav-link text-muted" href="#" @click.prevent="agregarDia"><i class="fas fa-plus me-1"></i>Día</a>
            </li>
        </ul>

        <!-- Vista previa al cargar un tour/paquete multi-día (Punto C) -->
        <div v-if="vistaPreviaCombo" class="card border-0 shadow-sm mb-2">
            <div class="card-body py-2 px-3 small bg-info-subtle rounded">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <span class="fw-semibold"><i class="fas fa-info-circle me-1 text-info"></i>Tour de varios días cargado:</span>
                        <span v-for="(paso, idx) in vistaPreviaCombo" :key="paso.tourOrigenId">
                            {{ idx > 0 ? ' / ' : ' ' }}Día {{ paso.dia }}: {{ paso.nombre }} · {{ alternativaActiva.moneda_cotizacion }} {{ paso.total.toFixed(2) }}
                        </span>
                    </div>
                    <i class="fas fa-times text-muted flex-shrink-0" style="cursor:pointer" @click="vistaPreviaCombo = null"></i>
                </div>
            </div>
        </div>

        <!-- Fila 13, fase 1b (§3.12) — la fecha cambió y algún tour quedó en
             otra temporada: solo aviso, recalcular es a pedido del vendedor. -->
        <div v-if="temporadasDesactualizadas.length" class="alert alert-warning py-2 px-3 small mb-2 d-flex justify-content-between align-items-center gap-2 flex-wrap">
            <span>
                <i class="fas fa-calendar-alt me-1"></i><strong>Las fechas cambiaron de temporada:</strong>
                <span v-for="(t, idx) in temporadasDesactualizadas" :key="t.carga_tour">
                    {{ idx > 0 ? '; ' : ' ' }}{{ t.tour_nombre }} ({{ t.temporada_actual ?? 'precio base' }} → {{ t.temporada_nueva ?? 'precio base' }}){{ t.precio_manual ? ' — precio a mano, no se toca' : '' }}
                </span>
            </span>
            <button class="btn btn-sm btn-warning" @click="recalcularTemporadas" :disabled="recalculandoTemporadas">
                <span v-if="recalculandoTemporadas" class="spinner-border spinner-border-sm me-1"></span>Recalcular precios por temporada
            </button>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div v-if="bloquesLienzo.length === 0 && mostrarTabsDia && diasDesdeCombo.has(diaActivo)" class="drop-hint text-center text-muted py-4 border rounded" style="border-style:dashed">
                    <i class="fas fa-triangle-exclamation text-warning me-1"></i>
                    Este día no tiene ítems porque el tour de origen no tiene Incluye cargado — revisa el catálogo.
                </div>
                <div v-else-if="bloquesLienzo.length === 0" class="drop-hint text-center text-muted py-4 border rounded" style="border-style:dashed">
                    Agregá un servicio con el botón de arriba
                </div>

                <div v-for="bloque in bloquesLienzo" :key="bloque.clave" class="mb-3" :data-item-id="bloque.carga ? bloque.items.map((i) => i.id).join(' ') : null"
                    :class="[{ 'border-start border-3 border-primary bg-light-subtle rounded-2 p-2': bloque.tourOrigenId }, bloque.carga ? claseResaltado(bloque.items.map((i) => i.id)) : null]">
                    <CargaTourLienzo :bloque="bloque" />

                    <template v-for="fila in (bloque.carga ? [] : filasDelBloque(bloque.items))" :key="fila.esGrupo ? fila.grupoId : fila.item.id">
                    <GrupoOpcionesLienzo v-if="fila.esGrupo" :fila="fila" />

                    <ItemLienzo v-else :item="fila.item" :bloque="bloque" />
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
/*
 * Lienzo de la alternativa activa (div.col-lg-8): barra de acciones, aviso de solo lectura, pasaje
 * aéreo, tabs de día, vista previa de combo, temporadas desactualizadas, avisos de vacío y los bloques
 * (CargaTourLienzo, GrupoOpcionesLienzo, ItemLienzo). El v-if de la raíz repite la condición del
 * div.row de la página: solo estrecha el tipo. Todo llega por inject desde cotizador/editar.vue
 * (T-105).
 */
import PasajeAereoForm from '@/components/AgenciaViajes/PasajeAereoForm.vue';
import { filasDelBloque } from '@/helpers/agencia-viajes/cotizador/bloquesLienzo';
import {
    CLAVE_NUCLEO, CLAVE_ALTERNATIVAS, CLAVE_DESTINOS, CLAVE_DIAS, CLAVE_LINEA_TOUR, CLAVE_BLOQUES,
    CLAVE_DRAWER, CLAVE_BIBLIOTECA, CLAVE_MODIFICACION, CLAVE_ITEMS_SUELTOS, inyectar,
} from '@/composables/agencia-viajes/cotizador/claves';
import CargaTourLienzo from '@/components/AgenciaViajes/cotizador/CargaTourLienzo.vue';
import GrupoOpcionesLienzo from '@/components/AgenciaViajes/cotizador/GrupoOpcionesLienzo.vue';
import ItemLienzo from '@/components/AgenciaViajes/cotizador/ItemLienzo.vue';

const { alternativaActiva, alternativaSoloLectura, configAgencia, cotizacion } = inyectar(CLAVE_NUCLEO);
const { reservaDeAlternativa } = inyectar(CLAVE_ALTERNATIVAS);
const { destinoActivoId } = inyectar(CLAVE_DESTINOS);
const {
    agregarDia, diaActivo, diaActivoParaAgregar, diasCreados, diasDesdeCombo, itemsSinDia, mostrarTabsDia,
} = inyectar(CLAVE_DIAS);
const { recalculandoTemporadas, recalcularTemporadas, temporadasDesactualizadas } = inyectar(CLAVE_LINEA_TOUR);
const { bloquesLienzo } = inyectar(CLAVE_BLOQUES);
const { abrirDrawerBiblioteca } = inyectar(CLAVE_DRAWER);
const { vistaPreviaCombo } = inyectar(CLAVE_BIBLIOTECA);
const { claseResaltado } = inyectar(CLAVE_MODIFICACION);
const {
    avisoItemReservado, formPasajeSoloLectura, itemPasajeAereoEnEdicion, mostrarFormPasajeAereo,
    onPasajeAereoActualizado, onPasajeAereoAgregado, toggleFormPasajeAereo,
} = inyectar(CLAVE_ITEMS_SUELTOS);
</script>

<style scoped>
.resaltado-item { box-shadow: 0 0 0 3px rgba(255, 193, 7, .6); transition: box-shadow .3s; }
</style>
