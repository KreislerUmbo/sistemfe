<template>
  <b-col cols="12">
    <UIComponentCard title="Selectr Components">
      <b-row class="g-2">
        <b-col md="4">
          <label class="mb-2">Default Select</label>
          <select id="default">
            <option value="value-1">Value 1</option>
            <option value="value-2">Value 2</option>
            <option value="value-3">Value 3</option>
          </select>
        </b-col>
        <b-col md="4">
          <label class="mb-2">Multi Select</label>
          <select id="multiSelect">
            <option value="value-1">Value 1</option>
            <option value="value-2">Value 2</option>
            <option value="value-3">Value 3</option>
          </select>
        </b-col>
        <b-col md="4">
          <label class="mb-2">Taggable Select</label>
          <select id="taggableSelect">
            <option value="value-1">Value 1</option>
            <option value="value-2">Value 2</option>
            <option value="value-3">Value 3</option>
          </select>
        </b-col>
      </b-row>
    </UIComponentCard>
  </b-col>
</template>
<script setup lang="ts">
import { onMounted } from "vue";
import Selectr from "mobius1-selectr";
import UIComponentCard from "@/components/UIComponentCard.vue";

onMounted(() => {
  new Selectr("#default");

  new Selectr("#multiSelect", {
    multiple: true,
  });

  new Selectr("#taggableSelect", {
    taggable: true,
    tagSeperators: [",", "|"],
  });
});
</script>
