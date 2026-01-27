<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Basic Example</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <b-table-simple responsive class="mb-0 table-centered">
              <b-thead class="table-light">
                <b-tr>
                  <b-th>Company</b-th>
                  <b-th>Bill</b-th>
                  <b-th>Average Bill</b-th>
                  <b-th>Paid Bill</b-th>
                  <b-th class="text-end">Action</b-th>
                </b-tr>
              </b-thead>
              <b-tbody>
                <b-tr>
                  <b-td
                    ><img
                      :src="chatgpt"
                      alt=""
                      class="rounded-circle thumb-md me-1 d-inline"
                    />
                    Micromin
                  </b-td>
                  <b-td>4</b-td>
                  <b-td>{{ currency }}250</b-td>
                  <b-td>{{ currency }}800</b-td>
                  <b-td class="text-end">
                    <b-dropdown
                      :variant="null"
                      class="d-inline-block"
                      menu-class="dropdown-menu-end"
                      toggle-class="p-0 m-0"
                    >
                      <template #button-content>
                        <i class="las la-ellipsis-v fs-20 text-muted"></i>
                      </template>
                      <b-dropdown-item>Create Project</b-dropdown-item>
                      <b-dropdown-item>Open Project</b-dropdown-item>
                      <b-dropdown-item>Tasks Details</b-dropdown-item>
                    </b-dropdown>
                  </b-td>
                </b-tr>
                <b-tr>
                  <b-td
                    ><img
                      :src="nextjs"
                      alt=""
                      class="rounded-circle thumb-md me-1 d-inline"
                    />
                    ZZ Diamond
                  </b-td>
                  <b-td>2</b-td>
                  <b-td>{{ currency }}180</b-td>
                  <b-td>{{ currency }}400</b-td>
                  <b-td class="text-end">
                    <b-dropdown
                      :variant="null"
                      class="d-inline-block"
                      menu-class="dropdown-menu-end"
                      toggle-class="p-0 m-0"
                    >
                      <template #button-content>
                        <i class="las la-ellipsis-v fs-20 text-muted"></i>
                      </template>
                      <b-dropdown-item>Create Project</b-dropdown-item>
                      <b-dropdown-item>Open Project</b-dropdown-item>
                      <b-dropdown-item>Tasks Details</b-dropdown-item>
                    </b-dropdown>
                  </b-td>
                </b-tr>
                <b-tr>
                  <b-td
                    ><img
                      :src="reactjs"
                      alt=""
                      class="rounded-circle thumb-md me-1 d-inline"
                    />
                    Dairy Sweet
                  </b-td>
                  <b-td>6</b-td>
                  <b-td>{{ currency }}210</b-td>
                  <b-td>{{ currency }}500</b-td>
                  <b-td class="text-end">
                    <b-dropdown
                      :variant="null"
                      class="d-inline-block"
                      menu-class="dropdown-menu-end"
                      toggle-class="p-0 m-0"
                    >
                      <template #button-content>
                        <i class="las la-ellipsis-v fs-20 text-muted"></i>
                      </template>
                      <b-dropdown-item>Create Project</b-dropdown-item>
                      <b-dropdown-item>Open Project</b-dropdown-item>
                      <b-dropdown-item>Tasks Details</b-dropdown-item>
                    </b-dropdown>
                  </b-td>
                </b-tr>
                <b-tr>
                  <b-td
                    ><img
                      :src="symfony"
                      alt=""
                      class="rounded-circle thumb-md me-1 d-inline"
                    />
                    Corner Tea
                  </b-td>
                  <b-td>3</b-td>
                  <b-td>{{ currency }}80</b-td>
                  <b-td>{{ currency }}350</b-td>
                  <b-td class="text-end">
                    <b-dropdown
                      :variant="null"
                      class="d-inline-block"
                      menu-class="dropdown-menu-end"
                      toggle-class="p-0 m-0"
                    >
                      <template #button-content>
                        <i class="las la-ellipsis-v fs-20 text-muted"></i>
                      </template>
                      <b-dropdown-item>Create Project</b-dropdown-item>
                      <b-dropdown-item>Open Project</b-dropdown-item>
                      <b-dropdown-item>Tasks Details</b-dropdown-item>
                    </b-dropdown>
                  </b-td>
                </b-tr>
              </b-tbody>
            </b-table-simple>
          </b-card-body>
        </b-card>
      </b-col>
      <b-col md="6" lg="6">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Bordered Table</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <b-table-simple responsive bordered class="mb-0 table-centered">
              <b-thead>
                <b-tr>
                  <b-th>Transaction ID</b-th>
                  <b-th>Date</b-th>
                  <b-th>Price</b-th>
                  <b-th>Order Status</b-th>
                  <b-th class="text-end">Action</b-th>
                </b-tr>
              </b-thead>
              <b-tbody>
                <b-tr>
                  <b-td>#124781</b-td>
                  <b-td>25/11/2018</b-td>
                  <b-td>{{ currency }}321</b-td>
                  <b-td>
                    <b-badge variant="success"> Approved </b-badge>
                  </b-td>
                  <b-td class="text-end">
                    <b-dropdown
                      :variant="null"
                      class="d-inline-block"
                      menu-class="dropdown-menu-end"
                      toggle-class="p-0 m-0"
                    >
                      <template #button-content>
                        <i class="las la-ellipsis-v fs-20 text-muted"></i>
                      </template>
                      <b-dropdown-item>Create Project</b-dropdown-item>
                      <b-dropdown-item>Open Project</b-dropdown-item>
                      <b-dropdown-item>Tasks Details</b-dropdown-item>
                    </b-dropdown>
                  </b-td>
                </b-tr>
                <b-tr>
                  <b-td>#542136</b-td>
                  <b-td>19/11/2018</b-td>
                  <b-td>{{ currency }}227</b-td>
                  <b-td><b-badge variant="success"> Approved </b-badge></b-td>
                  <b-td class="text-end">
                    <b-dropdown
                      :variant="null"
                      class="d-inline-block"
                      menu-class="dropdown-menu-end"
                      toggle-class="p-0 m-0"
                    >
                      <template #button-content>
                        <i class="las la-ellipsis-v fs-20 text-muted"></i>
                      </template>
                      <b-dropdown-item>Create Project</b-dropdown-item>
                      <b-dropdown-item>Open Project</b-dropdown-item>
                      <b-dropdown-item>Tasks Details</b-dropdown-item>
                    </b-dropdown>
                  </b-td>
                </b-tr>
                <b-tr>
                  <b-td>#234578</b-td>
                  <b-td>11/10/2018</b-td>
                  <b-td>{{ currency }}442</b-td>
                  <b-td>
                    <b-badge variant="danger"> Rejected </b-badge>
                  </b-td>
                  <b-td class="text-end">
                    <b-dropdown
                      :variant="null"
                      class="d-inline-block"
                      menu-class="dropdown-menu-end"
                      toggle-class="p-0 m-0"
                    >
                      <template #button-content>
                        <i class="las la-ellipsis-v fs-20 text-muted"></i>
                      </template>
                      <b-dropdown-item>Create Project</b-dropdown-item>
                      <b-dropdown-item>Open Project</b-dropdown-item>
                      <b-dropdown-item>Tasks Details</b-dropdown-item>
                    </b-dropdown>
                  </b-td>
                </b-tr>
                <b-tr>
                  <b-td>#951357</b-td>
                  <b-td>03/12/2018</b-td>
                  <b-td>{{ currency }}625</b-td>
                  <b-td><b-badge variant="success"> Approved </b-badge></b-td>
                  <b-td class="text-end">
                    <b-dropdown
                      :variant="null"
                      class="d-inline-block"
                      menu-class="dropdown-menu-end"
                      toggle-class="p-0 m-0"
                    >
                      <template #button-content>
                        <i class="las la-ellipsis-v fs-20 text-muted"></i>
                      </template>
                      <b-dropdown-item>Create Project</b-dropdown-item>
                      <b-dropdown-item>Open Project</b-dropdown-item>
                      <b-dropdown-item>Tasks Details</b-dropdown-item>
                    </b-dropdown>
                  </b-td>
                </b-tr>
              </b-tbody>
            </b-table-simple>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Striped Rows</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <b-table-simple responsive striped class="mb-0 table-centered">
              <b-thead class="table-light">
                <b-tr>
                  <b-th>Customer</b-th>
                  <b-th>Email</b-th>
                  <b-th>Contact No</b-th>
                  <b-th class="text-end">Action</b-th>
                </b-tr>
              </b-thead>
              <b-tbody>
                <b-tr>
                  <b-td
                    ><img
                      :src="avatar3"
                      alt=""
                      class="rounded-circle thumb-md me-1 d-inline"
                    />
                    Aaron Poulin</b-td
                  >
                  <b-td>Aaron@example.com</b-td>
                  <b-td>+21 21547896</b-td>
                  <b-td class="text-end">
                    <a href="#"
                      ><i class="las la-pen text-secondary font-16"></i></a
                    >{{ " " }}
                    <a href="#"
                      ><i class="las la-trash-alt text-secondary font-16"></i
                    ></a>
                  </b-td>
                </b-tr>
                <b-tr>
                  <b-td
                    ><img
                      :src="avatar4"
                      alt=""
                      class="rounded-circle thumb-md me-1 d-inline"
                    />
                    Richard Ali</b-td
                  >
                  <b-td>Richard@example.com</b-td>
                  <b-td>+41 21547896</b-td>
                  <b-td class="text-end">
                    <a href="#"
                      ><i class="las la-pen text-secondary font-16"></i></a
                    >{{ " " }}
                    <a href="#"
                      ><i class="las la-trash-alt text-secondary font-16"></i
                    ></a>
                  </b-td>
                </b-tr>
                <b-tr>
                  <b-td
                    ><img
                      :src="avatar5"
                      alt=""
                      class="rounded-circle thumb-md me-1 d-inline"
                    />
                    Juan Clark</b-td
                  >
                  <b-td>Juan@example.com</b-td>
                  <b-td>+65 21547896</b-td>
                  <b-td class="text-end">
                    <a href="#"
                      ><i class="las la-pen text-secondary font-16"></i></a
                    >{{ " " }}
                    <a href="#"
                      ><i class="las la-trash-alt text-secondary font-16"></i
                    ></a>
                  </b-td>
                </b-tr>
                <b-tr>
                  <b-td
                    ><img
                      :src="avatar6"
                      alt=""
                      class="rounded-circle thumb-md me-1 d-inline"
                    />
                    Albert Hull</b-td
                  >
                  <b-td>Albert@example.com</b-td>
                  <b-td>+88 21547896</b-td>
                  <b-td class="text-end">
                    <a href="#"
                      ><i class="las la-pen text-secondary font-16"></i></a
                    >{{ " " }}
                    <a href="#"
                      ><i class="las la-trash-alt text-secondary font-16"></i
                    ></a>
                  </b-td>
                </b-tr>
              </b-tbody>
            </b-table-simple>
          </b-card-body>
        </b-card>
      </b-col>
      <b-col md="6" lg="6">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Table Head Options</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <b-table-simple responsive class="mb-0">
              <b-thead class="table-light">
                <b-tr>
                  <b-th>#</b-th>
                  <b-th>Name</b-th>
                  <b-th>Email</b-th>
                  <b-th>Access</b-th>
                </b-tr>
              </b-thead>
              <b-tbody>
                <b-tr>
                  <b-th scope="row">1</b-th>
                  <b-td>Mark</b-td>
                  <b-td>XYZ@Example.com</b-td>
                  <b-td
                    ><b-badge
                      :variant="null"
                      class="border border-success text-success"
                    >
                      Business
                    </b-badge></b-td
                  >
                </b-tr>
                <b-tr>
                  <b-th scope="row">2</b-th>
                  <b-td>Jacob</b-td>
                  <b-td>XYZ@Example.com</b-td>
                  <b-td
                    ><b-badge
                      :variant="null"
                      class="border border-warning text-warning"
                    >
                      Personal
                    </b-badge></b-td
                  >
                </b-tr>
                <b-tr>
                  <b-th scope="row">3</b-th>
                  <b-td>Larry</b-td>
                  <b-td>XYZ@Example.com</b-td>
                  <b-td
                    ><b-badge
                      :variant="null"
                      class="border border-danger text-danger"
                    >
                      Disabled
                    </b-badge></b-td
                  >
                </b-tr>
                <b-tr>
                  <b-th scope="row">4</b-th>
                  <b-td>Mark</b-td>
                  <b-td>XYZ@Example.com</b-td>
                  <b-td
                    ><b-badge
                      :variant="null"
                      class="border border-success text-success"
                    >
                      Business
                    </b-badge></b-td
                  >
                </b-tr>
                <b-tr>
                  <b-th scope="row">5</b-th>
                  <b-td>Jacob</b-td>
                  <b-td>XYZ@Example.com</b-td>
                  <b-td
                    ><b-badge
                      :variant="null"
                      class="border border-warning text-warning"
                    >
                      Personal
                    </b-badge></b-td
                  >
                </b-tr>
              </b-tbody>
            </b-table-simple>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Hoverable Rows</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <b-table-simple responsive hover class="mb-0">
              <b-thead class="table-light">
                <b-tr>
                  <b-th>#</b-th>
                  <b-th>First Name</b-th>
                  <b-th>Last Name</b-th>
                  <b-th>Username</b-th>
                </b-tr>
              </b-thead>
              <b-tbody>
                <b-tr>
                  <b-th scope="row">1</b-th>
                  <b-td>Mark</b-td>
                  <b-td>Otto</b-td>
                  <b-td>@mdo</b-td>
                </b-tr>
                <b-tr>
                  <b-th scope="row">2</b-th>
                  <b-td>Jacob</b-td>
                  <b-td>Thornton</b-td>
                  <b-td>@fat</b-td>
                </b-tr>
                <b-tr>
                  <b-th scope="row">3</b-th>
                  <b-td colspan="2">Larry the Bird</b-td>
                  <b-td>@twitter</b-td>
                </b-tr>
              </b-tbody>
            </b-table-simple>
          </b-card-body>
        </b-card>
      </b-col>
      <b-col md="6" lg="6">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Dark Table</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <b-table-simple responsive dark class="mb-0">
              <b-thead>
                <b-tr>
                  <b-th class="rounded-bottom-0">#</b-th>
                  <b-th>First Name</b-th>
                  <b-th>Last Name</b-th>
                  <b-th class="rounded-bottom-0">Username</b-th>
                </b-tr>
              </b-thead>
              <b-tbody>
                <b-tr>
                  <b-th scope="row">1</b-th>
                  <b-td>Mark</b-td>
                  <b-td>Otto</b-td>
                  <b-td>@mdo</b-td>
                </b-tr>
                <b-tr>
                  <b-th scope="row">2</b-th>
                  <b-td>Jacob</b-td>
                  <b-td>Thornton</b-td>
                  <b-td>@fat</b-td>
                </b-tr>
                <b-tr>
                  <b-th scope="row">3</b-th>
                  <b-td>Larry</b-td>
                  <b-td>the Bird</b-td>
                  <b-td>@twitter</b-td>
                </b-tr>
              </b-tbody>
            </b-table-simple>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Contextual Classes</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <b-table-simple responsive="sm" class="mb-0">
              <b-thead class="table-light">
                <b-tr>
                  <b-th scope="col">#</b-th>
                  <b-th scope="col">First Name</b-th>
                  <b-th scope="col">Last Name</b-th>
                  <b-th scope="col">Username</b-th>
                </b-tr>
              </b-thead>
              <b-tbody>
                <b-tr class="table-primary">
                  <b-th scope="row">1</b-th>
                  <b-td class="">Mark</b-td>
                  <b-td class="">Otto</b-td>
                  <b-td class="">@mdo</b-td>
                </b-tr>
                <b-tr class="">
                  <b-th scope="row">2</b-th>
                  <b-td>Jacob</b-td>
                  <b-td>Thornton</b-td>
                  <b-td>@fat</b-td>
                </b-tr>
                <b-tr class="table-secondary">
                  <b-th scope="row">3</b-th>
                  <b-td class="">Larry</b-td>
                  <b-td class="">the Bird</b-td>
                  <b-td class="">@twitter</b-td>
                </b-tr>
                <b-tr class="">
                  <b-th scope="row">4</b-th>
                  <b-td>Mark</b-td>
                  <b-td>Otto</b-td>
                  <b-td>@mdo</b-td>
                </b-tr>
                <b-tr class="table-success">
                  <b-th scope="row">5</b-th>
                  <b-td>Jacob</b-td>
                  <b-td>Thornton</b-td>
                  <b-td>@fat</b-td>
                </b-tr>
                <b-tr class="">
                  <b-th scope="row">6</b-th>
                  <b-td>Larry</b-td>
                  <b-td>the Bird</b-td>
                  <b-td>@twitter</b-td>
                </b-tr>
                <b-tr class="table-info">
                  <b-th scope="row">7</b-th>
                  <b-td>Jacob</b-td>
                  <b-td>Thornton</b-td>
                  <b-td>@fat</b-td>
                </b-tr>
                <b-tr class="">
                  <b-th scope="row">8</b-th>
                  <b-td>Larry</b-td>
                  <b-td>the Bird</b-td>
                  <b-td>@twitter</b-td>
                </b-tr>
                <b-tr class="table-warning">
                  <b-th scope="row">9</b-th>
                  <b-td>Jacob</b-td>
                  <b-td>Thornton</b-td>
                  <b-td>@fat</b-td>
                </b-tr>
                <b-tr class="">
                  <b-th scope="row">10</b-th>
                  <b-td>Larry</b-td>
                  <b-td>the Bird</b-td>
                  <b-td>@twitter</b-td>
                </b-tr>
              </b-tbody>
            </b-table-simple>
          </b-card-body>
        </b-card>
      </b-col>
      <b-col md="6" lg="6">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Captions Table</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <b-table-simple responsive="sm" class="mb-0">
              <caption>
                List of users
              </caption>
              <b-thead class="table-light">
                <b-tr>
                  <b-th scope="col">#</b-th>
                  <b-th scope="col">First Name</b-th>
                  <b-th scope="col">Last Name</b-th>
                  <b-th scope="col">Username</b-th>
                </b-tr>
              </b-thead>
              <b-tbody>
                <b-tr>
                  <b-th scope="row">1</b-th>
                  <b-td>Mark</b-td>
                  <b-td>Otto</b-td>
                  <b-td>@mdo</b-td>
                </b-tr>
                <b-tr>
                  <b-th scope="row">2</b-th>
                  <b-td>Jacob</b-td>
                  <b-td>Thornton</b-td>
                  <b-td>@fat</b-td>
                </b-tr>
                <b-tr>
                  <b-th scope="row">3</b-th>
                  <b-td>Larry</b-td>
                  <b-td>the Bird</b-td>
                  <b-td>@twitter</b-td>
                </b-tr>
              </b-tbody>
            </b-table-simple>
          </b-card-body>
        </b-card>

        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Small Table</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <b-table-simple responsive="sm" small class="mb-0">
              <b-thead class="table-light">
                <b-tr>
                  <b-th scope="col">#</b-th>
                  <b-th scope="col">First Name</b-th>
                  <b-th scope="col">Last Name</b-th>
                  <b-th scope="col">Username</b-th>
                </b-tr>
              </b-thead>
              <b-tbody>
                <b-tr>
                  <b-th scope="row">1</b-th>
                  <b-td>Mark</b-td>
                  <b-td>Otto</b-td>
                  <b-td>@mdo</b-td>
                </b-tr>
                <b-tr>
                  <b-th scope="row">2</b-th>
                  <b-td>Jacob</b-td>
                  <b-td>Thornton</b-td>
                  <b-td>@fat</b-td>
                </b-tr>
                <b-tr>
                  <b-th scope="row">3</b-th>
                  <b-td colspan="2">Larry the Bird</b-td>
                  <b-td>@twitter</b-td>
                </b-tr>
              </b-tbody>
            </b-table-simple>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import { currency } from "@/helpers/constants";

import chatgpt from "@/assets/images/logos/lang-logo/chatgpt.png";
import nextjs from "@/assets/images/logos/lang-logo/nextjs.png";
import reactjs from "@/assets/images/logos/lang-logo/reactjs.png";
import symfony from "@/assets/images/logos/lang-logo/symfony.png";

import avatar3 from "@/assets/images/users/avatar-3.jpg";
import avatar4 from "@/assets/images/users/avatar-4.jpg";
import avatar5 from "@/assets/images/users/avatar-5.jpg";
import avatar6 from "@/assets/images/users/avatar-6.jpg";

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
