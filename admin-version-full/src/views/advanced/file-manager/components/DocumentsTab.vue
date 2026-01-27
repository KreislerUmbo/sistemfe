<template>
  <b-table-simple responsive class="mb-0 browser_users">
    <b-thead class="table-light">
      <b-tr>
        <b-th class="border-top-0">Name</b-th>
        <b-th class="border-top-0 text-end">Last Modified</b-th>
        <b-th class="border-top-0 text-end">Size</b-th>
        <b-th class="border-top-0 text-end">Members</b-th>
        <b-th class="border-top-0 text-end">Action</b-th>
      </b-tr>
    </b-thead>
    <b-tbody>
      <b-tr v-for="(item, idx) in documentList" :key="idx">
        <b-td>
          <div
            class="d-inline-flex justify-content-center align-items-center thumb-md bg-blue-subtle rounded mx-auto me-1"
          >
            <i
              class="fa-solid fa-file-pdf fs-18 align-self-center mb-0 text-blue"
            ></i>
          </div>
          {{ " " }}
          <a href="#" class="text-body">{{ item.file_name }}</a>
        </b-td>
        <b-td class="text-end">{{ item.date }}</b-td>
        <b-td class="text-end"> {{ item.size }}</b-td>
        <b-td class="text-end">
          <div class="img-group d-flex justify-content-end">
            <template v-for="(img, idx) in item.avatars" :key="idx">
              <a
                class="user-avatar position-relative d-inline-block"
                :class="idx && 'ms-n2'"
                href="#"
              >
                <img
                  :src="img"
                  alt="avatar"
                  class="thumb-md shadow-sm rounded-circle"
                />
              </a>
            </template>
          </div>
        </b-td>
        <b-td class="text-end">
          <a href="#"><i class="las la-download text-secondary fs-18"></i></a>
          {{ " " }}
          <a href="#"><i class="las la-pen text-secondary fs-18"></i></a>
          {{ " " }}
          <a href="#"><i class="las la-trash-alt text-secondary fs-18"></i></a>
        </b-td>
      </b-tr>
    </b-tbody>
  </b-table-simple>
</template>
<script setup lang="ts">
import { documentList } from "@/views/advanced/file-manager/components/data";
</script>
