<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col cols="12">
        <b-card no-body style="background-color: #ffd88e3b">
          <b-card-body>
            <b-row>
              <div class="col">
                <h6 class="mb-2 mt-1 fw-medium text-dark fs-18">
                  Notifications
                </h6>
                <p class="text-body fs-14">
                  Contrary to popular belief, Lorem Ipsum is not simply random
                  text. It has roots in a piece of classical <br />
                  Latin literature from 45 BC, making it over 2000 years old.
                </p>
                <b-button type="button" variant="warning" class="px-2"
                  >View All</b-button
                >
              </div>
              <div class="col-auto align-self-center">
                <img :src="notification" alt="" height="90" class="rounded" />
              </div>
            </b-row>
          </b-card-body>
        </b-card>
        <template v-for="(notification, idx) in notificationsList" :key="idx">
          <NotificationCard :notification="notification" />
        </template>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import { notificationsList } from "@/views/pages/notifications/components/data";
import NotificationCard from "@/views/pages/notifications/components/NotificationCard.vue";
import notification from "@/assets/images/extra/card/notification.gif";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
