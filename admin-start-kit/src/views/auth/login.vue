<template>
  <div class="auth-split-wrapper">
    <b-row class="g-0 min-vh-100">
      <!-- Panel izquierdo: marca -->
      <b-col lg="5" class="auth-brand-panel d-none d-lg-flex flex-column justify-content-between p-5">
        <span class="brand-dots brand-dots-top" aria-hidden="true"></span>

        <div class="text-center my-auto position-relative">
          <div class="brand-logo-badge mb-4">
            <img :src="logoUrl || logoSm" alt="logo" class="auth-logo" @error="handleLogoError" />
          </div>
          <h2 class="text-white fw-bold mb-1">Bienvenido</h2>
          <p class="text-success fw-semibold mb-4">Plataforma de Administración</p>
          <div class="brand-divider mx-auto mb-4"></div>
          <p class="text-white-50 mb-0">
            Gestiona, organiza y controla<br />
            tu negocio desde un solo lugar.
          </p>
        </div>

        <span class="brand-dots brand-dots-bottom" aria-hidden="true"></span>
      </b-col>

      <!-- Panel derecho: formulario -->
      <b-col lg="7" class="d-flex align-items-center justify-content-center bg-white py-5 px-3 px-md-5">
        <div class="auth-form-col w-100">
          <img
            :src="logoUrl || logoSm"
            alt="logo"
            class="auth-logo d-lg-none d-block mx-auto mb-4"
            @error="handleLogoError"
          />

          <div class="text-center text-lg-start mb-4">
            <h3 class="fw-bold mb-1">Inicia sesión en tu cuenta</h3>
            <p class="text-muted mb-0">
              Ingresa tus credenciales para acceder a la plataforma de administración.
            </p>
          </div>

          <!-- model-value="true" es obligatorio acá: BAlert (bootstrap-vue-next) tiene su
          propio estado interno de visibilidad (defineModel<boolean>({ default: false })),
          separado del v-if que lo envuelve. Sin esto, el componente monta (v-if lo permite)
          pero se renderiza vacío por dentro siempre — bug real confirmado con Playwright,
          nunca mostró ningún mensaje de error desde que existe esta pantalla. El cierre
          (dismissible) sigue funcionando igual: @dismissed pone error/lockedUntil en su
          valor "vacío", lo que desmonta el alert entero vía el v-if externo. -->
          <b-alert
            v-if="error"
            :model-value="true"
            variant="danger"
            class="d-block"
            dismissible
            @dismissed="error = ''"
          >
            {{ error }}
          </b-alert>

          <b-alert v-if="lockedUntil" :model-value="true" variant="warning" class="d-block">
            <i class="fas fa-triangle-exclamation me-1"></i>
            Demasiados intentos fallidos con estas credenciales. Espera {{ secondsLeft }}s e
            inténtalo de nuevo, o verifica que el correo y la contraseña sean correctos.
          </b-alert>

          <b-form novalidate @submit.prevent="handleLogin">
            <b-form-group class="mb-3" label="Usuario" label-for="username">
              <div class="input-icon-group">
                <i class="fas fa-user input-icon" aria-hidden="true"></i>
                <b-form-input
                  id="username"
                  v-model="v.email.$model"
                  type="text"
                  autocomplete="username"
                  placeholder="Ingresa tu usuario"
                  class="ps-5"
                  :disabled="isLoading || !!lockedUntil"
                />
              </div>
              <div v-if="v.email.$error" class="text-danger small mt-1">
                <span v-for="(err, idx) in v.email.$errors" :key="idx">{{ err.$message }}</span>
              </div>
            </b-form-group>

            <b-form-group class="mb-2" label="Contraseña" label-for="userpassword">
              <div class="input-icon-group">
                <i class="fas fa-lock input-icon" aria-hidden="true"></i>
                <b-form-input
                  id="userpassword"
                  v-model="v.password.$model"
                  :type="showPassword ? 'text' : 'password'"
                  autocomplete="current-password"
                  placeholder="Ingresa tu contraseña"
                  class="ps-5 pe-5"
                  :disabled="isLoading || !!lockedUntil"
                />
                <button
                  type="button"
                  class="input-icon-toggle"
                  tabindex="-1"
                  :aria-label="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                  @click="showPassword = !showPassword"
                >
                  <i :class="showPassword ? 'fas fa-eye-slash' : 'fas fa-eye'"></i>
                </button>
              </div>
              <div v-if="v.password.$error" class="text-danger small mt-1">
                <span v-for="(err, idx) in v.password.$errors" :key="idx">{{ err.$message }}</span>
              </div>
            </b-form-group>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3 mb-4">
              <div class="form-switch-success">
                <b-form-checkbox v-model="rememberMe" switch>Recordarme</b-form-checkbox>
              </div>
              <router-link to="/auth/reset-pass" class="text-muted font-13">
                <i class="dripicons-lock"></i> ¿Olvidaste tu contraseña?
              </router-link>
            </div>

            <div class="d-grid">
              <b-button variant="success" type="submit" size="lg" :disabled="isLoading || !!lockedUntil">
                <span v-if="isLoading" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                {{ isLoading ? "Verificando..." : "Iniciar sesión" }}
                <i v-if="!isLoading" class="fas fa-arrow-right ms-1"></i>
              </b-button>
            </div>
          </b-form>

          <p class="text-center text-muted mt-4 mb-0">
            ¿No tienes una cuenta?
            <router-link to="/auth/register" class="text-success fw-semibold ms-1">
              Regístrate gratis
            </router-link>
          </p>

          <p class="text-center text-muted small mt-4 mb-0">
            <i class="fas fa-shield-halved me-1"></i>
            Tu información está protegida con encriptación de nivel empresarial.
          </p>
        </div>
      </b-col>
    </b-row>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted, onBeforeUnmount } from "vue";

import logoSm from "@/assets/images/logo-sm.png";
import { required, email } from "@vuelidate/validators";
import { useVuelidate } from "@vuelidate/core";

import HttpClient from "@/helpers/http-client";
import { useAuthStore } from "@/stores/auth";

import type { AxiosResponse } from "axios";
import type { ResponseAuthLogin } from "@/types/auth";

// --- Constantes de seguridad del lado del cliente ---------------------------------
// Esto es una capa de UX adicional (evita martillar el botón de "Iniciar sesión"),
// NO reemplaza el rate-limiting real, que debe vivir en el backend (middleware
// `throttle` en las rutas de auth/login — ver nota de seguridad entregada aparte).
const MAX_INTENTOS = 5;
const BLOQUEO_SEGUNDOS = 30;
const REMEMBER_KEY = "remembered_username";

const credentials = reactive({
  email: "",
  password: "",
});

const logoUrl = ref("");
const error = ref("");
const showPassword = ref(false);
const isLoading = ref(false);
const rememberMe = ref(false);

const intentosFallidos = ref(0);
const lockedUntil = ref<number | null>(null);
const secondsLeft = ref(0);
let lockInterval: ReturnType<typeof setInterval> | undefined;

onMounted(async () => {
  try {
    logoUrl.value = import.meta.env.VITE_APP_LOGO_URL || "";
  } catch (e) {
    // Falla silenciosa: si no hay logo configurado, se usa el fallback local.
  }

  // "Recordarme" solo guarda el usuario/email, NUNCA la contraseña.
  const remembered = localStorage.getItem(REMEMBER_KEY);
  if (remembered) {
    credentials.email = remembered;
    rememberMe.value = true;
  }
});

onBeforeUnmount(() => {
  if (lockInterval) clearInterval(lockInterval);
});

const handleLogoError = (e: Event) => {
  const img = e.target as HTMLImageElement;
  img.src = logoSm;
};

const vuelidateRules = computed(() => ({
  email: { required, email },
  password: { required },
}));

const v = useVuelidate(vuelidateRules, credentials);

const useAuth = useAuthStore();

const iniciarBloqueo = () => {
  lockedUntil.value = Date.now() + BLOQUEO_SEGUNDOS * 1000;
  secondsLeft.value = BLOQUEO_SEGUNDOS;
  if (lockInterval) clearInterval(lockInterval);
  lockInterval = setInterval(() => {
    const restante = Math.ceil(((lockedUntil.value || 0) - Date.now()) / 1000);
    if (restante <= 0) {
      lockedUntil.value = null;
      secondsLeft.value = 0;
      intentosFallidos.value = 0;
      if (lockInterval) clearInterval(lockInterval);
    } else {
      secondsLeft.value = restante;
    }
  }, 1000);
};

const handleLogin = async () => {
  if (lockedUntil.value) return;

  const result = await v.value.$validate();
  if (!result || isLoading.value) return;

  isLoading.value = true;
  error.value = "";

  try {
    const res: AxiosResponse<ResponseAuthLogin> = await HttpClient.post("auth/login", credentials);

    if (res.data.access_token) {
      useAuth.saveSession({
        ...res.data.user,
        token: res.data.access_token,
      });

      if (rememberMe.value) {
        localStorage.setItem(REMEMBER_KEY, credentials.email);
      } else {
        localStorage.removeItem(REMEMBER_KEY);
      }

      intentosFallidos.value = 0;
      redirectUser();
    }
  } catch (e: any) {
    // No se registra en consola la respuesta completa (podría exponer datos de la
    // cuenta o del intento fallido) — solo un mensaje genérico visible al usuario.
    intentosFallidos.value += 1;

    if (e.response?.data?.error) {
      error.value = e.response.data.error;
    } else {
      error.value = "No se pudo iniciar sesión. Verifica tu conexión e inténtalo de nuevo.";
    }

    if (intentosFallidos.value >= MAX_INTENTOS) {
      // No se limpia error.value acá a propósito: antes se borraba el motivo real
      // (ej. "Correo o contraseña incorrectos") justo cuando arrancaba el bloqueo,
      // dejando en pantalla solo el aviso de "demasiados intentos" sin decir POR QUÉ
      // — confuso para un usuario que no sabía si el sistema estaba roto o si de
      // verdad tenía mal la contraseña. Ahora se ven los dos mensajes juntos.
      iniciarBloqueo();
    }
  } finally {
    isLoading.value = false;
  }
};

const redirectUser = () => {
  window.location.reload();
};
</script>

<style scoped>
.auth-split-wrapper {
  min-height: 100vh;
}

.auth-brand-panel {
  position: relative;
  overflow: hidden;
  background: linear-gradient(160deg, #0b1220 0%, #101a2c 55%, #0b1220 100%);
}

.brand-logo-badge {
  width: 96px;
  height: 96px;
  margin-inline: auto;
  border-radius: 50%;
  background: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
}

.auth-logo {
  max-height: 50px;
  max-width: 70px;
  object-fit: contain;
}

.brand-divider {
  width: 56px;
  height: 3px;
  border-radius: 2px;
  background: var(--bs-success, #16a34a);
}

.brand-dots {
  position: absolute;
  width: 130px;
  height: 90px;
  background-image: radial-gradient(rgba(255, 255, 255, 0.18) 1.5px, transparent 1.5px);
  background-size: 16px 16px;
  pointer-events: none;
}

.brand-dots-top {
  top: 32px;
  left: 32px;
}

.brand-dots-bottom {
  bottom: 32px;
  right: 32px;
}

.auth-form-col {
  max-width: 420px;
  margin-inline: auto;
}

.input-icon-group {
  position: relative;
}

.input-icon {
  position: absolute;
  top: 50%;
  left: 1rem;
  transform: translateY(-50%);
  color: #94a3b8;
  pointer-events: none;
}

.input-icon-toggle {
  position: absolute;
  top: 50%;
  right: 0.75rem;
  transform: translateY(-50%);
  border: none;
  background: transparent;
  color: #94a3b8;
  padding: 0.25rem 0.5rem;
  line-height: 1;
}

.input-icon-toggle:hover {
  color: #475569;
}
</style>
