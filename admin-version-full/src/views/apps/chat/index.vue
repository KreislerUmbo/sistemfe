<template>
  <DefaultLayout>
    <b-row>
      <b-col cols="12">
        <ContactList />
        <ChatBox />
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import ContactList from "@/views/apps/chat/components/ContactList.vue";
import ChatBox from "@/views/apps/chat/components/ChatBox.vue";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
