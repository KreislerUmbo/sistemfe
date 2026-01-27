<template>
  <DefaultLayout>
    <b-row>
      <b-col cols="12">
        <div class="mb-3">
          <b-card-body>
            <FullCalendar
              ref="fullCalendar"
              :bootstrap-font-awesome="false"
              :options="calendarOptions"
            />
          </b-card-body>
        </div>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import { ref } from "vue";
import FullCalendar from "@fullcalendar/vue3";
import dayGridPlugin from "@fullcalendar/daygrid";
import timeGridPlugin from "@fullcalendar/timegrid";
import { type CalendarOptions } from "@fullcalendar/core";
import { initialEvents } from "@/views/apps/calendar/components/data";

const calendarOptions = ref<CalendarOptions>({
  plugins: [dayGridPlugin, timeGridPlugin],
  initialView: "dayGridMonth",
  handleWindowResize: true,
  headerToolbar: {
    left: "prev,next today",
    center: "title",
    right: "dayGridMonth,timeGridWeek,timeGridDay",
  },
  editable: true,
  selectable: true,
  events: initialEvents,
});

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
