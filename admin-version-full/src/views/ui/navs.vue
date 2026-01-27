<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Base Nav">
          <b-nav>
            <b-nav-item active>Active</b-nav-item>
            <b-nav-item>Link</b-nav-item>
            <b-nav-item>Link</b-nav-item>
            <b-nav-item disabled>Disabled</b-nav-item>
          </b-nav>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Nav Tabs">
          <b-nav tabs>
            <b-nav-item active>Active</b-nav-item>
            <b-nav-item>Link</b-nav-item>
            <b-nav-item>Link</b-nav-item>
            <b-nav-item disabled>Disabled</b-nav-item>
          </b-nav>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Nav Justified">
          <b-nav pills justified>
            <b-nav-item active>Active</b-nav-item>
            <b-nav-item>Longer nav link</b-nav-item>
            <b-nav-item>Link</b-nav-item>
            <b-nav-item disabled>Disabled</b-nav-item>
          </b-nav>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Nav Pills">
          <b-nav pills>
            <b-nav-item active>Active</b-nav-item>
            <b-nav-item>Link</b-nav-item>
            <b-nav-item>Link</b-nav-item>
            <b-nav-item disabled>Disabled</b-nav-item>
          </b-nav>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Nav Tabs With Dropdowns">
          <b-nav tabs>
            <b-nav-item active>Active</b-nav-item>
            <b-nav-item-dropdown text="Dropdown" toggle-class="nav-link">
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-nav-item-dropdown>
            <b-nav-item>Link</b-nav-item>
            <b-nav-item disabled>Disabled</b-nav-item>
          </b-nav>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Nav Pills With Dropdowns">
          <b-nav pills>
            <b-nav-item active>Active</b-nav-item>
            <b-nav-item-dropdown text="Dropdown" toggle-class="nav-link">
              <b-dropdown-item>Action</b-dropdown-item>
              <b-dropdown-item>Another action</b-dropdown-item>
              <b-dropdown-item>Something else here</b-dropdown-item>
              <b-dropdown-divider />
              <b-dropdown-item>Separated link</b-dropdown-item>
            </b-nav-item-dropdown>
            <b-nav-item>Link</b-nav-item>
            <b-nav-item disabled>Disabled</b-nav-item>
          </b-nav>
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
