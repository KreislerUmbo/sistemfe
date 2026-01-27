<template>
  <b-col md="6" lg="3" v-for="(item, idx) in folderList" :key="idx">
    <StatWidgetCard :item="item" />
  </b-col>
</template>
<script setup lang="ts">
import { folderList } from "@/views/advanced/file-manager/components/data";
import StatWidgetCard from "@/views/advanced/file-manager/components/StatWidgetCard.vue";
</script>
