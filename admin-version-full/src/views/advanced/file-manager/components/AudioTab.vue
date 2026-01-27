<template>
  <b-table-simple responsive class="mb-0">
    <b-thead class="table-light">
      <b-tr>
        <b-th class="border-top-0">Name</b-th>
        <b-th class="border-top-0 text-end">Last Modified</b-th>
        <b-th class="border-top-0 text-end">Size</b-th>
        <b-th class="border-top-0 text-end">Action</b-th>
      </b-tr>
    </b-thead>
    <b-tbody>
      <b-tr v-for="(item, idx) in audioList" :key="idx">
        <b-td>
          <div
            class="d-inline-flex justify-content-center align-items-center thumb-md bg-secondary-subtle rounded mx-auto me-1"
          >
            <i
              class="fa-solid fa-microphone fs-18 align-self-center mb-0 text-secondary"
            ></i>
          </div>
          {{ " " }}
          <a href="#" class="text-body">{{ item.file_name }}</a>
        </b-td>
        <b-td class="text-end">{{ item.date }}</b-td>
        <b-td class="text-end"> {{ item.size }}</b-td>
        <b-td class="text-end">
          <a href="#"><i class="las la-download text-secondary fs-18"></i></a
          >{{ " " }}
          <a href="#"><i class="las la-pen text-secondary fs-18"></i></a
          >{{ " " }}
          <a href="#"><i class="las la-trash-alt text-secondary fs-18"></i></a>
        </b-td>
      </b-tr>
    </b-tbody>
  </b-table-simple>
</template>
<script setup lang="ts">
import { audioList } from "@/views/advanced/file-manager/components/data";
</script>
