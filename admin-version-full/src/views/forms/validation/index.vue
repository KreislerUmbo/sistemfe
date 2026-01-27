<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Vue Bootstrap">
          <VBForm />
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Validation Tooltips">
          <ValidationTooltipsForm />
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row>
      <b-col md="6" lg="6">
        <UIComponentCard title="Vuelidate Form">
          <VuelidateForm />
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import VBForm from "@/views/forms/validation/components/VBForm.vue";
import VuelidateForm from "@/views/forms/validation/components/VuelidateForm.vue";
import ValidationTooltipsForm from "@/views/forms/validation/components/ValidationTooltipsForm.vue";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
