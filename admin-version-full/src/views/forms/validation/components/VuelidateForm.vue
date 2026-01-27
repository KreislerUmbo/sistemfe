<template>
  <b-form @submit.prevent="handleVuelidateSubmit">
    <div class="mb-2">
      <b-form-group label="Username">
        <b-form-input type="text" v-model="v.username.$model" />
        <div v-if="v.username.$error" class="text-danger">
          Username must be at least 3 characters
        </div>
      </b-form-group>
    </div>

    <div class="mb-2">
      <b-form-group label="Email">
        <b-form-input type="email" v-model="v.email.$model" />
        <div v-if="v.email.$error" class="text-danger">
          Email is not invalid
        </div>
      </b-form-group>
    </div>

    <div class="mb-2">
      <b-form-group label="Password">
        <b-form-input type="password" v-model="v.password.$model" />
        <div v-if="v.password.$error" class="text-danger">
          Password must be at least 6 characters
        </div>
      </b-form-group>
    </div>

    <div class="mb-3">
      <b-form-group label="Confirm Password">
        <b-form-input type="password" v-model="v.password2.$model" />
        <div v-if="v.password2.$error" class="text-danger">
          Password2 is required
        </div>
      </b-form-group>
    </div>

    <b-button variant="primary" type="submit"> Submit form </b-button>
  </b-form>
</template>

<script setup lang="ts">
import { reactive, computed } from "vue";
import { required } from "@vuelidate/validators";
import { useVuelidate } from "@vuelidate/core";

const vuelidateState = reactive({
  username: undefined,
  email: undefined,
  password: undefined,
  password2: undefined,
});

const vuelidateRules = computed(() => ({
  username: { required },
  email: { required },
  password: { required },
  password2: { required },
}));

const v = useVuelidate(vuelidateRules, vuelidateState);

const handleVuelidateSubmit = async () => {
  const result = await v.value.$validate();
};
</script>
