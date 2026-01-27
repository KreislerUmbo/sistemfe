<template>
  <DefaultLayout>
    <b-row>
      <b-col cols="12">
        <b-card no-body>
          <b-card-body>
            <b-row>
              <b-col lg="5" class="offset-lg-1 align-self-center">
                <div class="p-3">
                  <span class="bg-pink-subtle p-1 rounded text-pink fw-medium"
                    >Basic Examples
                  </span>
                  <h1 class="my-4 font-weight-bold">
                    Manage Your Work With
                    <span class="text-primary">Rizz</span>.
                  </h1>
                  <p class="fs-14 text-muted">
                    Rizz is a Bootstrap 5 admin dashboard, It is fully
                    responsive and included awesome features to help build web
                    applications fast and easy.
                  </p>
                  <b-button type="button" variant="primary"
                    >Get Started</b-button
                  >
                </div>
              </b-col>
              <b-col lg="5" class="offset-lg-1 text-center">
                <b-carousel>
                  <b-carousel-slide :img-src="img2" />
                  <b-carousel-slide :img-src="img1" />
                  <b-carousel-slide :img-src="img3" />
                </b-carousel>
              </b-col>
            </b-row>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>

    <b-row>
      <b-col cols="12">
        <b-card no-body>
          <b-card-body>
            <b-row>
              <b-col lg="5" class="text-center">
                <b-carousel controls>
                  <b-carousel-slide :img-src="img4" />
                  <b-carousel-slide :img-src="img5" />
                  <b-carousel-slide :img-src="img6" />
                </b-carousel>
              </b-col>
              <b-col lg="5" class="offset-lg-1 align-self-center">
                <div class="p-3">
                  <span class="bg-pink-subtle p-1 rounded text-pink fw-medium"
                    >Indicators</span
                  >
                  <h1 class="my-4 font-weight-bold">
                    Manage Your Work With
                    <span class="text-primary">Rizz</span>.
                  </h1>
                  <p class="fs-14 text-muted">
                    Rizz is a Bootstrap 5 admin dashboard, It is fully
                    responsive and included awesome features to help build web
                    applications fast and easy.
                  </p>
                  <b-button type="button" variant="primary"
                    >Get Started</b-button
                  >
                </div>
              </b-col>
            </b-row>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>

    <b-row>
      <b-col cols="12">
        <b-card no-body>
          <b-card-body>
            <b-row>
              <b-col lg="5" class="offset-lg-1 align-self-center">
                <div class="p-3">
                  <span class="bg-pink-subtle p-1 rounded text-pink fw-medium"
                    >Captions
                  </span>
                  <h1 class="my-4 font-weight-bold">
                    Manage Your Work With
                    <span class="text-primary">Rizz</span>.
                  </h1>
                  <p class="fs-14 text-muted">
                    Rizz is a Bootstrap 5 admin dashboard, It is fully
                    responsive and included awesome features to help build web
                    applications fast and easy.
                  </p>
                  <b-button type="button" variant="primary"
                    >Get Started</b-button
                  >
                </div>
              </b-col>
              <b-col lg="5" class="offset-lg-1 text-center">
                <b-carousel controls indicators>
                  <b-carousel-slide :img-src="img4" />
                  <b-carousel-slide :img-src="img5" />
                  <b-carousel-slide :img-src="img6" />
                </b-carousel>
              </b-col>
            </b-row>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>

    <b-row>
      <b-col cols="12">
        <b-card no-body>
          <b-card-body>
            <b-row>
              <b-col lg="5" class="text-center">
                <b-carousel fade controls>
                  <b-carousel-slide :img-src="img6" />
                  <b-carousel-slide :img-src="img1" />
                  <b-carousel-slide :img-src="img3" />
                </b-carousel>
              </b-col>
              <b-col lg="5" class="offset-lg-1 align-self-center">
                <div class="p-3">
                  <span class="bg-pink-subtle p-1 rounded text-pink fw-medium"
                    >Crossfade</span
                  >
                  <h1 class="my-4 font-weight-bold">
                    Manage Your Work With
                    <span class="text-primary">Rizz</span>.
                  </h1>
                  <p class="fs-14 text-muted">
                    Rizz is a Bootstrap 5 admin dashboard, It is fully
                    responsive and included awesome features to help build web
                    applications fast and easy.
                  </p>
                  <b-button type="button" variant="primary"
                    >Get Started</b-button
                  >
                </div>
              </b-col>
            </b-row>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>

    <b-row>
      <b-col cols="12">
        <b-card no-body>
          <b-card-body>
            <b-row>
              <b-col lg="5" class="offset-lg-1 align-self-center">
                <div class="p-3">
                  <span class="bg-pink-subtle p-1 rounded text-pink fw-medium"
                    >Dark variant
                  </span>
                  <h1 class="my-4 font-weight-bold">
                    Manage Your Work With
                    <span class="text-primary">Rizz</span>.
                  </h1>
                  <p class="fs-14 text-muted">
                    Rizz is a Bootstrap 5 admin dashboard, It is fully
                    responsive and included awesome features to help build web
                    applications fast and easy.
                  </p>
                  <b-button type="button" variant="primary"
                    >Get Started</b-button
                  >
                </div>
              </b-col>
              <b-col lg="5" class="offset-lg-1 text-center">
                <b-carousel controls indicators class="carousel-dark">
                  <b-carousel-slide
                    :img-src="img6"
                    text="Some representative placeholder content for the first slide."
                    caption-html="<h5 class='text-white'>First slide label</h5>"
                  />
                  <b-carousel-slide
                    :img-src="img1"
                    text="Some representative placeholder content for the second slide."
                    caption-html="<h5 class='text-white'>Second slide label</h5>"
                  />
                  <b-carousel-slide
                    :img-src="img3"
                    text="Some representative placeholder content for the third slide."
                    caption-html="<h5 class='text-white'>Third slide label</h5>"
                  />
                </b-carousel>
              </b-col>
            </b-row>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import img1 from "@/assets/images/extra/card/img-1.jpg";
import img2 from "@/assets/images/extra/card/img-2.jpg";
import img3 from "@/assets/images/extra/card/img-3.jpg";
import img4 from "@/assets/images/extra/card/img-4.jpg";
import img5 from "@/assets/images/extra/card/img-5.jpg";
import img6 from "@/assets/images/extra/card/img-6.jpg";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
