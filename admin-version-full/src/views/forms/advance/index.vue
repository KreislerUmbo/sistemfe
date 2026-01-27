<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <SelectComponents />
    </b-row>

    <b-row class="justify-content-center">
      <HuebeeColor />
      <CustomTouchSpin />
    </b-row>

    <b-row class="justify-content-center">
      <DateFlatPicker />
      <Mask />
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import SelectComponents from "@/views/forms/advance/components/SelectComponents.vue";
import HuebeeColor from "@/views/forms/advance/components/HuebeeColor.vue";
import CustomTouchSpin from "@/views/forms/advance/components/CustomTouchSpin.vue";
import DateFlatPicker from "@/views/forms/advance/components/DateFlatPicker.vue";
import Mask from "@/views/forms/advance/components/Mask.vue";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
