<template>
  <b-col md="8">
    <b-tabs nav-class="mb-3">
      <b-tab title="Post" active title-link-class="fw-medium">
        <PostTab />
      </b-tab>
      <b-tab title="Gallery" class="p-3" title-link-class="fw-medium">
        <GalleryTab />
      </b-tab>
      <b-tab title="Settings" class="p-3" title-link-class="fw-medium">
        <SettingsTab />
      </b-tab>
    </b-tabs>
  </b-col>
</template>
<script setup lang="ts">
import PostTab from "@/views/pages/profile/components/PostTab.vue";
import GalleryTab from "@/views/pages/profile/components/GalleryTab.vue";
import SettingsTab from "@/views/pages/profile/components/SettingsTab.vue";
</script>
