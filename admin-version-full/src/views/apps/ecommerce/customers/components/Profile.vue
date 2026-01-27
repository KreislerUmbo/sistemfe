<template>
  <b-col md="12" lg="5">
    <b-card no-body>
      <b-card-body>
        <b-row class="align-items-center">
          <div class="col">
            <div class="d-flex align-items-center">
              <div class="position-relative">
                <img :src="avatar8" alt="" class="rounded-circle img-fluid" />
                <div
                  class="position-absolute top-50 start-100 translate-middle"
                >
                  <img
                    :src="bahaFlag"
                    alt=""
                    class="rounded-circle thumb-sm border border-3 border-white"
                  />
                </div>
              </div>
              <div class="flex-grow-1 text-truncate ms-3">
                <h5 class="m-0 fs-3 fw-bold">Karen Savage</h5>
                <p class="text-muted mb-0">@karen</p>
                <p class="text-muted mb-0">Id: #{{ id }}</p>
              </div>
            </div>
          </div>
          <div class="col-auto text-end">
            <b-button
              type="button"
              size="sm"
              variant="outline-primary"
              class="px-2 d-inline-flex align-items-center"
            >
              <i class="iconoir-chat-bubble fs-14 me-1"></i>Message
            </b-button>
          </div>
        </b-row>
        <div class="mt-3">
          <div class="text-body mb-2 d-flex align-items-center">
            <i class="iconoir-language fs-20 me-1 text-muted"></i
            ><span class="text-body fw-semibold">Language :</span> English /
            French / Spanish
          </div>
          <div class="text-muted mb-2 d-flex align-items-center">
            <i class="iconoir-mail-out fs-20 me-1"></i
            ><span class="text-body fw-semibold">Email :</span
            ><a href="#" class="text-primary text-decoration-underline"
              >example@example.com</a
            >
          </div>
          <div class="text-body mb-3 d-flex align-items-center">
            <i class="iconoir-phone fs-20 me-1 text-muted"></i
            ><span class="text-body fw-semibold">Phone :</span> +1 123 456 789
          </div>
          <ul class="mb-0 list-unstyled">
            <li class="list-inline-item">
              <a
                href="#"
                class="d-flex justify-content-center align-items-center thumb-md rounded-circle mx-auto social twitter"
              >
                <i class="icofont-twitter fs-18 align-self-center mb-0"></i>
              </a>
            </li>
            {{
              " "
            }}
            <li class="list-inline-item">
              <a
                href="#"
                class="d-flex justify-content-center align-items-center thumb-md rounded-circle mx-auto social instagram"
              >
                <i class="icofont-instagram fs-18 align-self-center mb-0"></i>
              </a>
            </li>
            {{
              " "
            }}
            <li class="list-inline-item">
              <a
                href="#"
                class="d-flex justify-content-center align-items-center thumb-md rounded-circle mx-auto social facebook"
              >
                <i class="icofont-facebook fs-18 align-self-center mb-0"></i>
              </a>
            </li>
          </ul>
        </div>
      </b-card-body>
    </b-card>
  </b-col>
</template>
<script setup lang="ts">
import avatar8 from "@/assets/images/users/avatar-8.jpg";
import bahaFlag from "@/assets/images/flags/baha_flag.jpg";

type IdPropsType = {
  id: number | string | string[];
};

defineProps<IdPropsType>();
</script>
