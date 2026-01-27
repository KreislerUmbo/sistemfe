<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Badge Examples">
          <b-badge variant="primary">Primary</b-badge> {{ " " }}
          <b-badge variant="secondary">Secondary</b-badge> {{ " " }}
          <b-badge variant="success">Success</b-badge> {{ " " }}
          <b-badge variant="danger">Danger</b-badge> {{ " " }}
          <b-badge variant="warning">Warning</b-badge> {{ " " }}
          <b-badge variant="info">Info</b-badge> {{ " " }}
          <b-badge variant="light" class="text-dark">Light</b-badge> {{ " " }}
          <b-badge variant="dark">Dark</b-badge>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Pill Badges">
          <b-badge pill variant="primary">Primary</b-badge> {{ " " }}
          <b-badge pill variant="secondary">Secondary</b-badge> {{ " " }}
          <b-badge pill variant="success">Success</b-badge> {{ " " }}
          <b-badge pill variant="danger">Danger</b-badge> {{ " " }}
          <b-badge pill variant="warning">Warning</b-badge> {{ " " }}
          <b-badge pill variant="info">Info</b-badge> {{ " " }}
          <b-badge pill variant="light" class="text-dark">Light</b-badge>
          {{ " " }}
          <b-badge pill variant="dark">Dark</b-badge>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Links Badges">
          <a href="#" class="badge bg-primary">Primary</a> {{ " " }}
          <a href="#" class="badge bg-secondary">Secondary</a> {{ " " }}
          <a href="#" class="badge bg-success">Success</a> {{ " " }}
          <a href="#" class="badge bg-danger">Danger</a>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Links Pill Badges">
          <a href="#" class="badge rounded-pill bg-primary">Primary</a>
          {{ " " }}
          <a href="#" class="badge rounded-pill bg-secondary">Secondary</a>
          {{ " " }}
          <a href="#" class="badge rounded-pill bg-success">Success</a>
          {{ " " }}
          <a href="#" class="badge rounded-pill bg-danger">Danger</a>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Badges Subtle">
          <b-badge :variant="null" class="bg-primary-subtle text-primary"
            >Primary</b-badge
          >
          {{ " " }}
          <b-badge :variant="null" class="bg-secondary-subtle text-secondary"
            >Secondary</b-badge
          >
          {{ " " }}
          <b-badge :variant="null" class="bg-success-subtle text-success"
            >Success</b-badge
          >
          {{ " " }}
          <b-badge :variant="null" class="bg-danger-subtle text-danger"
            >Danger</b-badge
          >
          {{ " " }}
          <b-badge :variant="null" class="bg-warning-subtle text-warning"
            >Warning</b-badge
          >
          {{ " " }}
          <b-badge :variant="null" class="bg-info-subtle text-info"
            >Info</b-badge
          >
          {{ " " }}
          <b-badge :variant="null" class="bg-purple-subtle text-purple"
            >Purple</b-badge
          >
          {{ " " }}
          <b-badge :variant="null" class="bg-dark-subtle text-dark"
            >Dark</b-badge
          >
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Badges Pill Subtle">
          <b-badge :variant="null" pill class="bg-primary-subtle text-primary"
            >Primary</b-badge
          >
          {{ " " }}
          <b-badge
            :variant="null"
            pill
            class="bg-secondary-subtle text-secondary"
            >Secondary</b-badge
          >
          {{ " " }}
          <b-badge :variant="null" pill class="bg-success-subtle text-success"
            >Success</b-badge
          >
          {{ " " }}
          <b-badge :variant="null" pill class="bg-danger-subtle text-danger"
            >Danger</b-badge
          >
          {{ " " }}
          <b-badge :variant="null" pill class="bg-warning-subtle text-warning"
            >Warning</b-badge
          >
          {{ " " }}
          <b-badge :variant="null" pill class="bg-info-subtle text-info"
            >Info</b-badge
          >
          {{ " " }}
          <b-badge :variant="null" pill class="bg-pink-subtle text-pink"
            >Pink</b-badge
          >
          {{ " " }}
          <b-badge :variant="null" pill class="bg-dark-subtle text-dark"
            >Dark</b-badge
          >
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Outline Badges">
          <b-badge
            :variant="null"
            class="bg-transparent border border-primary text-primary"
            >Primary</b-badge
          >
          {{ " " }}
          <b-badge
            :variant="null"
            class="bg-transparent border border-secondary text-secondary"
            >Secondary</b-badge
          >
          {{ " " }}
          <b-badge
            :variant="null"
            class="bg-transparent border border-success text-success"
            >Success</b-badge
          >
          {{ " " }}
          <b-badge
            :variant="null"
            class="bg-transparent border border-danger text-danger"
            >Danger</b-badge
          >
          {{ " " }}
          <b-badge
            :variant="null"
            class="bg-transparent border border-warning text-warning"
            >Warning</b-badge
          >
          {{ " " }}
          <b-badge
            :variant="null"
            class="bg-transparent border border-info text-info"
            >Info</b-badge
          >
          {{ " " }}
          <b-badge
            :variant="null"
            class="bg-transparent border border-purple text-purple"
            >Purple</b-badge
          >
          {{ " " }}
          <b-badge
            :variant="null"
            class="bg-transparent border border-dark text-dark"
            >Dark</b-badge
          >
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Outline Pill Badges">
          <b-badge
            :variant="null"
            pill
            class="bg-transparent border border-primary text-primary"
            >Primary</b-badge
          >
          {{ " " }}
          <b-badge
            :variant="null"
            pill
            class="bg-transparent border border-secondary text-secondary"
            >Secondary</b-badge
          >
          {{ " " }}
          <b-badge
            :variant="null"
            pill
            class="bg-transparent border border-success text-success"
            >Success</b-badge
          >
          {{ " " }}
          <b-badge
            :variant="null"
            pill
            class="bg-transparent border border-danger text-danger"
            >Danger</b-badge
          >
          {{ " " }}
          <b-badge
            :variant="null"
            pill
            class="bg-transparent border border-warning text-warning"
            >Warning</b-badge
          >
          {{ " " }}
          <b-badge
            :variant="null"
            pill
            class="bg-transparent border border-info text-info"
            >Info</b-badge
          >
          {{ " " }}
          <b-badge
            :variant="null"
            pill
            class="bg-transparent border border-purple text-purple"
            >Purple</b-badge
          >
          {{ " " }}
          <b-badge
            :variant="null"
            pill
            class="bg-transparent border border-dark text-dark"
            >Dark</b-badge
          >
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Buttons With Badge">
          <b-button type="button" variant="primary" size="sm">
            Notifications <b-badge variant="light" class="text-dark">4</b-badge>
          </b-button>
          {{ " " }}
          <b-button type="button" variant="secondary" size="sm">
            Notifications <b-badge variant="light" class="text-dark">8</b-badge>
          </b-button>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Buttons With Positioned Badge">
          <b-button type="button" variant="primary" class="position-relative">
            Notifications
            <span
              class="position-absolute top-0 start-100 translate-middle bg-danger border border-light rounded-circle"
            >
              <small class="thumb-xs">12</small>
            </span> </b-button
          >{{ " " }}
          <b-button
            type="button"
            variant="secondary"
            class="position-relative ms-2"
          >
            Notifications
            <span
              class="position-absolute top-0 start-100 translate-middle bg-primary px-1 border border-light rounded"
            >
              <small class="fs-10">New</small>
            </span>
          </b-button>
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
