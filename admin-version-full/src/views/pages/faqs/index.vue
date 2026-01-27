<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <AskedQuestions />
    </b-row>

    <b-row class="justify-content-center">
      <QuestionsWithAccordion />
      <HaveMoreQuestions />
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import AskedQuestions from "@/views/pages/faqs/components/AskedQuestions.vue";
import QuestionsWithAccordion from "@/views/pages/faqs/components/QuestionsWithAccordion.vue";
import HaveMoreQuestions from "@/views/pages/faqs/components/HaveMoreQuestions.vue";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
