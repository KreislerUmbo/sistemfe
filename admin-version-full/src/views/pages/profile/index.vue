<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <ProfileInfo />
    </b-row>

    <b-row class="justify-content-center">
      <PersonalInformation />
      <ProfileTabs />
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import ProfileInfo from "@/views/pages/profile/components/ProfileInfo.vue";
import PersonalInformation from "@/views/pages/profile/components/PersonalInformation.vue";
import ProfileTabs from "@/views/pages/profile/components/ProfileTabs.vue";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
