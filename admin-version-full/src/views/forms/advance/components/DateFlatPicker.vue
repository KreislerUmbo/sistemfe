<template>
  <b-col md="6" lg="6">
    <UIComponentCard title="Flatpicker">
      <b-row>
        <b-col md="12">
          <label class="mb-2">Default Datepicker</label>
          <FlatPicker
            id="basic-datepicker"
            v-model="date"
            placeholder="Default Date"
            class="mb-3"
          />

          <label class="mb-2">Date Range Picker</label>
          <FlatPicker
            id="DateRange"
            placeholder="2024-07-03 to 2024-07-24"
            v-model="dateRange"
            :options="{ mode: 'range' }"
          />
        </b-col>
        <b-col md="6">
          <label class="my-2">Inline Datepicker</label>
          <FlatPicker
            id="inline_calendar"
            placeholder="Inline calendar"
            v-model="dateInline"
            :options="{ inline: true }"
          />
        </b-col>
      </b-row>
    </UIComponentCard>
  </b-col>
</template>
<script setup lang="ts">
import { ref } from "vue";
import FlatPicker from "@/components/FlatPicker.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";

const date = ref();
const dateRange = ref();
const dateInline = ref();
</script>
