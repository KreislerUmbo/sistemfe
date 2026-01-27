<template>
  <div class="chat-box-left">
    <ul class="nav nav-tabs nav-justified" id="myTab" role="tablist">
      <li class="nav-item" role="presentation">
        <a
          class="nav-link py-2"
          :class="show && 'active'"
          id="messages_chat_tab"
          @click="show = !show"
          >Messages</a
        >
      </li>
      <li class="nav-item" role="presentation">
        <a
          class="nav-link py-2"
          :class="!show && 'active'"
          id="active_chat_tab"
          @click="show = !show"
          >Active</a
        >
      </li>
    </ul>
    <div class="chat-search p-3">
      <div class="p-1 bg-light rounded rounded-pill">
        <div class="input-group">
          <div class="input-group-prepend">
            <button
              id="button-addon2"
              type="submit"
              class="btn btn-link text-secondary"
            >
              <i class="fa fa-search"></i>
            </button>
          </div>
          <input
            type="search"
            placeholder="Searching.."
            aria-describedby="button-addon2"
            class="form-control border-0 bg-light"
          />
        </div>
      </div>
    </div>

    <simplebar class="chat-body-left px-3" data-simplebar>
      <div class="tab-content" id="pills-tabContent">
        <div
          class="tab-pane fade"
          :class="show && 'show active'"
          id="messages_chat"
        >
          <b-row>
            <b-col>
              <div
                class="p-2 border-dashed border-theme-color rounded mb-2"
                v-for="(item, idx) in contactsList"
                :key="idx"
              >
                <a href="#">
                  <div class="d-flex align-items-start">
                    <div class="position-relative">
                      <img
                        :src="item.image"
                        alt=""
                        class="thumb-lg rounded-circle"
                      />
                      <span class="position-absolute bottom-0 end-0">
                        <i
                          class="fa-solid fa-circle text-success fs-10 border-2 border-theme-color"
                        ></i>
                      </span>
                    </div>
                    <div
                      class="flex-grow-1 ms-2 text-truncate align-self-center"
                    >
                      <h6 class="my-0 fw-medium text-dark fs-14">
                        {{ item.name }}
                        <small class="float-end text-muted fs-11">{{
                          item.time
                        }}</small>
                      </h6>
                      <p class="text-muted mb-0">
                        <span class="text-primary">{{ item.message }}</span>
                        <b-badge
                          variant="success"
                          class="float-end rounded text-white"
                          v-if="item.unreadMessages"
                        >
                          {{ item.unreadMessages }}
                        </b-badge>
                      </p>
                    </div>
                  </div>
                </a>
              </div>
            </b-col>
          </b-row>
        </div>

        <div
          class="tab-pane fade"
          :class="!show && 'show active'"
          id="active_chat"
        >
          <div
            class="p-2 border-dashed border-theme-color rounded mb-2"
            v-for="(item, idx) in contactsList.slice(-3)"
            :key="idx"
          >
            <a href="#">
              <div class="d-flex align-items-start">
                <div class="position-relative">
                  <img
                    :src="item.image"
                    alt=""
                    class="thumb-lg rounded-circle"
                  />
                  <span class="position-absolute bottom-0 end-0"
                    ><i
                      class="fa-solid fa-circle text-success fs-10 border-2 border-theme-color"
                    ></i
                  ></span>
                </div>
                <div class="flex-grow-1 ms-2 text-truncate align-self-center">
                  <h6 class="my-0 fw-medium text-dark fs-14">
                    {{ item.name }}
                    <small class="float-end text-muted fs-11">{{
                      item.time
                    }}</small>
                  </h6>
                  <p class="text-muted mb-0">{{ item.message }}</p>
                </div>
              </div>
            </a>
          </div>
        </div>
      </div>
    </simplebar>
  </div>
</template>
<script setup lang="ts">
import { ref } from "vue";
import simplebar from "simplebar-vue";
import { contactsList } from "@/views/apps/chat/components/data";
const show = ref<boolean>(true);
</script>
