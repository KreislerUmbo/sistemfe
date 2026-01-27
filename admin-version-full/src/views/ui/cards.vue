<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="3">
        <b-card no-body>
          <img
            class="card-img-top img-fluid bg-light-alt"
            :src="img1"
            alt="Card img cap"
          />
          <b-card-header>
            <b-row class="align-items-center">
              <div class="col">
                <b-card-title>Card title</b-card-title>
              </div>
              <div class="col-auto">
                <img class="rounded-circle" :src="avatar7" alt="" height="24" />
                {{ " " }}
                <b-badge :variant="null" class="bg-primary-subtle text-primary">
                  30 May 2024
                </b-badge>
              </div>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <p class="card-text text-muted">
              Some quick example text to build on the card title and make up the
              bulk of the card's content.
            </p>
            <a href="#" class="btn btn-outline-primary btn-sm">Go somewhere</a>
          </b-card-body>
        </b-card>
      </b-col>
      <b-col md="6" xl="3">
        <b-card no-body>
          <b-card-header>
            <b-card-title>Card Body</b-card-title>
          </b-card-header>
          <b-card-body class="pt-0">
            <p class="card-subtitle fs-14 mb-2">This is the card subtitle</p>
            <p class="card-text text-muted">
              Some quick example text the bulk of the card's content. It is a
              long established fact that a reader will be distracted by the
              readable content.
            </p>
          </b-card-body>
        </b-card>
        <b-card no-body>
          <b-card-body>
            <h6 class="card-subtitle fs-14 mb-2">This is the card subtitle</h6>
            <p class="card-text text-muted">
              Some quick example text to build on the card title and make up the
              bulk of the card's content.
            </p>
            <a href="#" class="card-link text-primary">Card link</a> {{ " " }}
            <a href="#" class="card-link text-primary">Another link</a>
          </b-card-body>
        </b-card>
      </b-col>
      <b-col md="6" xl="3">
        <b-card no-body>
          <b-card-header class="bg-primary">
            <b-card-title class="text-white">Header - Primary</b-card-title>
          </b-card-header>
          <b-card-body>
            <p class="card-text text-muted">
              Some quick example text to build on the card title and make up the
              bulk of the card's content.
            </p>
          </b-card-body>
          <p class="card-footer bg-light-alt m-0">Footer - 2024 Rizz</p>
        </b-card>
        <b-card no-body>
          <b-card-body>
            <blockquote class="card-bodyquote mb-0">
              <p>
                Lorem ipsum dolor sit amet, consectetur adipiscing elit. Integer
                posuere erat a amet, consectetur adipiscing elit ante.
              </p>
              <footer class="blockquote-footer fs-14 mb-0 mt-2">
                Someone famous in <cite title="Source Title">Source Title</cite>
              </footer>
            </blockquote>
          </b-card-body>
        </b-card>
      </b-col>
      <b-col md="6" xl="3">
        <b-card no-body>
          <b-card-header>
            <b-card-title>List Group</b-card-title>
          </b-card-header>
          <b-card-body class="pt-0">
            <ul class="list-group list-group-flush border mb-3">
              <li class="list-group-item">Cras justo odio</li>
              <li class="list-group-item">Dapibus ac facilisis in</li>
              <li class="list-group-item">Vestibulum at eros</li>
            </ul>
            <p class="card-text text-muted">
              Some quick example text to build on the card title and make up the
              bulk of the card's content.
            </p>
            <a href="#" class="btn btn-outline-primary btn-sm">Go somewhere</a>
            {{ " " }}
            <a href="#" class="btn btn-outline-secondary btn-sm">Finish</a>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>
    <b-row>
      <b-col lg="4">
        <b-card no-body>
          <b-row class="g-0">
            <b-col md="4">
              <img :src="vcard" class="img-fluid rounded-start" alt="..." />
            </b-col>
            <b-col md="8">
              <b-card-body>
                <b-card-title tag="h5">Card title</b-card-title>
                <p class="card-text">
                  This is a wider card with supporting text below as a natural
                  lead-in to additional content. This content is a little bit
                  longer.
                </p>
                <p class="card-text">
                  <small class="text-body-secondary"
                    >Last updated 3 mins ago</small
                  >
                </p>
              </b-card-body>
            </b-col>
          </b-row>
        </b-card>
      </b-col>
      <b-col lg="4">
        <b-card no-body class="text-center">
          <b-card-header>
            <ul class="nav nav-tabs card-header-tabs">
              <li class="nav-item">
                <a class="nav-link active" href="#">Active</a>
              </li>
              <li class="nav-item">
                <a class="nav-link" href="#">Link</a>
              </li>
              <li class="nav-item">
                <a
                  class="nav-link disabled"
                  href="#"
                  tabindex="-1"
                  aria-disabled="true"
                  >Disabled</a
                >
              </li>
            </ul>
          </b-card-header>
          <b-card-body>
            <b-card-title tag="h5" class="mb-2"
              >Special title treatment</b-card-title
            >
            <p class="card-text">
              With supporting text below as a natural lead-in to additional
              content.
            </p>
            <a href="#" class="btn btn-primary btn-sm">Go somewhere</a>
          </b-card-body>
        </b-card>
      </b-col>
      <b-col lg="4">
        <b-card no-body>
          <b-row class="g-0">
            <b-col md="8">
              <b-card-body>
                <b-card-title tag="h5">Card title</b-card-title>
                <p class="card-text">
                  This is a wider card with supporting text below as a natural
                  lead-in to additional content. This content is a little bit
                  longer.
                </p>
                <p class="card-text">
                  <small class="text-body-secondary"
                    >Last updated 3 mins ago</small
                  >
                </p>
              </b-card-body>
            </b-col>
            <b-col md="4">
              <img :src="vcard" class="img-fluid rounded-end" alt="..." />
            </b-col>
          </b-row>
        </b-card>
      </b-col>
    </b-row>
    <b-row>
      <b-col lg="4">
        <b-card no-body>
          <b-card-header>
            <b-card-title>Special title treatment</b-card-title>
          </b-card-header>
          <b-card-body class="pt-0">
            <p class="card-text text-muted">
              With supporting text below as a natural lead-in to additional
              content.
            </p>
            <a href="#" class="btn btn-primary btn-sm">Go somewhere</a>
          </b-card-body>
        </b-card>
      </b-col>

      <b-col lg="4">
        <b-card no-body class="text-center">
          <b-card-header>
            <b-card-title>Special title treatment</b-card-title>
          </b-card-header>
          <b-card-body class="pt-0">
            <p class="card-text text-muted">
              With supporting text below as a natural lead-in to additional
              content.
            </p>
            <a href="#" class="btn btn-primary btn-sm">Go somewhere</a>
          </b-card-body>
        </b-card>
      </b-col>

      <b-col lg="4">
        <b-card no-body class="text-end">
          <b-card-header>
            <b-card-title>Special title treatment</b-card-title>
          </b-card-header>
          <b-card-body class="pt-0">
            <p class="card-text text-muted">
              With supporting text below as a natural lead-in to additional
              content.
            </p>
            <a href="#" class="btn btn-primary btn-sm">Go somewhere</a>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>
    <b-row>
      <b-col lg="6">
        <div class="card-group mb-3">
          <b-card no-body>
            <img class="img-fluid bg-light-alt" :src="excard" alt="Card img" />
            <b-card-header>
              <b-card-title>Card Groups</b-card-title>
            </b-card-header>
            <b-card-body class="pt-0">
              <p class="card-text">
                This is a wider card supporting text below to content.
              </p>
              <p class="card-text">
                <small class="text-muted">Last updated 3 mins ago</small>
              </p>
            </b-card-body>
          </b-card>
          <b-card no-body>
            <img class="img-fluid bg-light-alt" :src="excard" alt="Card img" />
            <b-card-header>
              <b-card-title>Card Groups</b-card-title>
            </b-card-header>
            <b-card-body class="pt-0">
              <p class="card-text">
                This is a wider card supporting text below to content.
              </p>
              <p class="card-text">
                <small class="text-muted">Last updated 3 mins ago</small>
              </p>
            </b-card-body>
          </b-card>
          <b-card no-body>
            <img class="img-fluid bg-light-alt" :src="excard" alt="Card img" />
            <b-card-header>
              <b-card-title>Card Groups</b-card-title>
            </b-card-header>
            <b-card-body class="pt-0">
              <p class="card-text">
                This is a wider card supporting text below to content.
              </p>
              <p class="card-text">
                <small class="text-muted">Last updated 3 mins ago</small>
              </p>
            </b-card-body>
          </b-card>
        </div>
        <b-card no-body class="mb-3">
          <b-row class="g-0">
            <b-col md="4" class="bg-light-alt align-self-center">
              <img :src="excard" alt="..." class="img-fluid bg-light-alt" />
            </b-col>
            <b-col md="8">
              <b-card-body>
                <b-card-title tag="h5">Card title</b-card-title>
                <p class="card-text mb-0">
                  This is a wider card with supporting text below as a natural
                  lead-in to additional content. This content is a little bit
                  longer.
                </p>
                <p class="card-text mb-0">
                  <small class="text-muted">Last updated 3 mins ago</small>
                </p>
              </b-card-body>
            </b-col>
          </b-row>
        </b-card>
      </b-col>
      <b-col lg="6">
        <b-row class="row-cols-1 row-cols-md-2 gx-3">
          <b-col>
            <b-card no-body>
              <img :src="excard" class="card-img-top bg-light-alt" alt="..." />
              <b-card-body>
                <b-card-title tag="h5">Card title</b-card-title>
                <p class="card-text">
                  This is a longer card with supporting text below as a natural
                  lead-in to additional content. This content is a little bit
                  longer.
                </p>
              </b-card-body>
            </b-card>
          </b-col>
          <b-col>
            <b-card no-body>
              <img :src="excard" class="card-img-top bg-light-alt" alt="..." />
              <b-card-body>
                <b-card-title tag="h5">Card title</b-card-title>
                <p class="card-text">
                  This is a longer card with supporting text below as a natural
                  lead-in to additional content. This content is a little bit
                  longer.
                </p>
              </b-card-body>
            </b-card>
          </b-col>
          <b-col>
            <b-card no-body>
              <img :src="excard" class="card-img-top bg-light-alt" alt="..." />
              <b-card-body>
                <b-card-title tag="h5">Card title</b-card-title>
                <p class="card-text">
                  This is a longer card with supporting text below as a natural
                  lead-in to additional content.
                </p>
              </b-card-body>
            </b-card>
          </b-col>
          <b-col>
            <b-card no-body>
              <img :src="excard" class="card-img-top bg-light-alt" alt="..." />
              <b-card-body>
                <b-card-title tag="h5">Card title</b-card-title>
                <p class="card-text">
                  This is a longer card with supporting text below as a natural
                  lead-in to additional content. This content is a little bit
                  longer.
                </p>
              </b-card-body>
            </b-card>
          </b-col>
        </b-row>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import avatar7 from "@/assets/images/users/avatar-7.jpg";
import img1 from "@/assets/images/extra/card/img-1.jpg";
import vcard from "@/assets/images/extra/card/v-card.jpg";
import excard from "@/assets/images/extra/card/ex-card.png";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
