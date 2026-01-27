<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <b-row class="justify-content-center">
          <b-col md="6" lg="6">
            <b-card no-body>
              <b-card-body>
                <span class="float-end text-muted fw-normal">Normal / 400</span>
                <h1 class="display-4 fw-normal mt-0 mb-4">Aa</h1>
                <h5 class="mb-0 fw-normal">Rizz</h5>
              </b-card-body>
            </b-card>
          </b-col>
          <b-col md="6" lg="6">
            <b-card no-body>
              <b-card-body>
                <span class="float-end text-muted fw-bold">Bold / 700</span>
                <h1 class="display-4 fw-bold mt-0 mb-4">Aa</h1>
                <h5 class="mb-0 fw-bold">Rizz</h5>
              </b-card-body>
            </b-card>
          </b-col>
        </b-row>
      </b-col>
      <b-col md="6" lg="6">
        <b-card no-body>
          <b-card-body>
            <pre
              class="rounded"
            ><code class="language-css">font-family : 'Be Vietnam Pro', sans-serif;</code></pre>
          </b-card-body>
        </b-card>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Heading">
          <h1>h1. Bootstrap heading</h1>
          <h2>h2. Bootstrap heading</h2>
          <h3>h3. Bootstrap heading</h3>
          <h4>h4. Bootstrap heading</h4>
          <h5>h5. Bootstrap heading</h5>
          <h6>h6. Bootstrap heading</h6>
        </UIComponentCard>

        <UIComponentCard title="Lead">
          <p class="lead mb-0">
            Vivamus sagittis lacus vel augue laoreet rutrum faucibus dolor
            auctor.
          </p>
        </UIComponentCard>

        <UIComponentCard title="Inline text elements">
          <p>You can use the mark tag to <mark>highlight</mark> text.</p>
          <p>
            <del>This line of text is meant to be treated as deleted text.</del>
          </p>
          <p>
            <s
              >This line of text is meant to be treated as no longer
              accurate.</s
            >
          </p>
          <p>
            <ins
              >This line of text is meant to be treated as an addition to the
              document.</ins
            >
          </p>
          <p><u>This line of text will render as underlined</u></p>
          <p>
            <small
              >This line of text is meant to be treated as fine print.</small
            >
          </p>
          <p><strong>This line rendered as bold text.</strong></p>
          <p class="mb-0"><em>This line rendered as italicized text.</em></p>
        </UIComponentCard>

        <UIComponentCard title="Unorder List">
          <ul class="mb-0">
            <li>Integer molestie lorem at massa</li>
            <li>
              Nulla volutpat aliquam velit
              <ul>
                <li>Phasellus iaculis neque</li>
                <li>Purus sodales ultricies</li>
                <li>Vestibulum laoreet porttitor sem</li>
              </ul>
            </li>
            <li>Faucibus porta lacus fringilla vel</li>
          </ul>
        </UIComponentCard>

        <UIComponentCard title="Order List">
          <ol class="mb-0">
            <li>Integer molestie lorem at massa</li>
            <li>Nulla volutpat aliquam velit</li>
            <li>Phasellus iaculis neque</li>
            <li>Purus sodales ultricies</li>
            <li>Vestibulum laoreet porttitor sem</li>
            <li>Faucibus porta lacus fringilla vel</li>
          </ol>
        </UIComponentCard>

        <UIComponentCard title="Unstyleed List">
          <ul class="mb-0 list-unstyled">
            <li>Integer molestie lorem at massa</li>
            <li>Nulla volutpat aliquam velit</li>
            <li>Phasellus iaculis neque</li>
            <li>Purus sodales ultricies</li>
            <li>Vestibulum laoreet porttitor sem</li>
            <li>Faucibus porta lacus fringilla vel</li>
          </ul>
        </UIComponentCard>

        <UIComponentCard title="Inline List">
          <ul class="list-inline">
            <li class="list-inline-item">
              <i class="mdi mdi-circle-outline font-13 text-success me-1"></i
              >Lorem ipsum
            </li>
            <li class="list-inline-item">
              <i class="mdi mdi-circle-outline font-13 text-success me-1"></i
              >Phasellus iaculis
            </li>
            <li class="list-inline-item">
              <i class="mdi mdi-circle-outline font-13 text-success me-1"></i
              >Nulla volutpat
            </li>
            <li class="list-inline-item">
              <i class="mdi mdi-circle-outline font-13 text-success me-1"></i
              >Lorem ipsum
            </li>
            <li class="list-inline-item">
              <i class="mdi mdi-circle-outline font-13 text-success me-1"></i
              >Phasellus iaculis
            </li>
            <li class="list-inline-item">
              <i class="mdi mdi-circle-outline font-13 text-success me-1"></i
              >Nulla volutpat
            </li>
            <li class="list-inline-item">
              <i class="mdi mdi-circle-outline font-13 text-success me-1"></i
              >Lorem ipsum
            </li>
            <li class="list-inline-item">
              <i class="mdi mdi-circle-outline font-13 text-success me-1"></i
              >Phasellus iaculis
            </li>
            <li class="list-inline-item">
              <i class="mdi mdi-circle-outline font-13 text-success me-1"></i
              >Nulla volutpat
            </li>
          </ul>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Display Heading">
          <h1 class="display-1">Display 1</h1>
          <h1 class="display-2">Display 2</h1>
          <h1 class="display-3">Display 3</h1>
          <h1 class="display-4">Display 4</h1>
        </UIComponentCard>

        <UIComponentCard title="Blockquote">
          <blockquote class="blockquote">
            <p>
              Lorem ipsum dolor sit amet, consectetur adipiscing elit. Integer
              posuere erat a ante.
            </p>
            <footer class="blockquote-footer font-14">
              Someone famous in <cite title="Source Title">Source Title</cite>
            </footer>
          </blockquote>

          <blockquote class="blockquote blockquote-reverse">
            <p>
              Lorem ipsum dolor sit amet, consectetur adipiscing elit. Integer
              posuere erat a ante.
            </p>
            <footer class="blockquote-footer font-14">
              Someone famous in <cite title="Source Title">Source Title</cite>
            </footer>
          </blockquote>

          <blockquote class="blockquote blockquote-reverse mb-0">
            <p>
              Lorem ipsum dolor sit amet, consectetur adipiscing elit. Integer
              posuere erat a ante.
            </p>
            <footer class="blockquote-footer font-14 text-right">
              Someone famous in <cite title="Source Title">Source Title</cite>
            </footer>
          </blockquote>
        </UIComponentCard>

        <UIComponentCard title="Colors">
          <p class="text-primary">.text-primary</p>
          <p class="text-secondary">.text-secondary</p>
          <p class="text-success">.text-success</p>
          <p class="text-danger">.text-danger</p>
          <p class="text-warning">.text-warning</p>
          <p class="text-info">.text-info</p>
          <p class="text-light bg-dark">.text-light</p>
          <p class="text-dark">.text-dark</p>
          <p class="text-body">.text-body</p>
          <p class="text-muted">.text-muted</p>
          <p class="text-white bg-black">.text-white</p>
          <p class="text-black-50">.text-black-50</p>
          <p class="text-white-50 bg-black">.text-white-50</p>
        </UIComponentCard>

        <UIComponentCard title="Description List Alignment">
          <dl class="row mb-0">
            <dt class="col-sm-3">Description lists</dt>
            <dd class="col-sm-9">
              A description list is perfect for defining terms.
            </dd>

            <dt class="col-sm-3">Euismod</dt>
            <dd class="col-sm-9">
              Vestibulum id ligula porta felis euismod semper eget lacinia odio
              sem nec elit.
            </dd>
            <dd class="col-sm-9 offset-sm-3">
              Donec id elit non mi porta gravida at eget metus.
            </dd>

            <dt class="col-sm-3">Malesuada porta</dt>
            <dd class="col-sm-9">
              Etiam porta sem malesuada magna mollis euismod.
            </dd>

            <dt class="col-sm-3 text-truncate">Truncated term is truncated</dt>
            <dd class="col-sm-9">
              Fusce dapibus, tellus ac cursus commodo, tortor mauris condimentum
              nibh, ut fermentum massa justo sit amet risus.
            </dd>

            <dt class="col-sm-3">Nesting</dt>
            <dd class="col-sm-9 mb-0">
              <dl class="row mb-0">
                <dt class="col-sm-4">Nested definition list</dt>
                <dd class="col-sm-8">
                  Aenean posuere, tortor sed cursus feugiat, nunc augue blandit
                  nunc.
                </dd>
              </dl>
            </dd>
          </dl>
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import * as Prism from "prismjs";
import { onMounted } from "vue";
onMounted(() => {
  setTimeout(() => {
    Prism.highlightAll();
  });
});

import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
