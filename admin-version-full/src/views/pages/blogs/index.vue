<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="4" v-for="(item, idx) in blogData" :key="idx">
        <BlogCard :item="item" />
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import { blogData } from "@/views/pages/blogs/components/data";
import BlogCard from "@/views/pages/blogs/components/BlogCard.vue";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
