<template>
  <b-form class="row g-3">
    <b-col md="4" class="position-relative">
      <b-form-group label="First name">
        <b-form-input
          type="text"
          v-model="vbState.firstName"
          :state="validateFirstName"
        />
        <b-form-valid-feedback tooltip :state="validateFirstName"
          >Looks good!</b-form-valid-feedback
        >
        <b-form-invalid-feedback tooltip :state="validateFirstName"
          >Please provide valid first name.
        </b-form-invalid-feedback>
      </b-form-group>
    </b-col>

    <b-col md="4" class="position-relative">
      <b-form-group label="Last name">
        <b-form-input
          type="text"
          v-model="vbState.lastName"
          :state="validateLastName"
        />
        <b-form-valid-feedback tooltip :state="validateLastName"
          >Looks good!</b-form-valid-feedback
        >
        <b-form-invalid-feedback tooltip :state="validateLastName"
          >Please provide valid last name.
        </b-form-invalid-feedback>
      </b-form-group>
    </b-col>

    <b-col md="4" class="position-relative">
      <b-form-group label="Username">
        <b-input-group>
          <b-input-group-text>@</b-input-group-text>
          <b-form-input type="text" v-model="vbState.username" />
        </b-input-group>
        <b-form-valid-feedback tooltip :state="validateUserName"
          >Looks good!</b-form-valid-feedback
        >
        <b-form-invalid-feedback tooltip :state="validateUserName"
          >Please provide valid username.
        </b-form-invalid-feedback>
      </b-form-group>
    </b-col>

    <b-col md="6" class="position-relative">
      <b-form-group label="City">
        <b-form-input type="text" v-model="vbState.city" />
        <b-form-valid-feedback tooltip :state="validateCity"
          >Looks good!</b-form-valid-feedback
        >
        <b-form-invalid-feedback tooltip :state="validateCity"
          >City is required.
        </b-form-invalid-feedback>
      </b-form-group>
    </b-col>

    <b-col md="3" class="position-relative">
      <b-form-group label="State">
        <b-form-select v-model="vbState.state">
          <option selected disabled value="">Choose...</option>
          <option>...</option>
        </b-form-select>
        <b-form-valid-feedback tooltip :state="validateState"
          >Looks good!</b-form-valid-feedback
        >
        <b-form-invalid-feedback tooltip :state="validateState"
          >State is required.
        </b-form-invalid-feedback>
      </b-form-group>
    </b-col>

    <b-col md="3" class="position-relative">
      <b-form-group label="Zip">
        <b-form-input v-model="vbState.zip" type="text" />
        <b-form-valid-feedback tooltip :state="validateZip"
          >Looks good!</b-form-valid-feedback
        >
        <b-form-invalid-feedback tooltip :state="validateZip"
          >Zip code is required.
        </b-form-invalid-feedback>
      </b-form-group>
    </b-col>
    <b-col cols="12">
      <b-button variant="primary" type="submit"> Submit form </b-button>
    </b-col>
  </b-form>
</template>

<script setup lang="ts">
import { reactive, computed } from "vue";

const vbState = reactive({
  firstName: "",
  lastName: "",
  username: "",
  city: "",
  state: "",
  zip: "",
});

const validateFirstName = computed(() => {
  return vbState.firstName.length > 0 && vbState.firstName.length < 15;
});
const validateLastName = computed(() => {
  return vbState.lastName.length > 0 && vbState.lastName.length < 15;
});
const validateUserName = computed(() => {
  return vbState.username.length > 0 && vbState.username.length < 15;
});
const validateCity = computed(() => {
  return vbState.city.length > 0 && vbState.city.length < 15;
});
const validateState = computed(() => {
  return vbState.state.length > 0 && vbState.state.length < 15;
});
const validateZip = computed(() => {
  return vbState.zip.length > 0 && vbState.zip.length <= 6;
});
</script>
