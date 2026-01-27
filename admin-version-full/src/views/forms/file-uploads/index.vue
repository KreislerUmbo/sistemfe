<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Custom File Upload">
          <CustomFileUpload />
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Uppy File Upload">
          <UppyFileUpload />
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import CustomFileUpload from "@/views/forms/file-uploads/components/CustomFileUpload.vue";
import UppyFileUpload from "@/views/forms/file-uploads/components/UppyFileUpload.vue";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
