<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col cols="12">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Customers Details</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <div class="table-responsive m-0">
              <table class="table datatable" id="datatable_1">
                <thead class="table-light">
                  <tr>
                    <th style="width: 16px">
                      <div class="form-check mb-0 ms-n1">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="select-all"
                          id="select-all"
                        />
                      </div>
                    </th>
                    <th>Name</th>
                    <th>Ext.</th>
                    <th>City</th>
                    <th data-type="date" data-format="YYYY/DD/MM">
                      Start Date
                    </th>
                    <th>Completion</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td style="width: 16px">
                      <div class="form-check">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="check"
                          id="customCheck1"
                        />
                      </div>
                    </td>
                    <td>Unity Pugh</td>
                    <td>9958</td>
                    <td>Curicó</td>
                    <td>2005/02/11</td>
                    <td>37%</td>
                  </tr>
                  <tr>
                    <td style="width: 16px">
                      <div class="form-check">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="check"
                          id="customCheck1"
                        />
                      </div>
                    </td>
                    <td>Theodore Duran</td>
                    <td>8971</td>
                    <td>Dhanbad</td>
                    <td>1999/04/07</td>
                    <td>97%</td>
                  </tr>
                  <tr>
                    <td style="width: 16px">
                      <div class="form-check">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="check"
                          id="customCheck1"
                        />
                      </div>
                    </td>
                    <td>Kylie Bishop</td>
                    <td>3147</td>
                    <td>Norman</td>
                    <td>2005/09/08</td>
                    <td>63%</td>
                  </tr>
                  <tr>
                    <td style="width: 16px">
                      <div class="form-check">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="check"
                          id="customCheck1"
                        />
                      </div>
                    </td>
                    <td>Willow Gilliam</td>
                    <td>3497</td>
                    <td>Amqui</td>
                    <td>2009/29/11</td>
                    <td>30%</td>
                  </tr>
                  <tr>
                    <td style="width: 16px">
                      <div class="form-check">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="check"
                          id="customCheck1"
                        />
                      </div>
                    </td>
                    <td>Blossom Dickerson</td>
                    <td>5018</td>
                    <td>Kempten</td>
                    <td>2006/11/09</td>
                    <td>17%</td>
                  </tr>
                  <tr>
                    <td style="width: 16px">
                      <div class="form-check">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="check"
                          id="customCheck1"
                        />
                      </div>
                    </td>
                    <td>Elliott Snyder</td>
                    <td>3925</td>
                    <td>Enines</td>
                    <td>2006/03/08</td>
                    <td>57%</td>
                  </tr>
                  <tr>
                    <td style="width: 16px">
                      <div class="form-check">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="check"
                          id="customCheck1"
                        />
                      </div>
                    </td>
                    <td>Castor Pugh</td>
                    <td>9488</td>
                    <td>Neath</td>
                    <td>2014/23/12</td>
                    <td>93%</td>
                  </tr>
                  <tr>
                    <td style="width: 16px">
                      <div class="form-check">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="check"
                          id="customCheck1"
                        />
                      </div>
                    </td>
                    <td>Pearl Carlson</td>
                    <td>6231</td>
                    <td>Cobourg</td>
                    <td>2014/31/08</td>
                    <td>100%</td>
                  </tr>
                  <tr>
                    <td style="width: 16px">
                      <div class="form-check">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="check"
                          id="customCheck1"
                        />
                      </div>
                    </td>
                    <td>Deirdre Bridges</td>
                    <td>1579</td>
                    <td>Eberswalde-Finow</td>
                    <td>2014/26/08</td>
                    <td>44%</td>
                  </tr>
                  <tr>
                    <td style="width: 16px">
                      <div class="form-check">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="check"
                          id="customCheck1"
                        />
                      </div>
                    </td>
                    <td>Daniel Baldwin</td>
                    <td>6095</td>
                    <td>Moircy</td>
                    <td>2000/11/01</td>
                    <td>33%</td>
                  </tr>
                  <tr>
                    <td style="width: 16px">
                      <div class="form-check">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="check"
                          id="customCheck1"
                        />
                      </div>
                    </td>
                    <td>Pearl Carlson</td>
                    <td>6231</td>
                    <td>Cobourg</td>
                    <td>2014/31/08</td>
                    <td>100%</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col cols="12">
        <b-card no-body>
          <b-card-header>
            <b-row class="align-items-center">
              <b-col>
                <b-card-title>Export Table</b-card-title>
              </b-col>
            </b-row>
          </b-card-header>
          <b-card-body class="pt-0">
            <div class="table-responsive m-0">
              <table class="table datatable" id="datatable_2">
                <thead class="">
                  <tr>
                    <th style="width: 16px">
                      <div class="form-check mb-0 ms-n1">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="select-all"
                          id="select-all"
                        />
                      </div>
                    </th>
                    <th>Name</th>
                    <th>Ext.</th>
                    <th>City</th>
                    <th data-type="date" data-format="YYYY/DD/MM">
                      Start Date
                    </th>
                    <th>Completion</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td style="width: 16px">
                      <div class="form-check">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="check"
                          id="customCheck1"
                        />
                      </div>
                    </td>
                    <td>Unity Pugh</td>
                    <td>9958</td>
                    <td>Curicó</td>
                    <td>2005/02/11</td>
                    <td>37%</td>
                  </tr>
                  <tr>
                    <td style="width: 16px">
                      <div class="form-check">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="check"
                          id="customCheck1"
                        />
                      </div>
                    </td>
                    <td>Theodore Duran</td>
                    <td>8971</td>
                    <td>Dhanbad</td>
                    <td>1999/04/07</td>
                    <td>97%</td>
                  </tr>
                  <tr>
                    <td style="width: 16px">
                      <div class="form-check">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="check"
                          id="customCheck1"
                        />
                      </div>
                    </td>
                    <td>Kylie Bishop</td>
                    <td>3147</td>
                    <td>Norman</td>
                    <td>2005/09/08</td>
                    <td>63%</td>
                  </tr>
                  <tr>
                    <td style="width: 16px">
                      <div class="form-check">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="check"
                          id="customCheck1"
                        />
                      </div>
                    </td>
                    <td>Willow Gilliam</td>
                    <td>3497</td>
                    <td>Amqui</td>
                    <td>2009/29/11</td>
                    <td>30%</td>
                  </tr>
                  <tr>
                    <td style="width: 16px">
                      <div class="form-check">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="check"
                          id="customCheck1"
                        />
                      </div>
                    </td>
                    <td>Blossom Dickerson</td>
                    <td>5018</td>
                    <td>Kempten</td>
                    <td>2006/11/09</td>
                    <td>17%</td>
                  </tr>
                  <tr>
                    <td style="width: 16px">
                      <div class="form-check">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="check"
                          id="customCheck1"
                        />
                      </div>
                    </td>
                    <td>Elliott Snyder</td>
                    <td>3925</td>
                    <td>Enines</td>
                    <td>2006/03/08</td>
                    <td>57%</td>
                  </tr>
                  <tr>
                    <td style="width: 16px">
                      <div class="form-check">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="check"
                          id="customCheck1"
                        />
                      </div>
                    </td>
                    <td>Castor Pugh</td>
                    <td>9488</td>
                    <td>Neath</td>
                    <td>2014/23/12</td>
                    <td>93%</td>
                  </tr>
                  <tr>
                    <td style="width: 16px">
                      <div class="form-check">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="check"
                          id="customCheck1"
                        />
                      </div>
                    </td>
                    <td>Pearl Carlson</td>
                    <td>6231</td>
                    <td>Cobourg</td>
                    <td>2014/31/08</td>
                    <td>100%</td>
                  </tr>
                  <tr>
                    <td style="width: 16px">
                      <div class="form-check">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="check"
                          id="customCheck1"
                        />
                      </div>
                    </td>
                    <td>Deirdre Bridges</td>
                    <td>1579</td>
                    <td>Eberswalde-Finow</td>
                    <td>2014/26/08</td>
                    <td>44%</td>
                  </tr>
                  <tr>
                    <td style="width: 16px">
                      <div class="form-check">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="check"
                          id="customCheck1"
                        />
                      </div>
                    </td>
                    <td>Daniel Baldwin</td>
                    <td>6095</td>
                    <td>Moircy</td>
                    <td>2000/11/01</td>
                    <td>33%</td>
                  </tr>
                  <tr>
                    <td style="width: 16px">
                      <div class="form-check">
                        <input
                          type="checkbox"
                          class="form-check-input"
                          name="check"
                          id="customCheck1"
                        />
                      </div>
                    </td>
                    <td>Pearl Carlson</td>
                    <td>6231</td>
                    <td>Cobourg</td>
                    <td>2014/31/08</td>
                    <td>100%</td>
                  </tr>
                </tbody>
              </table>

              <div class="d-flex flex-wrap gap-2 mt-2">
                <b-button variant="primary" size="sm" id="button-csv"
                  >Export CSV</b-button
                >
                <b-button variant="primary" size="sm" id="button-sql"
                  >Export SQL</b-button
                >
                <b-button variant="primary" size="sm" id="button-txt"
                  >Export TXT</b-button
                >
                <b-button variant="primary" size="sm" id="button-json"
                  >Export JSON</b-button
                >
              </div>
            </div>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import { onMounted } from "vue";
import {
  DataTable,
  exportCSV,
  exportSQL,
  exportTXT,
  exportJSON,
} from "simple-datatables";

import DefaultLayout from "@/layouts/DefaultLayout.vue";

onMounted(() => {
  new DataTable("#datatable_1", {
    searchable: true,
    fixedHeight: false,
  });

  const table2 = new DataTable("#datatable_2");

  const csv = document.getElementById("button-csv");
  if (csv) {
    csv.addEventListener("click", () => {
      exportCSV(table2, {
        download: true,
        lineDelimiter: "\n\n",
        columnDelimiter: ";",
      });
    });
  }

  const sql = document.getElementById("button-sql");
  if (sql) {
    sql.addEventListener("click", () => {
      exportSQL(table2, {
        download: true,
        tableName: "export_table",
      });
    });
  }

  const txt = document.getElementById("button-txt");
  if (txt) {
    txt.addEventListener("click", () => {
      exportTXT(table2, {
        download: true,
      });
    });
  }

  const json = document.getElementById("button-json");
  if (json) {
    json.addEventListener("click", () => {
      exportJSON(table2, {
        download: true,
        // escapeHTML: true,
        space: 3,
      });
    });
  }
});
</script>
