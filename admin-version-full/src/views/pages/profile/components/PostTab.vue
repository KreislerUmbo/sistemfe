<template>
  <b-row>
    <b-col lg="6">
      <b-card no-body>
        <b-card-body>
          <b-row class="d-flex justify-content-center">
            <div class="col">
              <p class="text-dark mb-1 fw-semibold">Views</p>
              <h3 class="my-2 fs-24 fw-bold">24k</h3>
              <p class="mb-0 text-truncate text-muted">
                <i class="iconoir-bell text-warning fs-18" />{{ " " }}
                <span class="text-dark fw-semibold">1500</span> New subscribers
                this week
              </p>
            </div>
            <div class="col-auto align-self-center">
              <div
                class="d-flex justify-content-center align-items-center thumb-xl bg-light rounded-circle mx-auto"
              >
                <i class="iconoir-eye fs-30 align-self-center text-muted"></i>
              </div>
            </div>
          </b-row>
        </b-card-body>
      </b-card>
    </b-col>
    <b-col lg="6">
      <b-card no-body>
        <b-card-body>
          <b-row class="d-flex justify-content-center">
            <div class="col">
              <p class="text-dark mb-1 fw-semibold">Comments</p>
              <h3 class="my-2 fs-24 fw-bold">24k</h3>
              <p class="mb-0 text-truncate text-muted">
                <i class="ti ti-thumb-up text-success fs-18"></i>
                <span class="text-dark fw-semibold">854</span> New Like this
                week
              </p>
            </div>
            <div class="col-auto align-self-center">
              <div
                class="d-flex justify-content-center align-items-center thumb-xl bg-light rounded-circle mx-auto"
              >
                <i
                  class="iconoir-chat-lines fs-30 align-self-center text-muted"
                ></i>
              </div>
            </div>
          </b-row>
        </b-card-body>
      </b-card>
    </b-col>
  </b-row>
  <b-row>
    <b-col cols="12">
      <b-card no-body>
        <b-card-header>
          <b-row class="align-items-center">
            <div class="col">
              <div class="d-flex align-items-center">
                <img
                  :src="avatar10"
                  class="thumb-md align-self-center rounded-circle"
                  alt="..."
                />
                <div class="flex-grow-1 ms-2">
                  <h5 class="m-0 fs-14">Anderson Vanhron</h5>
                  <p class="text-muted mb-0 fs-12">online</p>
                </div>
              </div>
            </div>
            <div class="col-auto">
              <div class="d-none d-sm-inline-block align-self-center">
                <a
                  href="javascript:void(0)"
                  class="me-2 text-muted"
                  id="tooltip1"
                >
                  <i class="iconoir-media-image fs-18"></i> </a
                >{{ " " }}
                <b-tooltip target="tooltip1" class="tooltip-primary"
                  >Call</b-tooltip
                >

                <a
                  href="javascript:void(0)"
                  class="me-2 text-muted"
                  id="tooltip2"
                >
                  <i class="iconoir-attachment fs-18"></i> </a
                >{{ " " }}
                <b-tooltip target="tooltip2" class="tooltip-primary"
                  >Attachment</b-tooltip
                >

                <a
                  href="javascript:void(0)"
                  class="me-2 text-muted"
                  id="tooltip3"
                >
                  <i class="iconoir-calendar fs-18"></i> </a
                >{{ " " }}
                <b-tooltip target="tooltip3" class="tooltip-primary"
                  >Delete</b-tooltip
                >

                <DropDown custom-class="d-inline-block">
                  <a
                    class="dropdown-toggle arrow-none text-muted"
                    data-bs-toggle="dropdown"
                    href="#"
                    role="button"
                    aria-haspopup="false"
                    aria-expanded="true"
                  >
                    <i class="iconoir-more-vert fs-18"></i>
                  </a>
                  <div class="dropdown-menu dropdown-menu-end pb-0">
                    <a class="dropdown-item" href="#">Profile</a>
                    <a class="dropdown-item" href="#">Add archive</a>
                    <a class="dropdown-item" href="#">Delete</a>
                    <a class="dropdown-item text-danger" href="#">Block</a>
                  </div>
                </DropDown>
              </div>
            </div>
          </b-row>
        </b-card-header>
        <b-card-body class="pt-0">
          <b-form action="/">
            <div>
              <b-form-textarea
                type="textarea"
                class="mb-2"
                rows="5"
                id="post-1"
                placeholder="Write here.."
              />
              <b-button type="button" variant="primary">Post</b-button>
            </div>
          </b-form>
        </b-card-body>
      </b-card>
    </b-col>
    <b-col cols="12">
      <b-card no-body>
        <b-card-body>
          <img :src="post1" alt="" class="img-fluid" />
          <div class="post-title mt-3">
            <b-row>
              <div class="col">
                <b-badge :variant="null" class="bg-primary-subtle text-primary"
                  >Natural</b-badge
                >
              </div>
              <div class="col-auto">
                <span class="text-muted"
                  ><i class="far fa-calendar me-1"></i>02 July 2024</span
                >
              </div>
            </b-row>

            <h5 href="#" class="fs-20 fw-bold d-block my-3">
              There is nothing more beautiful than nature.
            </h5>
            <span class="fs-15 bg-light py-2 px-3 rounded"
              >Taking pictures is savouring life intensely.</span
            >
            <p class="fs-15 mt-3">
              It is a long established fact that a reader will be distracted by
              the readable content of a page when looking at its layout. The
              point of using Lorem Ipsum is that it has a more-or-less normal
              distribution of letters, as opposed to using 'Content here,
              content here', making it look like readable English. Many desktop
              publishing packages and web page editors now use Lorem Ipsum as
              their default model text, and a search for 'lorem ipsum' will
              uncover many web sites still in their infancy.
            </p>
            <blockquote class="blockquote border-start ps-4">
              <p class="fs-18">
                <i
                  >"Lorem ipsum dolor sit amet, consectetur adipiscing elit.
                  Integer posuere erat a ante."</i
                >
              </p>
              <footer class="blockquote-footer mt-1">
                Someone famous in <cite title="Source Title">Source Title</cite>
              </footer>
            </blockquote>
            <p class="fs-15">
              Taking pictures is savouring life intensely, every hundredth of a
              second – Marc Riboud. Joy in looking and comprehending is nature’s
              most beautiful gift.
            </p>
            <p class="fs-15 mb-0">
              It is a long established fact that a reader will be distracted by
              the readable content of a page when looking at its layout. The
              point of using Lorem Ipsum is that it has a more-or-less normal
              distribution of letters, as opposed to using 'Content here,
              content here', making it look like readable English. Many desktop
              publishing packages and web page editors now use Lorem Ipsum as
              their default model text, and a search for 'lorem ipsum' will
              uncover many web sites still in their infancy.
            </p>
          </div>
        </b-card-body>
        <b-card-body class="pt-0">
          <b-row class="mb-3">
            <div class="col">
              <p class="text-dark fw-semibold mb-0">Artical tags</p>
            </div>
          </b-row>
          <b-badge variant="light" class="text-dark px-3 py-2 fw-semibold"
            >Music</b-badge
          >{{ "" }}
          <b-badge variant="light" class="text-dark px-3 py-2 fw-semibold"
            >Animals</b-badge
          >{{ "" }}
          <b-badge variant="light" class="text-dark px-3 py-2 fw-semibold"
            >Natural</b-badge
          >{{ "" }}
          <b-badge variant="light" class="text-dark px-3 py-2 fw-semibold"
            >Food</b-badge
          >{{ "" }}
          <b-badge variant="light" class="text-dark px-3 py-2 fw-semibold"
            >Fashion</b-badge
          >{{ "" }}
          <b-badge variant="light" class="text-dark px-3 py-2 fw-semibold"
            >Health</b-badge
          >{{ "" }}
          <b-badge variant="light" class="text-dark px-3 py-2 fw-semibold"
            >Charity</b-badge
          >
        </b-card-body>
      </b-card>
      <b-card no-body>
        <b-card-body class="pb-0">
          <b-row>
            <b-col>
              <p class="text-dark fw-semibold mb-0">Comments (205)</p>
            </b-col>
          </b-row>
        </b-card-body>
        <b-card-body class="border-bottom-dashed">
          <ul class="list-unstyled mb-0">
            <li>
              <b-row>
                <div class="col-auto">
                  <img :src="avatar2" alt="" class="thumb-md rounded-circle" />
                </div>
                <div class="col">
                  <div class="bg-light rounded ms-n2 bg-light-alt p-3">
                    <b-row>
                      <div class="col">
                        <p class="text-dark fw-semibold mb-2">Martin Luther</p>
                      </div>
                      <div class="col-auto">
                        <span class="text-muted"
                          ><i class="far fa-clock me-1"></i>30 min ago</span
                        >
                      </div>
                    </b-row>
                    <p>
                      It is a long established fact that a reader will be
                      distracted by the readable content of a page when looking
                      at its layout. The point of using Lorem Ipsum is that it
                      has a more-or-less normal distribution of letters, as
                      opposed to using 'Content here, content here', making it
                      look like readable English.
                    </p>
                    <a href="#" class="text-primary"
                      ><i class="fas fa-reply me-1"></i>Replay</a
                    >
                  </div>
                </div>
              </b-row>
              <ul class="list-unstyled ms-5">
                <li>
                  <b-row class="mt-3">
                    <div class="col-auto">
                      <img
                        :src="logoSm"
                        alt=""
                        class="thumb-md rounded-circle"
                      />
                    </div>
                    <div class="col">
                      <div class="bg-light rounded ms-n2 bg-light-alt p-3">
                        <b-row>
                          <div class="col">
                            <p class="text-dark fw-semibold mb-2">
                              Metrica Author
                            </p>
                          </div>
                          <div class="col-auto">
                            <span class="text-muted"
                              ><i class="far fa-clock me-1"></i>37 min ago</span
                            >
                          </div>
                        </b-row>
                        <p>
                          It is a long established fact that a reader will be
                          distracted by the readable content of a page when
                          looking at its layout.
                        </p>
                        <p class="mb-0">Thank you</p>
                      </div>
                    </div>
                  </b-row>
                </li>
              </ul>
            </li>
            <li class="mt-3">
              <b-row>
                <div class="col-auto">
                  <img :src="avatar1" alt="" class="thumb-md rounded-circle" />
                </div>
                <div class="col">
                  <div class="bg-light rounded ms-n2 bg-light-alt p-3">
                    <b-row>
                      <div class="col">
                        <p class="text-dark fw-semibold mb-2">Joseph Rust</p>
                      </div>
                      <div class="col-auto">
                        <span class="text-muted"
                          ><i class="far fa-clock me-1"></i>40 min ago</span
                        >
                      </div>
                    </b-row>
                    <p>
                      Is it a long established fact that a reader will be
                      distracted by the readable content of a page when looking
                      at its layout?
                    </p>
                    <a href="#" class="text-primary"
                      ><i class="fas fa-reply me-1"></i>Replay</a
                    >
                  </div>
                </div>
              </b-row>
            </li>
            <li class="mt-3">
              <b-row>
                <div class="col-auto">
                  <img :src="avatar5" alt="" class="thumb-md rounded-circle" />
                </div>
                <div class="col">
                  <div class="bg-light rounded ms-n2 bg-light-alt p-3">
                    <b-row>
                      <div class="col">
                        <p class="text-dark fw-semibold mb-2">Matt Rosales</p>
                      </div>
                      <div class="col-auto">
                        <span class="text-muted"
                          ><i class="far fa-clock me-1"></i>40 min ago</span
                        >
                      </div>
                    </b-row>
                    <p>
                      Is it a long established fact that a reader will be
                      distracted by the readable content of a page when looking
                      at its layout?
                    </p>
                    <a href="#" class="text-primary"
                      ><i class="fas fa-reply me-1"></i>Replay</a
                    >
                  </div>
                </div>
              </b-row>
              <ul class="list-unstyled ms-5">
                <li>
                  <b-row class="mt-3">
                    <div class="col-auto">
                      <img
                        :src="logoSm"
                        alt=""
                        class="thumb-md rounded-circle"
                      />
                    </div>
                    <div class="col">
                      <div class="bg-light rounded ms-n2 bg-light-alt p-3">
                        <b-row>
                          <div class="col">
                            <p class="text-dark fw-semibold mb-2">
                              Metrica Author
                            </p>
                          </div>
                          <div class="col-auto">
                            <span class="text-muted"
                              ><i class="far fa-clock me-1"></i>37 min ago</span
                            >
                          </div>
                        </b-row>
                        <p>
                          It is a long established fact that a reader will be
                          distracted by the readable content of a page when
                          looking at its layout.
                        </p>
                        <p class="mb-0">Thank you</p>
                      </div>
                    </div>
                  </b-row>
                </li>
              </ul>
            </li>
          </ul>
        </b-card-body>
        <b-card-body>
          <b-row>
            <b-col>
              <p class="text-dark fw-semibold mb-0">Leave a comment</p>
            </b-col>
          </b-row>
        </b-card-body>
        <b-card-body class="pt-0">
          <form>
            <b-form-group label-for="leave_comment" class="mb-3">
              <b-form-textarea
                type="textarea"
                rows="5"
                id="leave_comment"
                placeholder="Message"
              />
            </b-form-group>
            <b-row>
              <b-col sm="12" class="text-end">
                <b-button type="submit" variant="primary" class="px-4"
                  >Send Message</b-button
                >
              </b-col>
            </b-row>
          </form>
        </b-card-body>
      </b-card>
    </b-col>
  </b-row>
</template>
<script setup lang="ts">
import DropDown from "@/components/DropDown.vue";

import avatar10 from "@/assets/images/users/avatar-10.jpg";
import avatar2 from "@/assets/images/users/avatar-2.jpg";
import avatar1 from "@/assets/images/users/avatar-1.jpg";
import avatar5 from "@/assets/images/users/avatar-5.jpg";
import post1 from "@/assets/images/extra/card/post-1.jpg";

import logoSm from "@/assets/images/logo-sm.png";
</script>
