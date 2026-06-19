<template>
  <b-card>
    <template #header>
      <h5 class="mb-0"><i class="fas fa-tags me-2"></i>Precios y Planes</h5>
    </template>

    <b-row>
      <!-- Plan mensual -->
      <b-col md="6">
        <b-form-group label="Plan mensual (Precio x Mes)" description=" (S/)">
          <b-input-group>
            <b-input-group-text>S/</b-input-group-text>
            <b-form-input type="number" v-model="localPlans[0].price" min="0" step="1" @input="updatePlans"
              placeholder="99" />
          </b-input-group>

          <small class="text-muted"> Facturado mensualmente</small>
        </b-form-group>
      </b-col>
      <!-- Descuento de Plan mensual -->
      <b-col md="6">
        <b-form-group label="Desc. Plan Mensual">
          <b-input-group>
            <b-input-group-text>%</b-input-group-text>
            <b-form-input type="number" v-model="localPlans[1].discount" min="0" step="1" @input="updatePlans"
              placeholder="0" />
          </b-input-group>
        </b-form-group>
      </b-col>
      <b-col md="6"> </b-col>
      <b-col md="6">
        <b-form-group label="Plan Activo">
          <div class="d-flex gap-3">
            <b-form-radio v-model="state" name="state" value="1">Activo</b-form-radio>
            <b-form-radio v-model="state" name="state" value="2">Inactivo</b-form-radio>
          </div>
        </b-form-group>

      </b-col>
    </b-row>
    <hr>
    <b-row>
      <!-- Plan Semesrtral -->
      <b-col md="6">
        <b-form-group label="Plan semestral" description=" Precio por mes, facturado semestralmente">
          <b-input-group>
            <b-input-group-text>S/</b-input-group-text>
            <b-form-input type="number" v-model="localPlans[2].price" min="0" step="1" @input="updatePlans"
              placeholder="89" />
          </b-input-group>

          <div class="d-flex align-items-center gap-2 mt-1">
            <b-badge variant="success" v-if="discountPercent > 0">
              Ahorra {{ discountPercent }}%
            </b-badge>
            <span v-else class="text-muted small">Sin descuento</span>
          </div>
          <small class="text-muted">
            Total semestral: S/ {{ (localPlans[2].price || 0) * 12 }}
          </small>
        </b-form-group>
      </b-col>
      <!-- Descuento de Plan Semesrtral -->
      <b-col md="6">
        <b-form-group label="Descuento Plan semestral">
          <b-input-group>
            <b-input-group-text>%</b-input-group-text>
            <b-form-input type="number" v-model="localPlans[2].discount" min="0" step="1" @input="updatePlans"
              placeholder="0" />
          </b-input-group>
        </b-form-group>
      </b-col>


      <!-- Plan anual -->
      <b-col md="6">
        <b-form-group label="Plan anual" description="Precio por mes, facturado anualmente">
          <b-input-group>
            <b-input-group-text>S/</b-input-group-text>
            <b-form-input type="number" v-model="localPlans[1].price" min="0" step="1" @input="updatePlans"
              placeholder="79" />
          </b-input-group>

          <div class="d-flex align-items-center gap-2 mt-1">
            <b-badge variant="success" v-if="discountPercent > 0">
              Ahorra {{ discountPercent }}%
            </b-badge>
            <span v-else class="text-muted small">Sin descuento</span>
          </div>
          <small class="text-muted">
            Total anual: S/ {{ (localPlans[1].price || 0) * 12 }}
          </small>
        </b-form-group>
      </b-col>
      <!-- Descuento de Plan anual -->
      <b-col md="6">
        <b-form-group label="Descuento  Plan anual">
          <b-input-group>
            <b-input-group-text>%</b-input-group-text>
            <b-form-input type="number" v-model="localPlans[1].discount" min="0" step="1" @input="updatePlans"
              placeholder="0" />
          </b-input-group>
        </b-form-group>
      </b-col>


      <b-col md="6">
        <b-form-group label="Usuarios Maximos">
          <b-input-group>
            <b-input-group-text></b-input-group-text>
            <b-form-input type="number" v-model="usuarios_maximos" min="0" step="1" @input="updatePlans"
              placeholder="0" />
          </b-input-group>
        </b-form-group>
      </b-col>
    </b-row>

    <!-- Resumen visual (opcional) -->
    <b-row class="mt-3">
      <b-col>
        <div class="p-3 bg-light rounded d-flex justify-content-around">
          <div class="text-center">
            <div class="small text-muted">Mensual</div>
            <div class="h4 mb-0">S/ {{ localPlans[0]?.price || 0 }}</div>
            <div class="small text-muted">/mes</div>
          </div>
          <div class="text-center">
            <div class="small text-muted">Anual</div>
            <div class="h4 mb-0 text-success">
              S/ {{ localPlans[1]?.price || 0 }}
              <small class="text-muted" style="font-size: 14px;">/mes</small>
            </div>
            <div class="small text-muted">
              Total S/ {{ (localPlans[1]?.price || 0) * 12 }} al año
            </div>
          </div>
          <div class="text-center">
            <div class="small text-muted">Ahorro anual</div>
            <div class="h5 mb-0 text-success">
              {{ annualSavings > 0 ? 'S/ ' + annualSavings : '—' }}
            </div>
            <div class="small text-muted">
              {{ discountPercent > 0 ? discountPercent + '%' : 'Sin descuento' }}
            </div>
          </div>
        </div>
      </b-col>
    </b-row>
  </b-card>
</template>

<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
  plans: {
    type: Array,
    default: () => [
      {
        plan_type: 'monthly',
        price: 100,
        duration: 1,
        discount_percent: 0
      },
      {
        plan_type: 'annual',
        price: 0,
        duration: 12,
        discount_percent: 20
      },
      {
        plan_type: 'semestral',
        price: 0,
        duration: 6,
        discount_percent: 10
      }
    ]
  }
});


const emit = defineEmits(['update:plans']);

// Copia local de los planes
const localPlans = ref([
  { ...props.plans[0] },
  { ...props.plans[1] },
  { ...props.plans[2] }

]);

// Calcular descuento porcentual
const discountPercent = computed(() => {
  const monthly = localPlans.value[0]?.price || 0;
  const annual = localPlans.value[1]?.price || 0;
  const semestral = localPlans.value[2]?.price || 0;

  if (monthly > 0 && annual > 0 && annual < monthly) {
    return Math.round((1 - annual / monthly) * 100);
  }
  return 0;
});

// Calcular ahorro anual en soles
const annualSavings = computed(() => {
  const monthly = localPlans.value[0]?.price || 0;
  const annual = localPlans.value[1]?.price || 0;
  const semestral = localPlans.value[2]?.price || 0;

  if (monthly > 0 && annual > 0 && annual < monthly) {
    return (monthly - annual) * 12;
  }
  return 0;
});

// Actualizar el padre cuando cambien los precios
function updatePlans() {
  // Asegurar que ambos planes tengan plan_type
  localPlans.value[0].plan_type = 'monthly';
  localPlans.value[1].plan_type = 'annual';
  localPlans.value[2].plan_type = 'semestral';

  // Calcular descuento automáticamente
  const monthly = localPlans.value[0]?.price || 0;
  const annual = localPlans.value[1]?.price || 0;
  const semestral = localPlans.value[2]?.price || 0;

  if (monthly > 0 && annual > 0 && annual < monthly) {// Descuento de 20%
    localPlans.value[1].discount_percent = Math.round((1 - annual / monthly) * 100);
  } else {
    localPlans.value[1].discount_percent = 0;
  }
  // Emitir al padre
  const newPlans = [...localPlans.value];
  if (JSON.stringify(newPlans) !== JSON.stringify(props.plans)) {
    emit('update:plans', newPlans);
  }
}

// Sincronizar con el prop del padre (si cambia externamente)
watch(() => props.plans, (newVal) => {
  if (newVal && newVal.length === 2) {
    localPlans.value = [
      { ...newVal[0] },
      { ...newVal[1] },
      { ...newVal[2] }

    ];
  }
}, { deep: true, immediate: true });

// Inicializar (emitir al montar)
if (!props.plans || props.plans.length === 0) {
  updatePlans();
}
</script>

<style scoped>
/* Estilos opcionales para mejorar la visualización */
.bg-light {
  background-color: #f8f9fa !important;
}
</style>