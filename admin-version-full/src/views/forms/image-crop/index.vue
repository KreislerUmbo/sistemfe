<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col lg="9">
        <UIComponentCard title="Image Cropper">
          <div class="img-container mb-0">
            <img id="image" :src="img1" alt="Picture" />
          </div>
        </UIComponentCard>
        <div id="actions">
          <b-card no-body class="docs-buttons">
            <b-card-body>
              <b-button-group class="mb-2 me-1">
                <b-button
                  type="button"
                  variant="light"
                  @click="() => cropper.setDragMode('move')"
                  v-b-tooltip.hover.top="'Move'"
                >
                  <span class="fas fa-expand-arrows-alt"></span>
                </b-button>
                <b-button
                  type="button"
                  variant="light"
                  @click="() => cropper.setDragMode('crop')"
                  v-b-tooltip.hover.top="'Crop'"
                >
                  <span class="fas fa-crop"></span>
                </b-button>
              </b-button-group>

              <b-button-group class="mb-2 me-1">
                <b-button
                  type="button"
                  variant="light"
                  @click="() => cropper.zoom(0.1)"
                  v-b-tooltip.hover.top="'Zoom In'"
                >
                  <span class="fas fa-search-plus"></span>
                </b-button>
                <b-button
                  type="button"
                  variant="light"
                  @click="() => cropper.zoom(-0.1)"
                  v-b-tooltip.hover.top="'Zoom Out'"
                >
                  <span class="fas fa-search-minus"></span>
                </b-button>
              </b-button-group>

              <b-button-group class="mb-2 me-1">
                <b-button
                  type="button"
                  variant="light"
                  @click="() => cropper.move(-10, 0)"
                  v-b-tooltip.hover.top="'Move Left'"
                >
                  <span class="fas fa-arrow-left"></span>
                </b-button>
                <b-button
                  type="button"
                  variant="light"
                  @click="() => cropper.move(10, 0)"
                  v-b-tooltip.hover.top="'Move Right'"
                >
                  <span class="fas fa-arrow-right"></span>
                </b-button>
                <b-button
                  type="button"
                  variant="light"
                  @click="() => cropper.move(0, -10)"
                  v-b-tooltip.hover.top="'Move Up'"
                >
                  <span class="fas fa-arrow-up"></span>
                </b-button>
                <b-button
                  type="button"
                  variant="light"
                  @click="() => cropper.move(0, 10)"
                  v-b-tooltip.hover.top="'Move Down'"
                >
                  <span class="fa fa-arrow-down"></span>
                </b-button>
              </b-button-group>

              <b-button-group class="mb-2 me-1">
                <b-button
                  type="button"
                  variant="light"
                  @click="() => cropper.rotate(-45)"
                  v-b-tooltip.hover.top="'Rotate Left'"
                >
                  <span class="fas fa-reply"></span>
                </b-button>
                <b-button
                  type="button"
                  variant="light"
                  @click="() => cropper.rotate(45)"
                  v-b-tooltip.hover.top="'Rotate Right'"
                >
                  <span class="fas fa-share"></span>
                </b-button>
              </b-button-group>

              <b-button-group class="mb-2 me-1">
                <b-button
                  type="button"
                  variant="light"
                  @click="() => cropper.scaleX(-1)"
                  v-b-tooltip.hover.top="'Flip Horizontal'"
                >
                  <span class="fa fa-arrows-alt-h"></span>
                </b-button>
                <b-button
                  type="button"
                  variant="light"
                  @click="() => cropper.scaleY(-1)"
                  v-b-tooltip.hover.top="'Flip Vertical'"
                >
                  <span class="fas fa-arrows-alt-v"></span>
                </b-button>
              </b-button-group>

              <b-button-group class="mb-2 me-1">
                <b-button
                  type="button"
                  variant="light"
                  @click="() => cropper.crop()"
                  v-b-tooltip.hover.top="'Crop'"
                >
                  <span class="fas fa-check"></span>
                </b-button>
                <b-button
                  type="button"
                  variant="light"
                  @click="() => cropper.clear()"
                  v-b-tooltip.hover.top="'Clear'"
                >
                  <span class="fas fa-times"></span>
                </b-button>
              </b-button-group>

              <b-button-group class="mb-2 me-1">
                <b-button
                  type="button"
                  variant="light"
                  @click="() => cropper.disable()"
                  v-b-tooltip.hover.top="'Disable'"
                >
                  <span class="fas fa-lock"></span>
                </b-button>
                <b-button
                  type="button"
                  variant="light"
                  @click="() => cropper.enable()"
                  v-b-tooltip.hover.top="'Enable'"
                >
                  <span class="fas fa-unlock"></span>
                </b-button>
              </b-button-group>

              <b-button-group class="mb-2 me-1">
                <b-button
                  type="button"
                  variant="light"
                  @click="() => cropper.reset()"
                  v-b-tooltip.hover.top="'Reset'"
                >
                  <span class="fas fa-redo"></span>
                </b-button>
                <b-button
                  type="button"
                  variant="light"
                  class="btn-upload"
                  v-b-tooltip.hover.top="'Import image with Blob URLs'"
                >
                  <span class="fas fa-upload"></span>
                </b-button>
                <b-button
                  type="button"
                  variant="light"
                  @click="() => cropper.destroy()"
                  v-b-tooltip.hover.top="'Destroy'"
                >
                  <span class="fas fa-power-off"></span>
                </b-button>
              </b-button-group>

              <b-button-group class="btn-group-crop mb-2 me-1">
                <b-button
                  type="button"
                  variant="primary"
                  @click="
                    () => cropper.getCroppedCanvas({ width: 160, height: 90 })
                  "
                >
                  160&times;90
                </b-button>
                <b-button
                  type="button"
                  variant="primary"
                  @click="
                    () => cropper.getCroppedCanvas({ width: 320, height: 180 })
                  "
                >
                  320&times;180
                </b-button>
              </b-button-group>
              <b-button
                type="button"
                variant="secondary"
                class="mb-2 me-1"
                @click="() => (eventTeaxtArea = cropper.getData())"
              >
                Get Data
              </b-button>
              <b-button
                type="button"
                variant="secondary"
                class="mb-2 me-1"
                @click="() => cropper.setData(eventTeaxtArea)"
              >
                Set Data
              </b-button>
              <b-button
                type="button"
                variant="secondary"
                class="mb-2 me-1"
                @click="() => (eventTeaxtArea = cropper.getContainerData())"
              >
                Get Container Data
              </b-button>
              <b-button
                type="button"
                variant="secondary"
                class="mb-2 me-1"
                @click="() => (eventTeaxtArea = cropper.getImageData())"
              >
                Get Image Data
              </b-button>
              <b-button
                type="button"
                variant="secondary"
                class="mb-2 me-1"
                @click="() => (eventTeaxtArea = cropper.getCanvasData())"
              >
                Get Canvas Data
              </b-button>
              <b-button
                type="button"
                variant="secondary"
                class="mb-2 me-1"
                @click="() => cropper.setCanvasData(eventTeaxtArea)"
              >
                Set Canvas Data
              </b-button>
              <b-button
                type="button"
                variant="secondary"
                class="mb-2 me-1"
                @click="() => (eventTeaxtArea = cropper.getCropBoxData())"
              >
                Get Crop Box Data
              </b-button>
              <b-button
                type="button"
                variant="secondary"
                class="mb-2 me-1"
                @click="() => cropper.setCropBoxData(eventTeaxtArea)"
              >
                Set Crop Box Data
              </b-button>
              <b-button
                type="button"
                variant="secondary"
                class="mb-2 me-1"
                @click="() => cropper.moveTo(0)"
              >
                Move to [0,0]
              </b-button>
              <b-button
                type="button"
                variant="secondary"
                class="mb-2 me-1"
                @click="() => cropper.zoomTo(1)"
              >
                Zoom to 100%
              </b-button>
              <b-button
                type="button"
                variant="secondary"
                class="mb-2 me-1"
                @click="() => cropper.rotateTo(180)"
              >
                Rotate 180°
              </b-button>
              <b-button
                type="button"
                variant="secondary"
                class="mb-2 me-1"
                @click="() => cropper.scale(-2, -1)"
              >
                Scale (-2, -1)
              </b-button>
              <textarea
                class="form-control"
                id="putData"
                rows="3"
                :value="JSON.stringify(eventTeaxtArea)"
                placeholder="Get data to here or set data with this value"
              ></textarea>
            </b-card-body>
          </b-card>
        </div>
      </b-col>
      <b-col lg="3">
        <b-card no-body>
          <b-card-body>
            <div class="docs-preview clearfix">
              <div class="img-preview preview-lg"></div>
              <div class="img-preview preview-md"></div>
              <div class="img-preview preview-sm"></div>
              <div class="img-preview preview-xs"></div>
            </div>
            <div class="docs-data">
              <b-input-group class="mb-2">
                <b-input-group-text>X</b-input-group-text>
                <b-form-input
                  type="text"
                  :value="imgDetails.eventX"
                  placeholder="x"
                />
                <b-input-group-text>px</b-input-group-text>
              </b-input-group>
              <b-input-group class="mb-2">
                <b-input-group-text>Y</b-input-group-text>
                <b-form-input
                  type="text"
                  id="dataY"
                  :value="imgDetails.eventY"
                  placeholder="y"
                />
                <b-input-group-text>px</b-input-group-text>
              </b-input-group>
              <b-input-group class="mb-2">
                <b-input-group-text>Width</b-input-group-text>
                <b-form-input
                  type="text"
                  id="dataWidth"
                  :value="imgDetails.eventWidth"
                  placeholder="width"
                />
                <b-input-group-text>px</b-input-group-text>
              </b-input-group>
              <b-input-group class="mb-2">
                <b-input-group-text>Height</b-input-group-text>
                <b-form-input
                  type="text"
                  id="dataHeight"
                  :value="imgDetails.eventHeight"
                  placeholder="height"
                />
                <b-input-group-text>px</b-input-group-text>
              </b-input-group>
              <b-input-group class="mb-2">
                <b-input-group-text>Rotate</b-input-group-text>
                <b-form-input
                  type="text"
                  id="dataRotate"
                  :value="imgDetails.eventRotate"
                  placeholder="0"
                />
                <b-input-group-text>deg</b-input-group-text>
              </b-input-group>
              <b-input-group class="mb-2">
                <b-input-group-text>ScaleX</b-input-group-text>
                <b-form-input
                  type="text"
                  id="dataScaleX"
                  :value="imgDetails.eventScaleX"
                  placeholder="scaleX"
                />
              </b-input-group>
              <b-input-group class="mb-0">
                <b-input-group-text>ScaleY</b-input-group-text>
                <b-form-input
                  type="text"
                  id="dataScaleY"
                  :value="imgDetails.eventScaleY"
                  placeholder="scaleY"
                />
              </b-input-group>
            </div>
          </b-card-body>
        </b-card>
        <b-card no-body class="docs-toggles">
          <b-card-body>
            <div class="btn-group d-flex flex-nowrap" data-toggle="buttons">
              <label
                class="btn btn-light mb-1 active"
                @click="() => cropper.setAspectRatio(16 / 9)"
              >
                <input
                  type="radio"
                  class="sr-only"
                  id="aspectRatio0"
                  name="aspectRatio"
                  value="1.7777777777777777"
                />
                16:9
              </label>
              <label
                class="btn btn-light mb-1"
                @click="() => cropper.setAspectRatio(4 / 3)"
              >
                <input
                  type="radio"
                  class="sr-only"
                  id="aspectRatio1"
                  name="aspectRatio"
                  value="1.3333333333333333"
                />
                4:3
              </label>
              <label
                class="btn btn-light mb-1"
                @click="() => cropper.setAspectRatio(1 / 1)"
              >
                <input
                  type="radio"
                  class="sr-only"
                  id="aspectRatio2"
                  name="aspectRatio"
                  value="1"
                />
                1:1
              </label>
              <label
                class="btn btn-light mb-1"
                @click="() => cropper.setAspectRatio(2 / 3)"
              >
                <input
                  type="radio"
                  class="sr-only"
                  id="aspectRatio3"
                  name="aspectRatio"
                  value="0.6666666666666666"
                />
                2:3
              </label>
              <label
                class="btn btn-light mb-1"
                @click="() => cropper.setAspectRatio('NaN')"
              >
                <input
                  type="radio"
                  class="sr-only"
                  id="aspectRatio4"
                  name="aspectRatio"
                  value="NaN"
                />
                Free
              </label>
            </div>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import { ref, onMounted } from "vue";
import Cropper from "cropperjs";
import UIComponentCard from "@/components/UIComponentCard.vue";

import img1 from "@/assets/images/extra/card/img-1.jpg";

const imgDetails = ref({
  eventX: 0,
  eventY: 0,
  eventWidth: 0,
  eventHeight: 0,
  eventRotate: 0,
  eventScaleX: 0,
  eventScaleY: 0,
});

const eventTeaxtArea = ref();

const cropper = ref();

onMounted(() => {
  const image = document.getElementById("image") as any;
  cropper.value = new Cropper(image, {
    aspectRatio: 16 / 9,
    preview: ".img-preview",
    crop(event: any) {
      imgDetails.value.eventX = Math.round(event.detail.x);
      imgDetails.value.eventY = Math.round(event.detail.y);
      imgDetails.value.eventWidth = Math.round(event.detail.width);
      imgDetails.value.eventHeight = Math.round(event.detail.height);
      imgDetails.value.eventRotate = Math.round(event.detail.rotate);
      imgDetails.value.eventScaleX = Math.round(event.detail.scaleX);
      imgDetails.value.eventScaleY = Math.round(event.detail.scaleY);
    },
  });
});

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
