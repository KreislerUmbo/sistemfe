<template>
  <div class="bg-black text-white min-vh-100">
    <!-- Topbar -->
    <div
      class="topbar d-flex justify-content-between align-items-center px-4 py-3 border-bottom border-secondary bg-dark-2">
      <div class="logo fw-bold fs-3">UMBO<span class="text-danger">SYSTEM</span></div>
      <div class="secure-badge small text-muted">
        <i class="bi bi-lock-fill text-success me-1"></i> Pago 100% seguro y encriptado · SSL
      </div>
    </div>

    <!-- Stepper -->
    <div
      class="stepper d-flex justify-content-center align-items-center gap-2 py-4 border-bottom border-secondary bg-dark-2">
      <div class="step d-flex align-items-center gap-2" :class="{ done: step >= 1, active: step === 1 }">
        <div class="step-circle d-flex align-items-center justify-content-center rounded-circle fw-bold">1</div>
        <span>Carrito</span>
      </div>
      <div class="step-line" :class="{ done: step >= 2 }"></div>
      <div class="step d-flex align-items-center gap-2" :class="{ done: step >= 2, active: step === 2 }">
        <div class="step-circle d-flex align-items-center justify-content-center rounded-circle fw-bold">2</div>
        <span>Datos y pago</span>
      </div>
      <div class="step-line" :class="{ done: step >= 3 }"></div>
      <div class="step d-flex align-items-center gap-2" :class="{ done: step >= 3, active: step === 3 }">
        <div class="step-circle d-flex align-items-center justify-content-center rounded-circle fw-bold">3</div>
        <span>Confirmación</span>
      </div>
    </div>

    <div class="container py-4">
      <div class="row g-4">
        <!-- Formulario (izquierda) -->
        <div class="col-lg-7">
          <div class="d-flex flex-column gap-4">
            <!-- 1. Datos personales -->
            <div class="form-section bg-dark-2 rounded-4 border border-secondary">
              <div class="section-header d-flex align-items-center gap-3 p-3 border-bottom border-secondary">
                <div
                  class="section-num rounded-circle bg-danger d-flex align-items-center justify-content-center fw-bold"
                  :class="{ done: datosCompletos }">1</div>
                <h5 class="m-0 fw-bold">DATOS PERSONALES</h5>
                <button v-if="datosCompletos && step > 1" class="btn btn-sm text-danger ms-auto"
                  @click="editSection('datos')">Editar</button>
              </div>
              <div class="form-body p-3">
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label small fw-bold">Nombres *</label>
                    <input type="text" class="form-control bg-dark text-white border-secondary" v-model="form.nombres"
                      placeholder="Carlos Alberto">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small fw-bold">Apellidos *</label>
                    <input type="text" class="form-control bg-dark text-white border-secondary" v-model="form.apellidos"
                      placeholder="Ramírez Torres">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small fw-bold">Correo electrónico *</label>
                    <input type="email" class="form-control bg-dark text-white border-secondary" v-model="form.email"
                      placeholder="carlos@correo.com">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small fw-bold">Teléfono / WhatsApp *</label>
                    <input type="tel" class="form-control bg-dark text-white border-secondary" v-model="form.telefono"
                      placeholder="987 654 321">
                  </div>
                  <div class="col-12">
                    <label class="form-label small fw-bold">DNI / RUC</label>
                    <div class="input-group">
                      <input type="text" class="form-control bg-dark text-white border-secondary" v-model="form.dni"
                        placeholder="12345678">
                      <button class="btn btn-outline-danger" @click="buscarDni">Verificar</button>
                    </div>
                    <small v-if="tipoDocumento" class="text-muted">
                      Tipo de documento detectado: {{ tipoDocumento }}
                    </small>
                  </div>
                  <div class="col-12">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" id="crearCuenta" v-model="form.crearCuenta">
                      <label class="form-check-label text-white-50" for="crearCuenta">Crear cuenta para hacer
                        seguimiento de mis pedidos</label>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- 2. Método de entrega -->
            <div class="form-section bg-dark-2 rounded-4 border border-secondary">
              <div class="section-header d-flex align-items-center gap-3 p-3 border-bottom border-secondary">
                <div
                  class="section-num rounded-circle bg-danger d-flex align-items-center justify-content-center fw-bold"
                  :class="{ done: entregaCompleta }">2</div>
                <h5 class="m-0 fw-bold">MÉTODO DE ENTREGA</h5>
                <button v-if="entregaCompleta && step > 2" class="btn btn-sm text-danger ms-auto"
                  @click="editSection('entrega')">Editar</button>
              </div>
              <div class="form-body p-3">
                <div class="d-flex gap-2 mb-3">
                  <button class="btn flex-grow-1"
                    :class="entregaTipo === 'despacho' ? 'btn-danger' : 'btn-outline-secondary'"
                    @click="entregaTipo = 'despacho'">🚚 Despacho a domicilio</button>
                  <button class="btn flex-grow-1"
                    :class="entregaTipo === 'tienda' ? 'btn-danger' : 'btn-outline-secondary'"
                    @click="entregaTipo = 'tienda'">🏪 Recojo en tienda</button>
                </div>
                <div v-if="entregaTipo === 'despacho'">
                  <div class="mb-3">
                    <label class="form-label small fw-bold">Dirección *</label>
                    <input type="text" class="form-control bg-dark text-white border-secondary" v-model="form.direccion"
                      placeholder="Av. Los Pinos 456, Dpto 3B">
                  </div>
                  <div class="row g-2">
                    <div class="col-md-4">
                      <label class="form-label small fw-bold">Departamento</label>
                      <select class="form-select bg-dark text-white border-secondary" v-model="form.departamento">
                        <option value="">Seleccionar</option>
                        <option>San Martín</option>
                        <option>Lima</option>
                        <option>Cusco</option>
                        <option>Arequipa</option>
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label small fw-bold">Provincia</label>
                      <select class="form-select bg-dark text-white border-secondary" v-model="form.provincia">
                        <option value="">Seleccionar</option>
                        <option>Moyobamba</option>
                        <option>Rioja</option>
                        <option>Tarapoto</option>
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label small fw-bold">Distrito</label>
                      <select class="form-select bg-dark text-white border-secondary" v-model="form.distrito">
                        <option value="">Seleccionar</option>
                        <option>Tarapoto</option>
                        <option>Morales</option>
                        <option>La Banda</option>
                      </select>
                    </div>
                  </div>
                  <div class="mt-3">
                    <label class="form-label small fw-bold">Referencia</label>
                    <input type="text" class="form-control bg-dark text-white border-secondary"
                      v-model="form.referencia" placeholder="Frente al parque, casa color verde...">
                  </div>
                </div>
                <div v-else>
                  <div class="bg-dark p-3 rounded-3 border border-secondary">
                    <div class="d-flex gap-3">
                      <span class="fs-2">📍</span>
                      <div>
                        <div class="fw-bold">TechStore — Tienda Principal</div>
                        <div class="small text-muted">Jr. Comercio 123, Tarapoto, San Martín</div>
                        <div class="small text-muted mt-1">Lun–Sáb: 8:00am – 7:00pm · Dom: 9:00am – 2:00pm</div>
                        <div class="small text-success">✅ Disponible para recojo en 2 horas</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- 3. Comprobante -->
            <div class="form-section bg-dark-2 rounded-4 border border-secondary">
              <div class="section-header d-flex align-items-center gap-3 p-3 border-bottom border-secondary">
                <div
                  class="section-num rounded-circle bg-danger d-flex align-items-center justify-content-center fw-bold">
                  3</div>
                <h5 class="m-0 fw-bold">COMPROBANTE</h5>
              </div>
              <div class="form-body p-3">
                <div class="d-flex gap-2 mb-3">
                  <button class="btn flex-grow-1"
                    :class="comprobanteTipo === 'boleta' ? 'btn-danger' : 'btn-outline-secondary'"
                    @click="comprobanteTipo = 'boleta'">🧾 Boleta</button>
                  <button class="btn flex-grow-1"
                    :class="comprobanteTipo === 'factura' ? 'btn-danger' : 'btn-outline-secondary'"
                    @click="comprobanteTipo = 'factura'">📄 Factura electrónica</button>
                </div>
                <div v-if="comprobanteTipo === 'factura'">
                  <div class="mb-2">
                    <label class="form-label small fw-bold">RUC *</label>
                    <input type="text" class="form-control bg-dark text-white border-secondary" v-model="form.ruc">
                  </div>
                  <div class="mb-2">
                    <label class="form-label small fw-bold">Razón social *</label>
                    <input type="text" class="form-control bg-dark text-white border-secondary"
                      v-model="form.razonSocial">
                  </div>
                  <div>
                    <label class="form-label small fw-bold">Dirección fiscal</label>
                    <input type="text" class="form-control bg-dark text-white border-secondary"
                      v-model="form.dirFiscal">
                  </div>
                </div>
              </div>
            </div>

            <!-- 4. Método de pago -->
            <div class="form-section bg-dark-2 rounded-4 border border-secondary">
              <div class="section-header d-flex align-items-center gap-3 p-3 border-bottom border-secondary">
                <div
                  class="section-num rounded-circle bg-danger d-flex align-items-center justify-content-center fw-bold">
                  4</div>
                <h5 class="m-0 fw-bold">MÉTODO DE PAGO</h5>
              </div>
              <div class="form-body p-3">
                <div class="accordion" id="payAccordion">
                  <!-- Tarjeta -->
                  <div class="accordion-item bg-dark border-secondary">
                    <h2 class="accordion-header">
                      <button class="accordion-button bg-dark text-white" type="button" data-bs-toggle="collapse"
                        data-bs-target="#payCard" :class="{ collapsed: selectedPay !== 'card' }"
                        @click="selectedPay = 'card'">
                        💳 Tarjeta débito / crédito
                      </button>
                    </h2>
                    <div id="payCard" class="accordion-collapse collapse" :class="{ show: selectedPay === 'card' }"
                      data-bs-parent="#payAccordion">
                      <div class="accordion-body bg-dark-2">
                        <div class="credit-card-visual bg-gradient p-3 rounded-3 mb-3">
                          <div class="d-flex justify-content-between align-items-start">
                            <span class="fs-1">💳</span>
                            <span class="fw-bold">{{ cardBrand }}</span>
                          </div>
                          <div class="text-center fs-4 tracking-wider my-2">{{ cardNumberDisplay }}</div>
                          <div class="d-flex justify-content-between">
                            <div>
                              <div class="small text-muted">Titular</div>
                              <div class="fw-bold">{{ cardNameDisplay || 'NOMBRE APELLIDO' }}</div>
                            </div>
                            <div>
                              <div class="small text-muted">Vence</div>
                              <div>{{ cardExpiry }}</div>
                            </div>
                          </div>
                        </div>
                        <div class="mb-2">
                          <label class="form-label small fw-bold">Número de tarjeta</label>
                          <input type="text" class="form-control bg-dark text-white border-secondary"
                            v-model="cardNumber" maxlength="19" @input="formatCardNumber"
                            placeholder="1234 5678 9012 3456">
                        </div>
                        <div class="mb-2">
                          <label class="form-label small fw-bold">Nombre en la tarjeta</label>
                          <input type="text" class="form-control bg-dark text-white border-secondary" v-model="cardName"
                            placeholder="CARLOS RAMIREZ">
                        </div>
                        <div class="row g-2">
                          <div class="col-6">
                            <label class="form-label small fw-bold">Mes venc.</label>
                            <select class="form-select bg-dark text-white border-secondary" v-model="cardMonth">
                              <option v-for="m in 12" :key="m">{{ m.toString().padStart(2, '0') }}</option>
                            </select>
                          </div>
                          <div class="col-6">
                            <label class="form-label small fw-bold">Año venc.</label>
                            <select class="form-select bg-dark text-white border-secondary" v-model="cardYear">
                              <option v-for="y in 5" :key="y">{{ new Date().getFullYear() + y }}</option>
                            </select>
                          </div>
                          <div class="col-6">
                            <label class="form-label small fw-bold">CVV</label>
                            <input type="text" class="form-control bg-dark text-white border-secondary" maxlength="4"
                              v-model="cardCvv">
                          </div>
                        </div>
                        <div class="mt-3">
                          <label class="form-label small fw-bold">Cuotas sin interés</label>
                          <div class="row g-2">
                            <div class="col-3" v-for="cuota in [1, 3, 6, 12, 18, 24]" :key="cuota">
                              <button class="btn w-100"
                                :class="cuotasSeleccionadas === cuota ? 'btn-danger' : 'btn-outline-secondary'"
                                @click="cuotasSeleccionadas = cuota">{{ cuota }}</button>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                  <!-- Yape/Plin -->
                  <div class="accordion-item bg-dark border-secondary">
                    <h2 class="accordion-header">
                      <button class="accordion-button collapsed bg-dark text-white" type="button"
                        data-bs-toggle="collapse" data-bs-target="#payYape" @click="selectedPay = 'yape'">
                        📱 Yape / Plin
                      </button>
                    </h2>
                    <div id="payYape" class="accordion-collapse collapse" :class="{ show: selectedPay === 'yape' }"
                      data-bs-parent="#payAccordion">
                      <div class="accordion-body text-center">
                        <div class="fs-1 mb-2">📲</div>
                        <p>Escanea el QR con tu app Yape o Plin</p>
                        <div class="bg-dark p-3 rounded-3 d-inline-block mb-2">▓▒░▓▒░<br>░▓▒▓░▒<br>▒░▓▒▓░</div>
                        <p class="small text-muted">Yape al <strong class="text-white">987 654 321</strong> — TechStore
                          SAC</p>
                      </div>
                    </div>
                  </div>
                  <!-- Transferencia bancaria -->
                  <div class="accordion-item bg-dark border-secondary">
                    <h2 class="accordion-header">
                      <button class="accordion-button collapsed bg-dark text-white" type="button"
                        data-bs-toggle="collapse" data-bs-target="#payTransfer" @click="selectedPay = 'transfer'">
                        🏦 Transferencia bancaria
                      </button>
                    </h2>
                    <div id="payTransfer" class="accordion-collapse collapse"
                      :class="{ show: selectedPay === 'transfer' }" data-bs-parent="#payAccordion">
                      <div class="accordion-body">
                        <div class="d-flex justify-content-between align-items-center bg-dark p-2 rounded-3 mb-2">
                          <div><small class="text-muted">BCP — Cuenta corriente</small>
                            <div class="fw-bold">191-12345678-0-12</div>
                          </div>
                          <button class="btn btn-sm btn-outline-secondary"
                            @click="copiar('191-12345678-0-12')">Copiar</button>
                        </div>
                        <div class="d-flex justify-content-between align-items-center bg-dark p-2 rounded-3 mb-2">
                          <div><small class="text-muted">CCI</small>
                            <div class="fw-bold">00219100123456780</div>
                          </div>
                          <button class="btn btn-sm btn-outline-secondary"
                            @click="copiar('00219100123456780')">Copiar</button>
                        </div>
                        <p class="small text-warning mt-2">⚠️ Envía el comprobante a ventas@techstore.pe o por WhatsApp
                          para activar tu pedido.</p>
                      </div>
                    </div>
                  </div>
                  <!-- Efectivo -->
                  <div class="accordion-item bg-dark border-secondary">
                    <h2 class="accordion-header">
                      <button class="accordion-button collapsed bg-dark text-white" type="button"
                        data-bs-toggle="collapse" data-bs-target="#payCash" @click="selectedPay = 'cash'">
                        💵 Pago en efectivo
                      </button>
                    </h2>
                    <div id="payCash" class="accordion-collapse collapse" :class="{ show: selectedPay === 'cash' }"
                      data-bs-parent="#payAccordion">
                      <div class="accordion-body">
                        <p>Paga en efectivo cuando recibas tu pedido o en nuestra tienda física.</p>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="form-check mt-3">
                  <input class="form-check-input" type="checkbox" id="terms" v-model="acceptTerms">
                  <label class="form-check-label text-white-50" for="terms">Acepto los <a href="#"
                      class="text-danger">Términos y condiciones</a> y la <a href="#" class="text-danger">Política de
                      privacidad</a>.</label>
                </div>

                <button class="btn btn-danger w-100 mt-4 py-3 fw-bold" @click="confirmarPedido"
                  :disabled="!acceptTerms">
                  🔒 Confirmar y pagar — S/ {{ totalFinal.toLocaleString() }}
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Resumen del pedido (derecha) -->
        <div class="col-lg-5">
          <div class="summary-card bg-dark-2 rounded-4 border border-secondary position-sticky" style="top: 20px;">
            <div class="p-3 border-bottom border-secondary">
              <div class="d-flex justify-content-between align-items-center">
                <h5 class="m-0 fw-bold">TU PEDIDO</h5>
                <span class="badge bg-secondary">{{ cartStore.totalItems }} productos</span>
              </div>
            </div>
            <div class="p-3">
              <div v-for="item in cartStore.items" :key="item.id" class="d-flex gap-3 mb-3">
                <div class="position-relative">
                  <img :src="item.imagen" class="rounded-3" style="width: 60px; height: 60px; object-fit: cover;">
                  <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">{{
                    item.cantidad }}</span>
                </div>
                <div class="flex-grow-1">
                  <div class="fw-bold">{{ item.nombre }}</div>
                  <div class="small text-muted">S/ {{ item.precio.toFixed(2) }} c/u</div>
                </div>
                <div class="fw-bold text-danger">S/ {{ (item.precio * item.cantidad).toFixed(2) }}</div>
              </div>
            </div>

            <!-- Cupón -->
            <div class="px-3 mb-3">
              <div class="input-group">
                <input type="text" class="form-control bg-dark text-white border-secondary"
                  placeholder="🏷️ Código de descuento..." v-model="cuponCode">
                <button class="btn btn-outline-danger" @click="aplicarCupon">Aplicar</button>
              </div>
              <div v-if="cuponAplicado" class="mt-2 small text-success d-flex justify-content-between">
                <span>🎟️ Cupón TECH20 — 20% aplicado</span>
                <button class="btn btn-sm btn-link text-danger" @click="removerCupon">×</button>
              </div>
            </div>

            <div class="p-3 border-top border-secondary">
              <div class="d-flex justify-content-between small mb-1">
                <span class="text-muted">Subtotal</span>
                <span>S/ {{ subtotal.toLocaleString() }}</span>
              </div>
              <div v-if="descuentoProductos > 0" class="d-flex justify-content-between small text-success mb-1">
                <span>🏷️ Descuento en productos</span>
                <span>- S/ {{ descuentoProductos.toLocaleString() }}</span>
              </div>
              <div v-if="cuponAplicado" class="d-flex justify-content-between small text-warning mb-1">
                <span>🎟️ Cupón TECH20 (20%)</span>
                <span>- S/ {{ descuentoCupon.toLocaleString() }}</span>
              </div>
              <div class="d-flex justify-content-between small mb-2">
                <span class="text-muted">🚚 Envío</span>
                <span class="text-success">Gratis</span>
              </div>
              <hr class="border-secondary">
              <div class="d-flex justify-content-between fw-bold fs-5">
                <span>Total</span>
                <span class="text-danger">S/ {{ totalFinal.toLocaleString() }}</span>
              </div>
              <div class="text-end small text-muted">Incluye IGV: S/ {{ igv.toLocaleString() }}</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal de éxito -->
    <div class="modal fade" id="successModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-success">
          <div class="modal-body text-center p-4">
            <div
              class="success-ring mx-auto mb-3 d-flex align-items-center justify-content-center bg-success bg-opacity-10 rounded-circle"
              style="width: 80px; height: 80px;">
              <i class="bi bi-check-lg fs-1 text-success"></i>
            </div>
            <h3 class="fw-bold">¡PEDIDO CONFIRMADO!</h3>
            <p class="text-muted">Tu orden ha sido procesada exitosamente. Recibirás un correo de confirmación y te
              contactaremos por WhatsApp.</p>

            <div class="order-num badge bg-danger mb-3"># ORD-{{ orderNumber }}-{{ new Date().getTime() }}</div>
            <div class="d-grid gap-2">
              <button class="btn btn-success" @click="irAPedidos">📦 Ver estado de mi pedido</button>
              <button class="btn btn-outline-light" data-bs-dismiss="modal">← Seguir comprando</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <AuthModal ref="authModalRef" />
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useCarritoStore } from '@/stores/carrito'
import { Modal } from 'bootstrap'
import Swal from "sweetalert2/dist/sweetalert2.js";

import { ordenService } from '@/services/portal/ordenService';

import { useClientAuthStore } from '@/stores/clientAuth'
import AuthModal from '@/components/portal/AuthModal.vue'

const router = useRouter()
const cartStore = useCarritoStore()
const step = ref(2) // comenzamos en paso 2 (checkout)
const orderNumber = ref('');


const clientAuth = useClientAuthStore()
const authModalRef = ref<InstanceType<typeof AuthModal> | null>(null)
const guestMode = ref(false)

const tipoDocumento = computed(() => {
  const length = form.value.dni.trim().length
  if (length === 8) return 'DNI'
  if (length === 11) return 'RUC'
  if (length > 0) return 'CE'
  return ''
})

// Datos del formulario
const form = ref({
  nombres: '',
  apellidos: '',
  email: '',
  telefono: '',
  dni: '',
  tipoDocumento: '',
  crearCuenta: false,
  direccion: '',
  departamento: '',
  provincia: '',
  distrito: '',
  referencia: '',
  ruc: '',
  razonSocial: '',
  dirFiscal: ''
})
const entregaTipo = ref('despacho') // despacho o tienda
const comprobanteTipo = ref('boleta')
const acceptTerms = ref(false)

// Métodos de pago y tarjeta
const selectedPay = ref('card')
const cardNumber = ref('')
const cardName = ref('')
const cardMonth = ref('01')
const cardYear = ref(new Date().getFullYear())
const cardCvv = ref('')
const cuotasSeleccionadas = ref(1)

// Cupón
const cuponCode = ref('')
const cuponAplicado = ref(false)

// Computed del carrito
const subtotal = computed(() => cartStore.totalPrecio)
const descuentoProductos = computed(() => 0) // podrías calcular si tienes ofertas
const descuentoCupon = computed(() => cuponAplicado.value ? subtotal.value * 0.20 : 0)
const totalFinal = computed(() => subtotal.value - descuentoProductos.value - descuentoCupon.value)
const igv = computed(() => totalFinal.value * 0.18)

// Tarjeta visual
const cardNumberDisplay = computed(() => {
  let num = cardNumber.value.replace(/\s/g, '')
  num = num.padEnd(16, '•').slice(0, 16)
  return num.match(/.{1,4}/g)?.join(' ') || '•••• •••• •••• ••••'
})
const cardNameDisplay = computed(() => cardName.value.toUpperCase())
const cardExpiry = computed(() => `${cardMonth.value}/${cardYear.value}`)
const cardBrand = computed(() => {
  const num = cardNumber.value.replace(/\s/g, '')
  if (num.startsWith('4')) return 'VISA'
  if (num.startsWith('5')) return 'MASTERCARD'
  if (num.startsWith('3')) return 'AMEX'
  return 'VISA'
})

// Validación de secciones
const datosCompletos = computed(() => form.value.nombres && form.value.apellidos && form.value.email && form.value.telefono)
const entregaCompleta = computed(() => {
  if (entregaTipo.value === 'despacho') return form.value.direccion
  return true
})

// Funciones
const buscarDni = () => {
  // Simulación: podrías llamar a una API
  if (form.value.dni.length >= 8) {
    form.value.nombres = 'Carlos Alberto'
    form.value.apellidos = 'Ramírez Torres'
  }
}
const formatCardNumber = () => {
  let v = cardNumber.value.replace(/\D/g, '').substring(0, 16)
  cardNumber.value = v.match(/.{1,4}/g)?.join(' ') || v
}
const copiar = (texto: string) => {
  navigator.clipboard.writeText(texto)
}
const aplicarCupon = () => {
  if (cuponCode.value.toUpperCase() === 'TECH20') {
    cuponAplicado.value = true
  }
}
const removerCupon = () => {
  cuponAplicado.value = false
  cuponCode.value = ''
}
const editSection = (section: string) => {
  // scroll al section correspondiente
  const el = document.querySelector(`.form-section:has(.section-header:contains('${section}'))`)
  el?.scrollIntoView({ behavior: 'smooth' })
}

const confirmarPedido = async () => {
  if (!acceptTerms.value) {
    // mostrar alerta o tooltip
    return;
  }
  if (!cartStore.items.length) {
    Swal.fire({
      icon: 'warning',
      title: 'Carrito vacío',
      text: 'Agrega productos antes de continuar.'
    });
    return;
  }
  // Validar campos requeridos según tipo de entrega y comprobante
  if (!form.value.nombres || !form.value.apellidos || !form.value.email || !form.value.telefono) {
    Swal.fire({
      icon: 'warning',
      title: 'Datos de contacto incompletos',
      text: 'Por favor, completa tus datos de contacto.'
    });
    return;
  }
  if (entregaTipo.value === 'despacho' && !form.value.direccion) {
    Swal.fire({
      icon: 'warning',
      title: 'Datos de entrega incompletos',
      text: 'Por favor, completa tus datos de entrega.'
    });
    return;
  }
  if (comprobanteTipo.value === 'factura' && (!form.value.ruc || !form.value.razonSocial)) {
    Swal.fire({
      icon: 'warning',
      title: 'Datos de factura incompletos',
      text: 'Por favor, completa tus datos de factura.'
    });
    return;
  }

  // Si no está autenticado y no estamos en modo invitado, mostrar modal
  if (!clientAuth.isAuthenticated && !guestMode.value) {
    localStorage.setItem('checkoutState', JSON.stringify({
      form: form.value,
      entregaTipo: entregaTipo.value,
      comprobanteTipo: comprobanteTipo.value,
      cuponAplicado: cuponAplicado.value,
      selectedPay: selectedPay.value,
    }))
    authModalRef.value?.show()
    return
  }

  // Construir payload (incluir cliente token si está autenticado)
  const headers: any = {}
  if (clientAuth.isAuthenticated) {
    headers.Authorization = `Bearer ${clientAuth.token}`
  }
  const payload = {
    nombres: form.value.nombres,
    apellidos: form.value.apellidos,
    email: form.value.email,
    telefono: form.value.telefono,
    dni: form.value.dni,
    direccion: entregaTipo.value === 'despacho' ? form.value.direccion : null,
    departamento: entregaTipo.value === 'despacho' ? form.value.departamento : null,
    provincia: entregaTipo.value === 'despacho' ? form.value.provincia : null,
    distrito: entregaTipo.value === 'despacho' ? form.value.distrito : null,
    referencia: entregaTipo.value === 'despacho' ? form.value.referencia : null,
    entrega_tipo: entregaTipo.value,
    comprobante_tipo: comprobanteTipo.value,
    ruc: comprobanteTipo.value === 'factura' ? form.value.ruc : null,
    razon_social: comprobanteTipo.value === 'factura' ? form.value.razonSocial : null,
    metodo_pago: selectedPay.value,
    cupon: cuponAplicado.value ? cuponCode.value.toUpperCase() : null,
    subtotal: subtotal.value,
    descuento: descuentoProductos.value + descuentoCupon.value,
    total: totalFinal.value,
    items: cartStore.items.map(item => ({
      id: item.id,
      cantidad: item.cantidad,
      precio: item.precio
    }))

  };
  try {
    const response = await ordenService.crearOrden(payload, headers);
    console.log(response);
    if (response.success) {
      // Mostrar modal de éxito (actualizar contenido con número de pedido)
      orderNumber.value = response.order_number;
      const modalEl = document.getElementById('successModal');
      if (modalEl) {
        const modal = new Modal(modalEl);
        modal.show();
      }
      // Vaciar carrito
      cartStore.vaciarCarrito();//vaciarCarrito() es una función que limpia el carrito que viene de carrito.ts
    } else {
      alert('Error: ' + response.message);
    }
  }
  catch (error: any) {
    console.error(error);
    if (error.response && error.response.data.errors) {
      const errors = error.response.data.errors;
      let msg = '';
      for (let key in errors) msg += `${key}: ${errors[key][0]}\n`;
      alert('Errores de validación:\n' + msg);
    } else {
      alert('Hubo un error al procesar tu pedido. Intenta nuevamente.');
    }
  }
}
const irAPedidos = () => {
  router.push('/mi-cuenta/pedidos')
}

// Al montar, verificar si hay estado guardado
onMounted(() => {
  window.addEventListener('checkout:guest', () => {
    console.log('EVENTO RECIBIDO')
    guestMode.value = true
    confirmarPedido()
  })

  const savedState = localStorage.getItem('checkoutState')//obtener el estado guardado del carrito
  if (savedState) {//si hay estado guardado
    const state = JSON.parse(savedState)//parsear el estado
    // restaurar valores del formulario
    form.value = { ...form.value, ...state.form }//copiar los valores del formulario
    entregaTipo.value = state.entregaTipo
    comprobanteTipo.value = state.comprobanteTipo
    cuponAplicado.value = state.cuponAplicado
    selectedPay.value = state.selectedPay
    localStorage.removeItem('checkoutState')
  }
  // Si el cliente está autenticado, precargar datos
  if (clientAuth.isAuthenticated && clientAuth.client) {
    form.value.nombres = clientAuth.client.name || ''
    form.value.apellidos = clientAuth.client.surname || ''
    form.value.email = clientAuth.client.email || ''
    form.value.telefono = clientAuth.client.phone || ''
    form.value.dni = clientAuth.client.n_document || ''
  }
  if (cartStore.items.length === 0) {//si el carrito esta vacio
    router.push('/catalogo')//redirigir a la tienda
  }
})

</script>

<style scoped>
.bg-dark-2 {
  background-color: #111;
}

.topbar,
.stepper {
  background-color: #0a0a0a;
}

.step-circle {
  width: 32px;
  height: 32px;
  background: #222;
  border: 2px solid #444;
  color: #aaa;
}

.step.active .step-circle {
  background: #dc3545;
  border-color: #dc3545;
  color: white;
  box-shadow: 0 0 12px rgba(220, 53, 69, 0.3);
}

.step.done .step-circle {
  background: #00c896;
  border-color: #00c896;
  color: white;
}

.step-line {
  width: 60px;
  height: 2px;
  background: #444;
}

.step-line.done {
  background: #00c896;
}

.section-num {
  width: 28px;
  height: 28px;
  background: #dc3545;
  box-shadow: 0 0 8px rgba(220, 53, 69, 0.3);
}

.section-num.done {
  background: #00c896;
}

.credit-card-visual {
  background: linear-gradient(135deg, #1a0005, #2d0010);
  border: 1px solid rgba(220, 53, 69, 0.2);
}

.tracking-wider {
  letter-spacing: 2px;
}

.success-ring {
  animation: ring-pop 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes ring-pop {
  from {
    transform: scale(0);
    opacity: 0;
  }

  to {
    transform: scale(1);
    opacity: 1;
  }
}
</style>