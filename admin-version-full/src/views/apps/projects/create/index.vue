<template>
  <DefaultLayout>
    <b-row>
      <b-col cols="12">
        <b-card no-body>
          <b-card-body class="p-0">
            <b-row class="g-0 h-100">
              <b-col lg="7" class="border-end">
                <b-card-title class="fs-16 mb-0 pt-3 ps-4"
                  >Create Project</b-card-title
                >
                <b-form class="p-4 pt-3">
                  <b-form-group
                    label="Project Name :"
                    label-for="projectName"
                    class="mb-2 mb-lg-1"
                  >
                    <b-form-input
                      type="text"
                      placeholder="Enter project name"
                      id="projectName"
                    />
                  </b-form-group>
                  <b-form-group>
                    <b-row>
                      <b-col lg="3" cols="6" class="mb-2 mb-lg-1">
                        <label class="form-label mt-2" for="pro-start-date"
                          >Start Date</label
                        >
                        <b-form-input
                          type="text"
                          name="startdate"
                          id="pro-start-date"
                          placeholder="Enter start date"
                        />
                      </b-col>
                      <b-col lg="3" cols="6" class="mb-2 mb-lg-1">
                        <label class="form-label mt-2" for="pro-end-date"
                          >End Date</label
                        >
                        <b-form-input
                          type="text"
                          name="enddate"
                          id="pro-end-date"
                          placeholder="Enter end date"
                        />
                      </b-col>
                      <b-col lg="3" cols="6" class="mb-2 mb-lg-1">
                        <label class="form-label mt-2" for="pro-rate"
                          >Rate</label
                        >
                        <b-form-input
                          type="text"
                          id="pro-rate"
                          placeholder="Enter rate"
                        />
                      </b-col>
                      <b-col lg="3" cols="6" class="mb-2 mb-lg-1">
                        <b-form-group
                          label="Price Type"
                          label-for="pro-price-type"
                          class="mt-2"
                        >
                          <b-form-select
                            model-value="Hourly"
                            id="pro-price-type"
                          >
                            <option value="Hourly">Hourly</option>
                            <option value="Daily">Daily</option>
                            <option value="Fix">Fix</option>
                          </b-form-select>
                        </b-form-group>
                      </b-col>
                    </b-row>
                  </b-form-group>
                  <b-form-group>
                    <b-row>
                      <b-col lg="6" class="mb-2 mb-lg-1">
                        <b-form-group
                          label="Required"
                          label-for="required"
                          class="mt-2"
                        >
                          <b-form-select model-value="select" id="required">
                            <option value="select">--Select--</option>
                            <option value="ui/ux-design">UI/UX Design</option>
                            <option value="payment-system">
                              Payment System
                            </option>
                            <option value="android-10">Android 10</option>
                          </b-form-select>
                        </b-form-group>
                      </b-col>
                      <b-col lg="3" cols="6">
                        <b-form-group
                          label="Invoice Time"
                          label-for="invoice-time"
                          class="mt-2"
                        >
                          <b-form-select model-value="30-day" id="invoice-time">
                            <option value="30-day">30 Day</option>
                            <option value="3-month">3 Month</option>
                            <option value="1-year">1 Year</option>
                          </b-form-select>
                        </b-form-group>
                      </b-col>
                      <b-col lg="3" cols="6">
                        <b-form-group
                          label="Priority"
                          label-for="priority"
                          class="mt-2"
                        >
                          <b-form-select model-value="select" id="priority">
                            <option value="select">-- select --</option>
                            <option value="high">High</option>
                            <option value="medium">Medium</option>
                            <option value="low">Low</option>
                          </b-form-select>
                        </b-form-group>
                      </b-col>
                    </b-row>
                  </b-form-group>
                  <b-form-group
                    label="Message"
                    label-for="pro-message"
                    label-class="mt-2"
                    class="mb-3"
                  >
                    <b-form-textarea
                      type="textarea"
                      rows="5"
                      id="pro-message"
                      placeholder="writing here.."
                    />
                  </b-form-group>
                  <b-button type="submit" variant="primary"
                    >Create project</b-button
                  >{{ " " }}
                  <b-button type="button" variant="danger">Cancel</b-button>
                </b-form>
              </b-col>
              <b-col lg="5" class="align-self-center">
                <b-form class="p-4">
                  <b-form-group>
                    <div class="d-flex align-items-center">
                      <img
                        :src="gitlab"
                        alt=""
                        class="thumb-xxl rounded me-3"
                      />
                      <div class="flex-grow-1 text-truncate">
                        <label class="btn btn-primary text-light">
                          Change Avatar <input type="file" hidden />
                        </label>
                      </div>
                    </div>
                  </b-form-group>
                  <h5 class="fw-normal my-3 lh-lg">
                    There are many variations of passages of Lorem Ipsum
                    available, but the majority have suffered alteration in some
                    form, by injected humour, or randomised.
                  </h5>
                  <b-form-group>
                    <label class="form-label" for="team-leader"
                      >Project team members</label
                    >
                    <div class="img-group d-flex justify-content-start">
                      <a
                        class="user-avatar position-relative d-inline-block"
                        href="#"
                      >
                        <img
                          :src="avatar1"
                          alt="avatar"
                          class="thumb-md shadow-sm rounded-circle"
                        />
                      </a>
                      <a
                        class="user-avatar position-relative d-inline-block ms-n2"
                        href="#"
                      >
                        <img
                          :src="avatar2"
                          alt="avatar"
                          class="thumb-md shadow-sm rounded-circle"
                        />
                      </a>
                      <a
                        class="user-avatar position-relative d-inline-block ms-n2"
                        href="#"
                      >
                        <img
                          :src="avatar4"
                          alt="avatar"
                          class="thumb-md shadow-sm rounded-circle"
                        />
                      </a>
                      <a
                        class="user-avatar position-relative d-inline-block ms-n2"
                        href="#"
                      >
                        <img
                          :src="avatar5"
                          alt="avatar"
                          class="thumb-md shadow-sm rounded-circle"
                        />
                      </a>
                      <a
                        class="user-avatar position-relative d-inline-block ms-n2"
                        href="#"
                      >
                        <img
                          :src="avatar4"
                          alt="avatar"
                          class="thumb-md shadow-sm rounded-circle"
                        />
                      </a>
                      <a
                        class="user-avatar position-relative d-inline-block ms-n2"
                        href="#"
                      >
                        <img
                          :src="avatar6"
                          alt="avatar"
                          class="thumb-md shadow-sm rounded-circle"
                        />
                      </a>
                      <a
                        href=""
                        class="user-avatar position-relative d-inline-block ms-1"
                      >
                        <span
                          class="thumb-md shadow-sm justify-content-center d-flex align-items-center bg-info-subtle rounded-circle fw-semibold fs-6"
                          >+32</span
                        >
                      </a>
                    </div>
                    <input
                      id="add-member"
                      type="file"
                      name="files[]"
                      multiple
                      style="display: none"
                    />
                  </b-form-group>
                  <div
                    class="p-3 border-info border-dashed bg-info-subtle mt-3 rounded"
                  >
                    <b-row class="d-flex justify-content-center">
                      <div class="col">
                        <div>
                          <a href="#" class="fw-bold me-1 text-info"
                            >You've almost reached your goal</a
                          >
                          75% of your goals are completed just complate 25% of
                          remaining goals to achieve your target.
                        </div>
                      </div>
                      <div class="col-auto align-self-center">
                        <b-badge
                          :variant="null"
                          class="rounded text-info bg-transparent border border-info mb-2 p-1"
                          >Last Created Project</b-badge
                        >
                        <p class="text-dark fw-semibold fs-13">
                          15 Aug 2024, AM-10:15
                        </p>
                      </div>
                    </b-row>
                  </div>
                </b-form>
              </b-col>
            </b-row>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import gitlab from "@/assets/images/logos/lang-logo/gitlab.png";

import avatar1 from "@/assets/images/users/avatar-1.jpg";
import avatar2 from "@/assets/images/users/avatar-2.jpg";
import avatar4 from "@/assets/images/users/avatar-4.jpg";
import avatar5 from "@/assets/images/users/avatar-5.jpg";
import avatar6 from "@/assets/images/users/avatar-6.jpg";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
