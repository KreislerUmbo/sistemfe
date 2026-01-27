<template>
  <div class="chat-box-right">
    <div
      class="p-3 d-flex justify-content-between align-items-center card-bg rounded"
    >
      <a href="#" class="d-flex align-self-center">
        <div class="flex-shrink-0">
          <img :src="avatar1" alt="user" class="rounded-circle thumb-lg" />
        </div>
        <div class="flex-grow-1 ms-2 align-self-center">
          <div>
            <h6 class="my-0 fw-medium text-dark fs-14">Mary Schneider</h6>
            <p class="text-muted mb-0">Last seen: 2 hours ago</p>
          </div>
        </div>
      </a>
      <div class="d-none d-sm-inline-block align-self-center">
        <a
          href="javascript:void(0)"
          class="fs-22 me-2 text-muted"
          id="tooltip1"
        >
          <i class="iconoir-phone"></i> </a
        >{{ " " }}
        <b-tooltip target="tooltip1" class="tooltip-primary">Call</b-tooltip>

        <a
          href="javascript:void(0)"
          class="fs-22 me-2 text-muted"
          id="tooltip2"
        >
          <i class="iconoir-video-camera"></i> </a
        >{{ " " }}
        <b-tooltip target="tooltip2" class="tooltip-primary"
          >Video call</b-tooltip
        >

        <a
          href="javascript:void(0)"
          class="fs-22 me-2 text-muted"
          id="tooltip3"
        >
          <i class="iconoir-trash"></i> </a
        >{{ " " }}
        <b-tooltip target="tooltip3" class="tooltip-primary">Delete</b-tooltip>
        <a href="javascript:void(0)" class="fs-22 text-muted">
          <i class="iconoir-menu-scale"></i> </a
        >{{ " " }}
      </div>
    </div>
    <simplebar class="chat-body" data-simplebar>
      <div class="chat-detail">
        <template v-for="(item, idx) in chatMessages" :key="idx">
          <div :class="item.isSender ? 'd-flex flex-row-reverse' : 'd-flex'">
            <img :src="item.img" alt="user" class="rounded-circle thumb-md" />
            <div
              class="chat-box w-100"
              :class="item.isSender ? 'me-1 reverse' : 'ms-1'"
            >
              <div class="user-chat">
                <p class="" v-for="(msg, idx) in item.messages" :key="idx">
                  {{ msg }}
                </p>
              </div>
              <div class="chat-time">{{ item.time }}</div>
            </div>
          </div>
        </template>
      </div>
    </simplebar>
    <div class="chat-footer">
      <form @submit.prevent="handleYupSubmit">
        <b-row>
          <b-col cols="10" md="8">
            <input
              type="text"
              class="form-control"
              placeholder="Type something here..."
              v-model="messageState.message"
            />
          </b-col>
          <b-col cols="2" md="4" class="text-end">
            <div class="chat-features d-flex justify-content-end">
              <div class="d-none d-sm-inline-block">
                <a href=""><i class="iconoir-camera"></i></a>{{ " " }}
                <a href=""><i class="iconoir-attachment"></i></a>{{ " " }}
                <a href=""><i class="iconoir-microphone"></i></a>{{ " " }}
              </div>
              {{ " " }}
              <button
                type="submit"
                class="btn btn-link text-primary p-0 m-0 ms-2"
              >
                <i class="iconoir-send-solid fs-24" />
              </button>
            </div>
          </b-col>
          <span v-if="error.length > 0" class="text-danger mt-2">{{
            error
          }}</span>
        </b-row>
      </form>
    </div>
  </div>
</template>
<script setup lang="ts">
import { ref, watch, reactive } from "vue";
import simplebar from "simplebar-vue";
import { messages } from "@/views/apps/chat/components/data";
import avatar1 from "@/assets/images/users/avatar-1.jpg";
import avatar3 from "@/assets/images/users/avatar-3.jpg";

import { object, string } from "yup";
const chatMessages = ref(messages);
const error = ref("");

const messageSchema = object({
  message: string().required("Message is Required"),
});

const messageState = reactive({
  message: "",
});

const handleYupSubmit = async (event: Event) => {
  await messageSchema
    .validate(messageState)
    .then((res) => {
      error.value = "";
      chatMessages.value.push({
        id: Math.floor(Math.random() * 100 + 20) - 20,
        messages: [res.message],
        time: "8:20 pm",
        isSender: true,
        direction: "right",
        img: avatar3,
      });
      messageState.message = "";
    })
    .catch((e) => {
      return (error.value = e.message);
    });
};

watch(
  chatMessages.value,
  () => {
    setTimeout(() => {
      chatMessages.value.push({
        id: Math.floor(Math.random() * 100 + 20) - 20,
        messages: ["Server is not connected 😔"],
        time: "8:20 pm",
        isSender: false,
        direction: "left",
        img: avatar1,
      });
    }, 1000);
  },
  { once: true },
);
</script>
