<template>
  <div class="modal fade" id="authModal" tabindex="-1" aria-hidden="true" ref="modalRef">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content bg-dark text-white border-secondary">
        <div class="modal-header border-secondary">
          <h5 class="modal-title">Identifícate para continuar</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted mb-3">¿Ya tienes una cuenta? Inicia sesión o regístrate para hacer seguimiento de tus pedidos.</p>
          <div class="d-grid gap-2">
            <button class="btn btn-danger" @click="iniciarSesion">Iniciar sesión</button>
            <button class="btn btn-outline-danger" @click="registrarse">Registrarme</button>
            <button class="btn btn-outline-secondary" @click="continuarInvitado">Continuar como invitado</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Modal } from 'bootstrap'
import { useRouter } from 'vue-router'

const router = useRouter()
const modalRef = ref<HTMLElement | null>(null)
let modalInstance: Modal | null = null

// Guardar estado actual del checkout antes de redirigir
const saveCheckoutState = () => {
  // Guardamos los datos del formulario y carrito en localStorage
  const state = {
    form: {
      nombres: (document.querySelector('#nombres') as HTMLInputElement)?.value || '',
      apellidos: (document.querySelector('#apellidos') as HTMLInputElement)?.value || '',
      email: (document.querySelector('#email') as HTMLInputElement)?.value || '',
      telefono: (document.querySelector('#telefono') as HTMLInputElement)?.value || '',
      dni: (document.querySelector('#dni') as HTMLInputElement)?.value || '',
      direccion: (document.querySelector('#direccion') as HTMLInputElement)?.value || '',
      // ... otros campos
    },
    entregaTipo: (document.querySelector('input[name="entregaTipo"]:checked') as HTMLInputElement)?.value || 'despacho',
    comprobanteTipo: (document.querySelector('input[name="comprobanteTipo"]:checked') as HTMLInputElement)?.value || 'boleta',
    // ... otros
  }
  localStorage.setItem('checkoutState', JSON.stringify(state))
}

const show = () => {
  if (modalRef.value) {
    modalInstance = new Modal(modalRef.value)
    modalInstance.show()
  }
}

const hide = () => {
  modalInstance?.hide()
}

const iniciarSesion = () => {
  saveCheckoutState()
  hide()

  router.push({
    name: 'portal.login',
    query: {
      redirect: '/checkout'
    }
  })
}

const registrarse = () => {
  saveCheckoutState()
  hide()

  router.push({
    name: 'portal.register',
    query: {
      redirect: '/checkout'
    }
  })
}

const continuarInvitado = () => {
  console.log('INVITADO CLICK')
  hide()
  // Disparar evento para que el checkout sepa que puede continuar
  window.dispatchEvent(new CustomEvent('checkout:guest'))
}

defineExpose({ show, hide })
</script>