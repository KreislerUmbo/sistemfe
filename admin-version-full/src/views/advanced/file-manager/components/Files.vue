<template>
  <b-col cols="12">
    <div class="clearfix">
      <DropDown>
        <div class="btn-group float-end ms-2">
          <button type="button" class="btn btn-secondary me-0 overflow-hidden">
            Upload File
            <input
              type="file"
              name="file"
              class="overflow-hidden position-absolute top-0 start-0 opacity-0"
            />
          </button>
          <button
            type="button"
            class="btn btn-secondary dropdown-toggle dropdown-toggle-split"
            data-bs-toggle="dropdown"
            aria-expanded="false"
          >
            <i class="las la-angle-down"></i>
          </button>
          <div class="dropdown-menu dropdown-menu-end">
            <a class="dropdown-item" href="#">
              <i class="las la-file-upload fs-16 me-1 align-text-bottom"></i>
              Upload File
            </a>
            <a class="dropdown-item" href="#">
              <i
                class="las la-cloud-upload-alt fs-16 me-1 align-text-bottom"
              ></i
              >Upload Folder
            </a>
          </div>
        </div>
      </DropDown>
      <ul class="nav nav-tabs my-4" role="tablist">
        <li class="nav-item">
          <a
            class="nav-link fw-semibold py-2"
            :class="tabShow === 'documents' && 'active'"
            @click="tabShow = 'documents'"
          >
            <i class="fa-regular fa-folder-open me-1"></i> Documents
            <span class="badge rounded text-blue bg-blue-subtle ms-1">32</span>
          </a>
        </li>
        <li class="nav-item">
          <a
            class="nav-link fw-semibold py-2"
            :class="tabShow === 'images' && 'active'"
            @click="tabShow = 'images'"
          >
            <i class="fa-regular fa-image me-1"></i> Images
            <span class="badge rounded text-blue bg-blue-subtle ms-1">85</span>
          </a>
        </li>
        <li class="nav-item">
          <a
            class="nav-link fw-semibold py-2"
            :class="tabShow === 'audio' && 'active'"
            @click="tabShow = 'audio'"
          >
            <i class="fa-solid fa-headphones me-1"></i> Audio
            <span class="badge rounded text-blue bg-blue-subtle ms-1">21</span>
          </a>
        </li>
      </ul>
    </div>

    <b-card no-body>
      <b-card-header>
        <b-row class="align-items-center">
          <div class="col">
            <b-card-title>Files</b-card-title>
          </div>
          <div class="col-auto">
            <div class="dropdown">
              <a href="#" class="text-body text-decoration-underline">
                View All
              </a>
            </div>
          </div>
        </b-row>
      </b-card-header>
      <b-card-body class="pt-0">
        <div class="tab-content">
          <div
            class="tab-pane"
            :class="tabShow === 'documents' && 'active'"
            id="documents"
          >
            <DocumentsTab />
          </div>
          <div
            class="tab-pane"
            :class="tabShow === 'images' && 'active'"
            id="images"
          >
            <ImagesTab />
          </div>
          <div
            class="tab-pane"
            :class="tabShow === 'audio' && 'active'"
            id="audio"
          >
            <AudioTab />
          </div>
        </div>
      </b-card-body>
    </b-card>
  </b-col>
</template>
<script setup lang="ts">
import { ref } from "vue";
import DropDown from "@/components/DropDown.vue";

import DocumentsTab from "@/views/advanced/file-manager/components/DocumentsTab.vue";
import ImagesTab from "@/views/advanced/file-manager/components/ImagesTab.vue";
import AudioTab from "@/views/advanced/file-manager/components/AudioTab.vue";

const tabShow = ref("documents");
</script>
