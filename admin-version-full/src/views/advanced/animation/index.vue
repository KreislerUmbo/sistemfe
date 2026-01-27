<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="12">
        <UIComponentCard title="Masic Animation">
          <div class="text-center">
            <div class="position-relative d-inline-block mx-auto">
              <div id="animate-me">
                <div class="shadow-sm p-4 d-inline-block rounded-circle">
                  <img :src="logoSm" alt="" class="center-block thumb-xl" />
                </div>
              </div>
            </div>
          </div>
          <div class="mt-5">
            <b-row>
              <b-col
                lg="6"
                id="content"
                class="mx-auto text-center button-list container-magic-bottom container text-center"
              >
                <simplebar style="height: 450px" data-simplebar>
                  <h5 class="my-3 fs-15">Magic Effects</h5>
                  <div class="d-flex justify-content-center gap-1">
                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="mb-1"
                      data-test="vanishIn"
                      @click="animationFun('vanishIn')"
                      >Masic</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="twisterInDown"
                      @click="animationFun('twisterInDown')"
                      >TwisterInDown</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="twisterInUp"
                      @click="animationFun('twisterInUp')"
                      >TwisterInUp</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="swap"
                      @click="animationFun('swap')"
                      >Swap</b-button
                    >
                  </div>
                  <h5 class="my-3 fs-15">Bling</h5>
                  <div class="d-flex justify-content-center gap-1">
                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="puffIn"
                      @click="animationFun('puffIn')"
                      >PuffIn</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="puffOut"
                      @click="animationFun('puffOut')"
                      >PuffOut</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="vanishIn"
                      @click="animationFun('vanishIn')"
                      >VanishIn</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="vanishOut"
                      @click="animationFun('vanishOut')"
                      >VanishOut</b-button
                    >
                  </div>
                  <h5 class="my-3 fs-15">Static Effects</h5>
                  <div class="d-flex justify-content-center flex-wrap gap-1">
                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="openDownLeft"
                      @click="animationFun('openDownLeft')"
                      >OpenDownLeft</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="openDownRight"
                      @click="animationFun('openDownRight')"
                      >OpenDownRight</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="openUpLeft"
                      @click="animationFun('openUpLeft')"
                      >OpenUpLeft</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="openUpRight"
                      @click="animationFun('openUpRight')"
                      >OpenUpRight</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="openDownLeftReturn"
                      @click="animationFun('openDownLeftReturn')"
                      >OpenDownLeftReturn</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="openDownRightReturn"
                      @click="animationFun('openDownRightReturn')"
                      >OpenDownRightReturn</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="openUpLeftReturn"
                      @click="animationFun('openUpLeftReturn')"
                      >OpenUpLeftReturn</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="openUpRightReturn"
                      @click="animationFun('openUpRightReturn')"
                      >OpenUpRightReturn</b-button
                    >
                  </div>
                  <h5 class="my-3 fs-15">Static Effects Out</h5>
                  <div class="d-flex justify-content-center gap-1">
                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="openDownLeftOut"
                      @click="animationFun('openDownLeftOut')"
                      >OpenDownLeftOut</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="openDownRightOut"
                      @click="animationFun('openDownRightOut')"
                      >OpenDownRightOut</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="openUpLeftOut"
                      @click="animationFun('openUpLeftOut')"
                      >OpenUpLeftOut</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="openUpRightOut"
                      @click="animationFun('openUpRightOut')"
                      >OpenUpRightOut</b-button
                    >
                  </div>
                  <h5 class="my-3 fs-15">Perspective</h5>
                  <div class="d-flex justify-content-center flex-wrap gap-1">
                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="perspectiveDown"
                      @click="animationFun('perspectiveDown')"
                      >PerspectiveDown</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="perspectiveUp"
                      @click="animationFun('perspectiveUp')"
                      >PerspectiveUp</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="perspectiveLeft"
                      @click="animationFun('perspectiveLeft')"
                      >PerspectiveLeft</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="perspectiveRight"
                      @click="animationFun('perspectiveRight')"
                      >PerspectiveRight</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="perspectiveDownReturn"
                      @click="animationFun('perspectiveDownReturn')"
                      >PerspectiveDownReturn</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="perspectiveUpReturn"
                      @click="animationFun('perspectiveUpReturn')"
                      >PerspectiveUpReturn</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="perspectiveLeftReturn"
                      @click="animationFun('perspectiveLeftReturn')"
                      >PerspectiveLeftReturn</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="perspectiveRightReturn"
                      @click="animationFun('perspectiveRightReturn')"
                      >PerspectiveRightReturn</b-button
                    >
                  </div>
                  <h5 class="my-3 fs-15">Rotate</h5>
                  <div class="d-flex justify-content-center gap-1">
                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="rotateDown"
                      @click="animationFun('rotateDown')"
                      >RotateDown</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="rotateUp"
                      @click="animationFun('rotateUp')"
                      >RotateUp</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="rotateLeft"
                      @click="animationFun('rotateLeft')"
                      >RotateLeft</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="rotateRight"
                      @click="animationFun('rotateRight')"
                      >RotateRight</b-button
                    >
                  </div>
                  <h5 class="my-3 fs-15">Slide</h5>
                  <div class="d-flex justify-content-center flex-wrap gap-1">
                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="slideDown"
                      @click="animationFun('slideDown')"
                      >SlideDown</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="slideUp"
                      @click="animationFun('slideUp')"
                      >SlideUp</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="slideLeft"
                      @click="animationFun('slideLeft')"
                      >SlideLeft</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="slideRight"
                      @click="animationFun('slideRight')"
                      >SlideRight</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="slideDownReturn"
                      @click="animationFun('slideDownReturn')"
                      >SlideDownReturn</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="slideUpReturn"
                      @click="animationFun('slideUpReturn')"
                      >SlideUpReturn</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="slideLeftReturn"
                      @click="animationFun('slideLeftReturn')"
                      >SlideLeftReturn</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="slideRightReturn"
                      @click="animationFun('slideRightReturn')"
                      >SlideRightReturn</b-button
                    >
                  </div>
                  <h5 class="my-3 fs-15">Math</h5>
                  <div class="d-flex justify-content-center gap-1">
                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="swashOut"
                      @click="animationFun('swashOut')"
                      >SwashOut</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="swashIn"
                      @click="animationFun('swashIn')"
                      >SwashIn</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="foolishIn"
                      @click="animationFun('foolishIn')"
                      >FoolishIn</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="holeOut"
                      @click="animationFun('holeOut')"
                      >HoleOut</b-button
                    >
                  </div>
                  <h5 class="my-3 fs-15">Tin</h5>
                  <div class="d-flex justify-content-center flex-wrap gap-1">
                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="tinRightOut"
                      @click="animationFun('tinRightOut')"
                      >TinRightOut</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="tinLeftOut"
                      @click="animationFun('tinLeftOut')"
                      >TinLeftOut</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="tinUpOut"
                      @click="animationFun('tinUpOut')"
                      >TinUpOut</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="tinDownOut"
                      @click="animationFun('tinDownOut')"
                      >TinDownOut</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="tinRightIn"
                      @click="animationFun('tinRightIn')"
                      >TinRightIn</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="tinLeftIn"
                      @click="animationFun('tinLeftIn')"
                      >TinLeftIn</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="tinUpIn"
                      @click="animationFun('tinUpIn')"
                      >TinUpIn</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="tinDownIn"
                      @click="animationFun('tinDownIn')"
                      >TinDownIn</b-button
                    >
                  </div>
                  <h5 class="my-3 fs-15">Bomb</h5>
                  <div class="d-flex justify-content-center gap-1">
                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="bombRightOut"
                      @click="animationFun('bombRightOut')"
                      >BombRightOut</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="bombLeftOut"
                      @click="animationFun('bombLeftOut')"
                      >BombLeftOut</b-button
                    >
                  </div>
                  <h5 class="my-3 fs-15">Boing</h5>
                  <div class="d-flex justify-content-center gap-1">
                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="boingInUp"
                      @click="animationFun('boingInUp')"
                      >BoingInUp</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="boingOutDown"
                      @click="animationFun('boingOutDown')"
                      >BoingOutDown</b-button
                    >
                  </div>
                  <h5 class="my-3 fs-15">On The Space</h5>
                  <div class="d-flex justify-content-center flex-wrap gap-1">
                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="spaceOutUp"
                      @click="animationFun('spaceOutUp')"
                      >SpaceOutUp</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="spaceOutRight"
                      @click="animationFun('spaceOutRight')"
                      >SpaceOutRight</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="spaceOutDown"
                      @click="animationFun('spaceOutDown')"
                      >SpaceOutDown</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="spaceOutLeft"
                      @click="animationFun('spaceOutLeft')"
                      >SpaceOutLeft</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="spaceInUp"
                      @click="animationFun('spaceInUp')"
                      >SpaceInUp</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="spaceInRight"
                      @click="animationFun('spaceInRight')"
                      >SpaceInRight</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="spaceInDown"
                      @click="animationFun('spaceInDown')"
                      >SpaceInDown</b-button
                    >

                    <b-button
                      type="button"
                      variant="outline-secondary"
                      size="sm"
                      class="btn-animation mb-1"
                      data-test="spaceInLeft"
                      @click="animationFun('spaceInLeft')"
                      >SpaceInLeft</b-button
                    >
                  </div>
                </simplebar>
              </b-col>
            </b-row>
          </div>
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import simplebar from "simplebar-vue";

import logoSm from "@/assets/images/logo-sm.png";

const animationFun = (ele: string) => {
  const selector = document.getElementById("animate-me") as HTMLElement;
  selector.classList.add("magictime", ele);
  setTimeout(() => {
    selector.classList.remove("magictime", ele);
  }, 1000);
};

import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
