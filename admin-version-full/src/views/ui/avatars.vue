<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Avatars Circle">
          <div class="d-flex align-items-center">
            <a class="me-2 d-inline-block" href="#">
              <img :src="avatar9" alt="user" class="thumb-xxl rounded-circle" />
            </a>
            <a class="me-2 d-inline-block" href="#">
              <img :src="avatar9" alt="user" class="thumb-xl rounded-circle" />
            </a>
            <a class="me-2 d-inline-block" href="#">
              <img :src="avatar9" alt="user" class="thumb-lg rounded-circle" />
            </a>
            <a class="me-2 d-inline-block" href="#">
              <img :src="avatar9" alt="user" class="thumb-md rounded-circle" />
            </a>
            <a class="me-2 d-inline-block" href="#">
              <img :src="avatar9" alt="user" class="thumb-sm rounded-circle" />
            </a>
            <a class="me-2 d-inline-block" href="#">
              <img :src="avatar9" alt="user" class="thumb-xs rounded-circle" />
            </a>
          </div>
        </UIComponentCard>
      </b-col>

      <b-col md="6" lg="6">
        <UIComponentCard title="Avatars Square">
          <div class="d-flex align-items-center">
            <a class="me-2 d-inline-block" href="#">
              <img :src="avatar9" alt="user" class="thumb-xxl rounded" />
            </a>
            <a class="me-2 d-inline-block" href="#">
              <img :src="avatar9" alt="user" class="thumb-xl rounded" />
            </a>
            <a class="me-2 d-inline-block" href="#">
              <img :src="avatar9" alt="user" class="thumb-lg rounded" />
            </a>
            <a class="me-2 d-inline-block" href="#">
              <img :src="avatar9" alt="user" class="thumb-md rounded" />
            </a>
            <a class="me-2 d-inline-block" href="#">
              <img :src="avatar9" alt="user" class="thumb-sm rounded" />
            </a>
            <a class="me-2 d-inline-block" href="#">
              <img :src="avatar9" alt="user" class="thumb-xs rounded" />
            </a>
          </div>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Text Avatars Circle">
          <div class="d-flex align-items-center">
            <span
              class="thumb-xxl justify-content-center d-flex align-items-center bg-success-subtle text-success rounded-circle me-2"
              >MT</span
            >
            <span
              class="thumb-xl justify-content-center d-flex align-items-center bg-pink-subtle text-pink rounded-circle me-2"
              >MT</span
            >
            <span
              class="thumb-lg justify-content-center d-flex align-items-center bg-purple-subtle text-purple rounded-circle me-2"
              >MT</span
            >
            <span
              class="thumb-md justify-content-center d-flex align-items-center bg-warning-subtle text-warning rounded-circle me-2"
              >MT</span
            >
            <span
              class="thumb-sm justify-content-center d-flex align-items-center bg-info-subtle text-info rounded-circle me-2"
              >MT</span
            >
            <span
              class="thumb-xs justify-content-center d-flex align-items-center bg-dark-subtle text-dark rounded-circle me-2"
              >MT</span
            >
          </div>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Text Avatars Square">
          <div class="d-flex align-items-center">
            <span
              class="thumb-xxl justify-content-center d-flex align-items-center bg-success-subtle text-success rounded me-2"
              >MT</span
            >
            <span
              class="thumb-xl justify-content-center d-flex align-items-center bg-pink-subtle text-pink rounded me-2"
              >MT</span
            >
            <span
              class="thumb-lg justify-content-center d-flex align-items-center bg-purple-subtle text-purple rounded me-2"
              >MT</span
            >
            <span
              class="thumb-md justify-content-center d-flex align-items-center bg-warning-subtle text-warning rounded me-2"
              >MT</span
            >
            <span
              class="thumb-sm justify-content-center d-flex align-items-center bg-info-subtle text-info rounded me-2"
              >MT</span
            >
            <span
              class="thumb-xs justify-content-center d-flex align-items-center bg-dark-subtle text-dark rounded me-2"
              >MT</span
            >
          </div>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Icon Avatars Circle">
          <div class="d-flex align-items-center">
            <span
              class="thumb-xxl justify-content-center d-flex align-items-center bg-success text-white rounded-circle me-2"
            >
              <i class="iconoir-profile-circle"></i>
            </span>
            <span
              class="thumb-xl justify-content-center d-flex align-items-center bg-pink text-white rounded-circle me-2"
            >
              <i class="iconoir-profile-circle"></i>
            </span>
            <span
              class="thumb-lg justify-content-center d-flex align-items-center bg-purple text-white rounded-circle me-2"
            >
              <i class="iconoir-profile-circle"></i>
            </span>
            <span
              class="thumb-md justify-content-center d-flex align-items-center bg-warning text-white rounded-circle me-2"
            >
              <i class="iconoir-profile-circle"></i>
            </span>
            <span
              class="thumb-sm justify-content-center d-flex align-items-center bg-info text-white rounded-circle me-2"
            >
              <i class="iconoir-profile-circle"></i>
            </span>
            <span
              class="thumb-xs justify-content-center d-flex align-items-center bg-dark text-white rounded-circle me-2"
            >
              <i class="iconoir-profile-circle"></i>
            </span>
          </div>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Icon Avatars Square">
          <div class="d-flex align-items-center">
            <span
              class="thumb-xxl justify-content-center d-flex align-items-center bg-success text-white rounded me-2"
            >
              <i class="iconoir-people-tag"></i>
            </span>
            <span
              class="thumb-xl justify-content-center d-flex align-items-center bg-pink text-white rounded me-2"
            >
              <i class="iconoir-people-tag"></i>
            </span>
            <span
              class="thumb-lg justify-content-center d-flex align-items-center bg-purple text-white rounded me-2"
            >
              <i class="iconoir-people-tag"></i>
            </span>
            <span
              class="thumb-md justify-content-center d-flex align-items-center bg-warning text-white rounded me-2"
            >
              <i class="iconoir-people-tag"></i>
            </span>
            <span
              class="thumb-sm justify-content-center d-flex align-items-center bg-info text-white rounded me-2"
            >
              <i class="iconoir-people-tag"></i>
            </span>
            <span
              class="thumb-xs justify-content-center d-flex align-items-center bg-dark text-white rounded me-2"
            >
              <i class="iconoir-people-tag"></i>
            </span>
          </div>
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
import avatar9 from "@/assets/images/users/avatar-9.jpg";
</script>
