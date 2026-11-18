<template>
    <!-- ═══ PANEL DE PRECIO (resumen, jerárquico, sin edición) ═══ -->
    <!-- El v-if repite la condición del div.row de la página: solo estrecha el tipo (T-105). -->
    <div v-if="alternativaActiva" class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm price-panel">
            <div class="card-body">
                <p class="small fw-semibold text-secondary mb-2">Alternativa {{ alternativaActiva.nombre }}</p>
                <div class="small mb-2" style="max-height:380px;overflow-y:auto;">
                    <!-- Sesión 12f-2, §7.1 punto 4 — con 2+ destinos, un nivel extra
                         colapsable por destino ENVUELVE el mismo desglose por tour de
                         siempre (mismo markup, ahora reusado con :key de destino). Con
                         1 solo destino, sigue el flujo de siempre sin este nivel. -->
                    <template v-if="mostrarChipsDestino">
                        <div v-for="bloqueDestino in gruposPrecioPorDestino" :key="bloqueDestino.destinoId ?? 'sin-destino'" class="mb-2 pb-2 border-bottom">
                            <div class="d-flex justify-content-between align-items-center" style="cursor:pointer" @click="toggleDestinoExpandido(bloqueDestino.destinoId)">
                                <span class="fw-semibold"><i class="fas fa-map-marker-alt me-1 text-primary"></i>{{ bloqueDestino.destinoTexto }}</span>
                                <span class="d-flex align-items-center gap-1">
                                    <span class="text-nowrap">{{ bloqueDestino.subtotal.toFixed(2) }}</span>
                                    <i class="fas" :class="destinosExpandido.has(bloqueDestino.destinoId) ? 'fa-chevron-up' : 'fa-chevron-down'" style="font-size:10px"></i>
                                </span>
                            </div>
                            <div v-if="destinosExpandido.has(bloqueDestino.destinoId)" class="ms-2 mt-1">
                                <div v-for="grupo in bloqueDestino.grupos" :key="grupo.clave" class="mb-2 pb-1">
                                    <template v-if="grupo.tourOrigenId">
                                        <div class="d-flex justify-content-between align-items-center" style="cursor:pointer" @click="toggleGrupoExpandido(grupo.tourOrigenId)">
                                            <span class="fw-semibold"><i class="fas fa-route me-1 text-primary"></i>{{ grupo.tourNombre ?? 'Tour' }}</span>
                                            <span class="d-flex align-items-center gap-1">
                                                <span class="text-nowrap">{{ subtotalGrupo(grupo.items).toFixed(2) }}</span>
                                                <i class="fas" :class="gruposExpandido.has(grupo.tourOrigenId) ? 'fa-chevron-up' : 'fa-chevron-down'" style="font-size:10px"></i>
                                            </span>
                                        </div>
                                        <div v-if="gruposExpandido.has(grupo.tourOrigenId)" class="ms-3 mt-1">
                                            <div v-for="item in grupo.items" :key="item.id" class="d-flex justify-content-between gap-2 text-muted" style="font-size:11px">
                                                <span style="word-break:break-word">
                                                    {{ etiquetaItem(item) }}
                                                    <span v-if="badgeTratamientoTributario(item)" class="badge ms-1"
                                                        :class="badgeTratamientoTributario(item)!.clase" style="font-size:9px">{{ badgeTratamientoTributario(item)!.texto }}</span>
                                                </span>
                                                <span class="text-nowrap">{{ totalConvertidoLocal(item).toFixed(2) }}</span>
                                            </div>
                                        </div>
                                    </template>
                                    <template v-else>
                                        <div v-for="item in filasResumen(grupo.items)" :key="item.id" class="d-flex justify-content-between gap-2 mb-1">
                                            <span class="text-muted" style="word-break:break-word;font-size:11px">
                                                {{ etiquetaItem(item) }}
                                                <span v-if="item.grupo_opcion_id && !item.opcion_elegida" class="text-muted" style="font-size:10px">(desde, sin resolver)</span>
                                                <span v-if="badgeTratamientoTributario(item)" class="badge ms-1"
                                                    :class="badgeTratamientoTributario(item)!.clase" style="font-size:9px">{{ badgeTratamientoTributario(item)!.texto }}</span>
                                            </span>
                                            <span class="text-nowrap">{{ totalConvertidoLocal(item).toFixed(2) }}</span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </template>
                    <template v-else>
                        <div v-for="grupo in gruposPrecioPanel" :key="grupo.clave" class="mb-2 pb-2 border-bottom">
                            <template v-if="grupo.tourOrigenId">
                                <div class="d-flex justify-content-between align-items-center" style="cursor:pointer" @click="toggleGrupoExpandido(grupo.tourOrigenId)">
                                    <span class="fw-semibold"><i class="fas fa-route me-1 text-primary"></i>{{ grupo.tourNombre ?? 'Tour' }}</span>
                                    <span class="d-flex align-items-center gap-1">
                                        <span class="text-nowrap">{{ subtotalGrupo(grupo.items).toFixed(2) }}</span>
                                        <i class="fas" :class="gruposExpandido.has(grupo.tourOrigenId) ? 'fa-chevron-up' : 'fa-chevron-down'" style="font-size:10px"></i>
                                    </span>
                                </div>
                                <div v-if="gruposExpandido.has(grupo.tourOrigenId)" class="ms-3 mt-1">
                                    <div v-for="item in grupo.items" :key="item.id" class="d-flex justify-content-between gap-2 text-muted" style="font-size:11px">
                                        <span style="word-break:break-word">
                                            {{ etiquetaItem(item) }}
                                            <span v-if="badgeTratamientoTributario(item)" class="badge ms-1"
                                                :class="badgeTratamientoTributario(item)!.clase" style="font-size:9px">{{ badgeTratamientoTributario(item)!.texto }}</span>
                                        </span>
                                        <span class="text-nowrap">{{ totalConvertidoLocal(item).toFixed(2) }}</span>
                                    </div>
                                </div>
                            </template>
                            <template v-else>
                                <div v-for="item in filasResumen(grupo.items)" :key="item.id" class="d-flex justify-content-between gap-2 mb-1">
                                    <span class="text-muted" style="word-break:break-word;">
                                        {{ etiquetaItem(item) }}<span v-if="textoAdultosMayorista(item)"> {{ textoAdultosMayorista(item) }}</span>
                                        <span v-if="item.grupo_opcion_id && !item.opcion_elegida" class="text-muted" style="font-size:10px">(desde, sin resolver)</span>
                                        <span v-if="badgeTratamientoTributario(item)" class="badge ms-1"
                                            :class="badgeTratamientoTributario(item)!.clase" style="font-size:9px">{{ badgeTratamientoTributario(item)!.texto }}</span>
                                    </span>
                                    <span class="text-nowrap">{{ totalConvertidoLocal(item).toFixed(2) }}</span>
                                </div>
                            </template>
                        </div>
                    </template>
                    <div v-if="(alternativaActiva.items?.length ?? 0) === 0" class="text-muted">Sin ítems todavía</div>
                </div>

                <div class="border-top pt-2 mb-2" v-if="(alternativaActiva.items?.length ?? 0) > 0">
                    <!-- Punto C (Sesión 11i) — un único input, según configuracion_agencia.
                         Guardrail (19-sep-2026, hallazgo del usuario) — antes el símbolo
                         (USD/%) era un <span> suelto al lado del input, no "pegado" como
                         un input-group real (mismo patrón que ya usa el descuento POR
                         ÍTEM, línea ~444 más abajo) — menos claro a simple vista de qué
                         modo está activo. El label ya no repite el modo en texto (antes
                         "Descuento global %" vs "Descuento global") — una sola señal (el
                         símbolo pegado al input, que siempre refleja la configuración real
                         gracias a la invalidación de caché de la sesión anterior) en vez
                         de dos que podían leerse como contradictorias si una quedaba vieja. -->
                    <label class="form-label mb-1 small fw-semibold text-secondary">Descuento global</label>
                    <template v-if="configAgencia?.modo_descuento_global === 'monto'">
                        <div class="input-group input-group-sm" style="max-width:160px">
                            <span class="input-group-text">{{ alternativaActiva.moneda_cotizacion }}</span>
                            <input type="number" min="0" class="form-control"
                                v-model.number="descuentoGlobalMontoLocal" :disabled="alternativaSoloLectura || aplicandoDescuentoGlobal" @change="onEditarDescuentoGlobalMonto">
                            <span v-if="aplicandoDescuentoGlobal" class="input-group-text"><span class="spinner-border spinner-border-sm"></span></span>
                        </div>
                    </template>
                    <template v-else>
                        <div class="input-group input-group-sm" style="max-width:160px">
                            <input type="number" min="0" max="100" class="form-control"
                                v-model.number="descuentoGlobalLocal" :disabled="alternativaSoloLectura || aplicandoDescuentoGlobal" @change="onEditarDescuentoGlobal">
                            <span v-if="aplicandoDescuentoGlobal" class="input-group-text"><span class="spinner-border spinner-border-sm"></span></span>
                            <span class="input-group-text">%</span>
                        </div>
                    </template>
                    <small v-if="lineasFueraDePiso.length" class="text-danger d-block mt-1">
                        <i class="fas fa-exclamation-triangle me-1"></i>{{ lineasFueraDePiso.length }} línea(s) quedaron bajo el piso permitido — revisalas en el lienzo.
                    </small>
                </div>

                <hr>
                <div class="d-flex justify-content-between align-items-baseline">
                    <span class="small text-secondary">Total <template v-if="notaMayoristaSinNinos">(el paquete de mayorista no incluye niños)</template><template v-else>{{ resumenPaxTotal }}</template></span>
                    <span class="fs-4 fw-semibold">{{ alternativaActiva.moneda_cotizacion }} {{ totalLocal.toFixed(2) }}</span>
                </div>
                <div v-if="itemsAjuste.length" class="small text-muted mt-2">
                    <div class="fw-semibold">Ajuste — no se suma: ya está en el precio del servicio</div>
                    <div v-for="a in itemsAjuste" :key="a.id" class="d-flex justify-content-between gap-2">
                        <span>{{ etiquetaItem(a) }}</span>
                        <span class="text-nowrap">{{ alternativaActiva.moneda_cotizacion }} {{ totalConvertidoLocal(a).toFixed(2) }}</span>
                    </div>
                </div>
                <div class="text-end">
                    <a href="#" class="small" @click.prevent="mostrarCalculadoraTipoCambio = true">
                        Convertir a {{ alternativaActiva.moneda_cotizacion === 'USD' ? 'soles' : 'dólares' }}
                    </a>
                </div>

                <div class="mt-3 d-flex flex-column gap-2">
                    <!-- Solo en borrador o enviada, la misma regla que ReservaController::alternativaAceptable():
                         una descartada o reemplazada respondía 422. -->
                    <template v-if="['borrador', 'enviada'].includes(alternativaActiva.estado) && !modificacionInfo && !cotizacionPerdida">
                        <button class="btn btn-success btn-sm w-100" @click="marcarAceptada" :disabled="(alternativaActiva.items?.length ?? 0) === 0 || marcandoAceptada">
                            <span v-if="marcandoAceptada" class="spinner-border spinner-border-sm me-1"></span>
                            <i v-else class="fas fa-check me-1"></i>Aceptado por cliente
                        </button>
                        <small v-if="(alternativaActiva.items?.length ?? 0) === 0" class="text-muted d-block text-center">
                            Agregá al menos un ítem para poder aceptar
                        </small>
                    </template>

                    <button v-if="!modificacionInfo && !cotizacionPerdida" class="btn btn-outline-primary btn-sm w-100" @click="duplicarAlternativa"
                        :disabled="duplicando || alcanzoLimiteAlternativas" :title="alcanzoLimiteAlternativas ? 'Máximo 5 alternativas por cotización' : ''">
                        <span v-if="duplicando" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="fas fa-copy me-1"></i>Duplicar alternativa
                    </button>

                    <button class="btn btn-outline-secondary btn-sm w-100" :disabled="descargandoPdf" @click="descargarPdfAlternativa">
                        <span v-if="descargandoPdf" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="fas fa-file-pdf me-1"></i>Ver PDF
                    </button>
                    <button class="btn btn-outline-secondary btn-sm w-100" :disabled="descargandoCondiciones" @click="descargarCondicionesGenerales">
                        <span v-if="descargandoCondiciones" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="fas fa-file-contract me-1"></i>Descargar condiciones generales
                    </button>
                    <!-- T-053: vigencia de la enviada y «Extender vigencia» (vigente o vencida). -->
                    <div v-if="alternativaActiva.estado === 'enviada' && !modificacionInfo && !cotizacionPerdida"
                        class="d-flex align-items-center justify-content-between gap-2 small">
                        <span v-if="vigenciaActiva" :class="vigenciaActiva.vencida ? 'text-danger' : 'text-muted'">
                            <i class="fas fa-hourglass-half me-1"></i>{{ vigenciaActiva.vencida ? 'Vencida el' : 'Vigente hasta el' }} {{ vigenciaActiva.dia }}
                        </span>
                        <span v-else class="text-muted"><i class="fas fa-hourglass-half me-1"></i>Sin fecha de vencimiento</span>
                        <button type="button" class="btn btn-outline-primary btn-sm text-nowrap" @click="extendiendoVigencia = true">
                            <i class="fas fa-calendar-plus me-1"></i>Extender vigencia
                        </button>
                    </div>
                    <ExtenderVigenciaModal v-if="extendiendoVigencia" :alternativa-id="alternativaActiva.id" :nombre="alternativaActiva.nombre"
                        :dias-vigencia="configAgencia?.dias_vigencia_cotizacion" @cerrar="extendiendoVigencia = false" @extendida="onVigenciaExtendida" />
                    <!-- T-022: "¿lista para el cliente?" junto a los botones de envío. -->
                    <RevisionAlternativa v-if="!modificacionInfo" :alternativa-id="alternativaActiva.id" :moneda="alternativaActiva.moneda_cotizacion"
                        :refrescar="claveRevision" :nombre-de-item="nombreItemRevision"
                        @cambio="revisionActual = $event" @ir="irARevision" @actualizada="cargarCotizacion" />
                    <EnviarAlternativa v-if="!modificacionInfo" :alternativa="alternativaActiva" :cliente="cotizacion?.cliente ?? null"
                        :destino="cotizacion?.destino ?? ''" :total="totalLocal" :descargar-pdf="descargarPdfAlternativa"
                        :hay-acciones-pendientes="(accionesRef?.cantidad ?? 0) > 0" :revision="revisionActual"
                        @enviada="onAlternativaEnviada" @registrada="historialRef?.recargar(); accionesRef?.recargar()" />
                    <AccionesPendientes v-if="cotizacion?.cliente_id" ref="accionesRef" :cliente-id="cotizacion.cliente_id"
                        referencia-tipo="cotizacion" :referencia-id="cotizacion.id" @cambio="historialRef?.recargar()" />
                    <HistorialActividadesCotizacion v-if="cotizacion" ref="historialRef" :cotizacion-id="cotizacion.id"
                        :cliente-id="cotizacion.cliente_id ?? null" />
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
/*
 * Panel de precio de la alternativa activa: resumen por destino y tour, descuento global, totales,
 * botones (PDF, duplicar, aceptar), vigencia, revisión antes de enviar, envío, acciones pendientes e
 * historial. accionesRef e historialRef son refs de useResultadoCotizacion atadas acá como template
 * refs. Todo llega por inject desde cotizador/editar.vue (T-105).
 */
import AccionesPendientes from '@/components/Clientes/AccionesPendientes.vue';
import EnviarAlternativa from '@/components/AgenciaViajes/EnviarAlternativa.vue';
import ExtenderVigenciaModal from '@/components/AgenciaViajes/ExtenderVigenciaModal.vue';
import HistorialActividadesCotizacion from '@/components/AgenciaViajes/HistorialActividadesCotizacion.vue';
import RevisionAlternativa from '@/components/AgenciaViajes/RevisionAlternativa.vue';
import { badgeTratamientoTributario, etiquetaItem } from '@/helpers/agencia-viajes/cotizador/presentacionItem';
import {
    CLAVE_NUCLEO, CLAVE_PRECIOS, CLAVE_PASAJEROS, CLAVE_RESULTADO, CLAVE_ALTERNATIVAS, CLAVE_DESTINOS,
    CLAVE_BLOQUES, CLAVE_REVISION, inyectar,
} from '@/composables/agencia-viajes/cotizador/claves';

const {
    alternativaActiva, alternativaSoloLectura, cargarCotizacion, configAgencia, cotizacion, itemsAjuste,
    modificacionInfo,
} = inyectar(CLAVE_NUCLEO);
const {
    aplicandoDescuentoGlobal, descuentoGlobalLocal, descuentoGlobalMontoLocal, filasResumen,
    lineasFueraDePiso, onEditarDescuentoGlobal, onEditarDescuentoGlobalMonto, subtotalGrupo,
    totalConvertidoLocal, totalLocal,
} = inyectar(CLAVE_PRECIOS);
const { notaMayoristaSinNinos, resumenPaxTotal, textoAdultosMayorista } = inyectar(CLAVE_PASAJEROS);
const {
    accionesRef, cotizacionPerdida, extendiendoVigencia, historialRef, onAlternativaEnviada,
    onVigenciaExtendida, vigenciaActiva,
} = inyectar(CLAVE_RESULTADO);
const {
    alcanzoLimiteAlternativas, descargandoCondiciones, descargandoPdf, descargarCondicionesGenerales,
    descargarPdfAlternativa, duplicando, duplicarAlternativa, marcandoAceptada, marcarAceptada,
    mostrarCalculadoraTipoCambio,
} = inyectar(CLAVE_ALTERNATIVAS);
const { mostrarChipsDestino } = inyectar(CLAVE_DESTINOS);
const {
    destinosExpandido, gruposExpandido, gruposPrecioPanel, gruposPrecioPorDestino, toggleDestinoExpandido,
    toggleGrupoExpandido,
} = inyectar(CLAVE_BLOQUES);
const { claveRevision, irARevision, nombreItemRevision, revisionActual } = inyectar(CLAVE_REVISION);
</script>

<style scoped>
.price-panel { position: sticky; top: 1rem; }
</style>
