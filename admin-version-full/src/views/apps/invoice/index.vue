<template>
  <DefaultLayout>
    <b-row>
      <b-col cols="12">
        <b-card no-body>
          <b-card-body class="bg-black">
            <b-row>
              <b-col cols="4" class="align-self-center">
                <img
                  :src="logoSm"
                  alt="logo-small"
                  class="logo-sm me-1"
                  height="70"
                />
              </b-col>
              <b-col cols="8" class="text-end align-self-center">
                <h5 class="mb-1 fw-semibold text-white">
                  <span class="text-muted">Invoice:</span> #BBN2351D458
                </h5>
                <h5 class="mb-0 fw-semibold text-white">
                  <span class="text-muted">Issue Date:</span> 20/07/2024
                </h5>
              </b-col>
            </b-row>
          </b-card-body>
          <b-card-body>
            <b-row class="row-cols-3 d-flex justify-content-md-between">
              <b-col md="3" class="d-print-flex align-self-center">
                <div>
                  <b-badge variant="light" class="rounded text-dark"
                    >Invoice to</b-badge
                  >
                  <h5 class="my-1 fw-semibold fs-18">Michael Reyes</h5>
                  <p class="text-muted">@Michael_Reyes|+1 123 456 789</p>
                </div>
              </b-col>
              <b-col md="3" class="d-print-flex align-self-center">
                <div>
                  <address class="fs-13">
                    <strong class="fs-14">Billed To :</strong><br />
                    854 Ave Folsom <br />
                    San Francisco, CA 36925<br />
                    <abbr title="Phone">P:</abbr> (123) 456-7890
                  </address>
                </div>
              </b-col>
              <b-col md="3" class="d-print-flex align-self-center">
                <div>
                  <address class="fs-13">
                    <strong class="fs-14">Shipped To:</strong><br />
                    795 Folsom Ave<br />
                    San Francisco, CA 94107<br />
                    <abbr title="Phone">P:</abbr> (123) 456-7890
                  </address>
                </div>
              </b-col>
            </b-row>

            <b-row>
              <b-col lg="12">
                <b-table-simple
                  responsive
                  bordered
                  class="mb-0 project-invoice"
                >
                  <b-thead class="table-light">
                    <b-tr>
                      <b-th>Project Breakdown</b-th>
                      <b-th>Hours</b-th>
                      <b-th>Rate</b-th>
                      <b-th>Subtotal</b-th>
                    </b-tr>
                  </b-thead>
                  <b-tbody>
                    <b-tr>
                      <b-td>
                        <h5 class="mt-0 mb-1 fs-14">Project Design</h5>
                        <p class="mb-0 text-muted">
                          It is a long established fact that a reader will be
                          distracted.
                        </p>
                      </b-td>
                      <b-td>60</b-td>
                      <b-td>{{ currency }}50</b-td>
                      <b-td>{{ currency }}3000.00</b-td>
                    </b-tr>
                    <b-tr>
                      <b-td>
                        <h5 class="mt-0 mb-1 fs-14">Development</h5>
                        <p class="mb-0 text-muted">
                          It is a long established fact that a reader will be
                          distracted.
                        </p>
                      </b-td>
                      <b-td>100</b-td>
                      <b-td>{{ currency }}50</b-td>
                      <b-td>{{ currency }}5000.00</b-td>
                    </b-tr>
                    <b-tr>
                      <b-td>
                        <h5 class="mt-0 mb-1 fs-14">Testing & Bug Fixing</h5>
                        <p class="mb-0 text-muted">
                          It is a long established fact that a reader will be
                          distracted.
                        </p>
                      </b-td>
                      <b-td>10</b-td>
                      <b-td>{{ currency }}20</b-td>
                      <b-td>{{ currency }}200.00</b-td>
                    </b-tr>

                    <b-tr>
                      <b-td colspan="1" class="border-0"></b-td>
                      <b-td colspan="2" class="border-0 fs-14 text-dark"
                        ><b>Sub Total</b></b-td
                      >
                      <b-td class="border-0 fs-14 text-dark"
                        ><b>{{ currency }}82,000.00</b></b-td
                      >
                    </b-tr>
                    <b-tr>
                      <th colspan="1" class="border-0"></th>
                      <b-td colspan="2" class="border-0 fs-14 text-dark"
                        ><b>Tax Rate</b></b-td
                      >
                      <b-td class="border-0 fs-14 text-dark"
                        ><b>{{ currency }}0.00%</b></b-td
                      >
                    </b-tr>
                    <b-tr>
                      <th colspan="1" class="border-0"></th>
                      <b-td colspan="2" class="border-0 fs-14"
                        ><b>Total</b></b-td
                      >
                      <b-td class="border-0 fs-14"
                        ><b>{{ currency }}82,000.00</b></b-td
                      >
                    </b-tr>
                  </b-tbody>
                </b-table-simple>
              </b-col>
            </b-row>

            <b-row>
              <b-col lg="6">
                <h5 class="mt-4">Terms And Condition :</h5>
                <ul class="ps-3">
                  <li>
                    <small class="fs-12"
                      >All accounts are to be paid within 7 days from receipt of
                      invoice.
                    </small>
                  </li>
                  <li>
                    <small class="fs-12"
                      >To be paid by cheque or credit card or direct payment
                      online.</small
                    >
                  </li>
                  <li>
                    <small class="fs-12">
                      If account is not paid within 7 days the credits details
                      supplied as confirmation of work undertaken will be
                      charged the agreed quoted fee noted above.</small
                    >
                  </li>
                </ul>
              </b-col>
              <b-col lg="6" class="align-self-center">
                <div class="float-none float-md-end" style="width: 30%">
                  <small>Account Manager</small>
                  <div>
                    <img
                      :src="signature"
                      alt=""
                      class="mt-2 mb-1"
                      height="24"
                    />
                  </div>
                  <p class="border-top">Signature</p>
                </div>
              </b-col>
            </b-row>
            <hr />
            <b-row class="d-flex justify-content-center">
              <b-col lg="12" xl="4" class="ms-auto align-self-center">
                <div class="text-center">
                  <small class="fs-12"
                    >Thank you very much for doing business with us.</small
                  >
                </div>
              </b-col>
              <b-col lg="12" xl="4">
                <div class="float-end d-print-none mt-2 mt-md-0">
                  <a href="#" class="btn btn-info" @click="print">Print</a
                  >{{ " " }} <a href="#" class="btn btn-primary">Submit</a
                  >{{ " " }}
                  <a href="#" class="btn btn-danger">Cancel</a>
                </div>
              </b-col>
            </b-row>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import logoSm from "@/assets/images/logo-sm.png";
import signature from "@/assets/images/extra/signature.png";
import { currency } from "@/helpers/constants";

const print = () => {
  window.print();
};

import DefaultLayout from "@/layouts/DefaultLayout.vue";
</script>
