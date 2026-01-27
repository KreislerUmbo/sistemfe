<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Tabs">
          <b-tabs>
            <b-tab title="Home" active>
              <p class="mb-0 text-muted p-3">
                Raw denim you probably haven't heard of them jean shorts Austin.
              </p>
            </b-tab>
            <b-tab title="Profile">
              <p class="mb-0 text-muted p-3">
                Food truck fixie locavore, accusamus mcsweeney's single-origin
                coffee squid.
              </p>
            </b-tab>
            <b-tab title="Settings">
              <p class="mb-0 text-muted p-3">
                Trust fund seitan letterpress, keytar raw denim keffiyeh etsy.
              </p>
            </b-tab>
          </b-tabs>
        </UIComponentCard>
      </b-col>

      <b-col md="6" lg="6">     
        <UIComponentCard title="Pills">
          <b-tabs pills justified>
            <b-tab title="Home" active>
              <p class="mb-0 text-muted p-3">
                Raw denim you probably haven't heard of them jean shorts Austin.
              </p>
            </b-tab>
            <b-tab title="Profile">
              <p class="mb-0 text-muted p-3">
                Food truck fixie locavore, accusamus mcsweeney's single-origin
                coffee squid.
              </p>
            </b-tab>
            <b-tab title="Settings">
              <p class="mb-0 text-muted p-3">
                Trust fund seitan letterpress, keytar raw denim keffiyeh etsy.
              </p>
            </b-tab>
          </b-tabs>
        </UIComponentCard>
      </b-col>
    </b-row>

    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Vertical Pills">
          <b-row>
            <b-tabs
              content-class="mo-mt-2"
              nav-class="flex-column text-center"
              nav-wrapper-class="col-lg-3 col-sm-4"
              pills
              vertical
              justified
            >
              <b-tab title="Home" id="home" active>
                <p class="text-muted mb-0">
                  Trust fund seitan letterpress, keytar raw denim keffiyeh etsy
                  art party before they sold out master cleanse gluten-free
                  squid scenester freegan cosby sweater.
                </p>
              </b-tab>
              <b-tab title="Profile" id="profile">
                <p class="text-muted mb-0">
                  Food truck fixie locavore, accusamus mcsweeney's marfa nulla
                  single-origin coffee squid. Exercitation +1 labore velit, blog
                  sartorial PBR leggings next level wes anderson.
                </p>
              </b-tab>
              <b-tab title="Settings" id="settings">
                <p class="text-muted mb-0">
                  Exercitation commodo enim craft beer mlkshk +1 labore velit,
                  blog sartorial PBR leggings next level wes anderson artisan
                  four loko farm-to-table craft beer twee. Qui photo booth
                  letterpress.
                </p>
              </b-tab>
            </b-tabs>
          </b-row>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Custom Tabs">
          <b-tabs nav-wrapper-class="nav-tabs-custom text-center">
            <b-tab active>
              <template #title>
                <i class="la la-home d-block"></i>Home
              </template>
              <p class="mb-0 text-muted p-3">
                Raw denim you probably haven't heard of them jean shorts Austin.
              </p>
            </b-tab>
            <b-tab>
              <template #title>
                <i class="la la-calendar d-block"></i>Events
              </template>
              <p class="mb-0 text-muted p-3">
                Food truck fixie locavore, accusamus mcsweeney's marfa nulla.
              </p>
            </b-tab>
            <b-tab>
              <template #title>
                <i class="la la-cog d-block"></i>Settings
              </template>
              <p class="mb-0 text-muted p-3">
                Trust fund seitan letterpress, keytar raw denim keffiyeh etsy.
              </p>
            </b-tab>
          </b-tabs>
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Collapse">
          <p class="d-flex flex-wrap gap-2">
            <a class="btn btn-primary" v-b-toggle.collapse-1>
              Link with href
            </a>
            <b-button variant="secondary" type="button" v-b-toggle.collapse-1>
              Button with data-target
            </b-button>
          </p>
          <b-collapse id="collapse-1" visible>
            <b-card-body class="mb-0 border-dashed border-theme-color rounded">
              <p class="mb-0 text-muted">
                Anim pariatur cliche reprehenderit, enim eiusmod high life
                accusamus terry richardson ad squid. Nihil anim keffiyeh
                helvetica, craft beer labore wes anderson cred nesciunt sapiente
                ea proident.
              </p>
            </b-card-body>
          </b-collapse>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Flush Examplee">
          <b-accordion flush>
            <b-accordion-item
              title="Accordion Item #1"
              header-class="m-0"
              button-class="fw-semibold"
            >
              Anim pariatur cliche reprehenderit, enim eiusmod high life
              accusamus terry richardson ad squid. 3 wolf moon officia aute, non
              cupidatat skateboard dolor brunch. Food truck quinoa nesciunt
              laborum eiusmod. Brunch 3 wolf moon tempor, sunt aliqua put a bird
              on it squid single-origin coffee nulla assumenda shoreditch et.
              Nihil anim keffiyeh helvetica, craft beer labore wes anderson cred
              nesciunt sapiente ea proident. Ad vegan excepteur butcher vice
              lomo. Leggings occaecat craft beer farm-to-table, raw denim
              aesthetic synth nesciunt you probably haven't heard of them
              accusamus labore sustainable VHS.
            </b-accordion-item>
            <b-accordion-item
              title="Accordion Item #2"
              header-class="m-0"
              button-class="fw-semibold"
            >
              Anim pariatur cliche reprehenderit, enim eiusmod high life
              accusamus terry richardson ad squid. 3 wolf moon officia aute, non
              cupidatat skateboard dolor brunch. Food truck quinoa nesciunt
              laborum eiusmod. Brunch 3 wolf moon tempor, sunt aliqua put a bird
              on it squid single-origin coffee nulla assumenda shoreditch et.
              Nihil anim keffiyeh helvetica, craft beer labore wes anderson cred
              nesciunt sapiente ea proident. Ad vegan excepteur butcher vice
              lomo. Leggings occaecat craft beer farm-to-table, raw denim
              aesthetic synth nesciunt you probably haven't heard of them
              accusamus labore sustainable VHS.
            </b-accordion-item>
            <b-accordion-item
              title="Accordion Item #3"
              header-class="m-0"
              button-class="fw-semibold"
            >
              Anim pariatur cliche reprehenderit, enim eiusmod high life
              accusamus terry richardson ad squid. 3 wolf moon officia aute, non
              cupidatat skateboard dolor brunch. Food truck quinoa nesciunt
              laborum eiusmod. Brunch 3 wolf moon tempor, sunt aliqua put a bird
              on it squid single-origin coffee nulla assumenda shoreditch et.
              Nihil anim keffiyeh helvetica, craft beer labore wes anderson cred
              nesciunt sapiente ea proident. Ad vegan excepteur butcher vice
              lomo. Leggings occaecat craft beer farm-to-table, raw denim
              aesthetic synth nesciunt you probably haven't heard of them
              accusamus labore sustainable VHS.
            </b-accordion-item>
          </b-accordion>
        </UIComponentCard>
      </b-col>
    </b-row>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="Accordion Example">
          <b-accordion>
            <b-accordion-item
              title="Accordion Item #1"
              header-class="m-0"
              button-class="fw-semibold"
              visible
            >
              <strong>This is the first item's accordion body.</strong> It is
              hidden by default, until the collapse plugin adds the appropriate
              classes that we use to style each element. These classes control
              the overall appearance, as well as the showing and hiding via CSS
              transitions. You can modify any of this with custom CSS or
              overriding our default variables. It's also worth noting that just
              about any HTML can go within the <code>.accordion-body</code>,
              though the transition does limit overflow.
            </b-accordion-item>
            <b-accordion-item
              title="Accordion Item #2"
              header-class="m-0"
              button-class="fw-semibold"
            >
              <strong>This is the second item's accordion body.</strong> It is
              hidden by default, until the collapse plugin adds the appropriate
              classes that we use to style each element. These classes control
              the overall appearance, as well as the showing and hiding via CSS
              transitions. You can modify any of this with custom CSS or
              overriding our default variables. It's also worth noting that just
              about any HTML can go within the <code>.accordion-body</code>,
              though the transition does limit overflow.
            </b-accordion-item>
            <b-accordion-item
              title="Accordion Item #3"
              header-class="m-0"
              button-class="fw-semibold"
            >
              <strong>This is the third item's accordion body.</strong> It is
              hidden by default, until the collapse plugin adds the appropriate
              classes that we use to style each element. These classes control
              the overall appearance, as well as the showing and hiding via CSS
              transitions. You can modify any of this with custom CSS or
              overriding our default variables. It's also worth noting that just
              about any HTML can go within the <code>.accordion-body</code>,
              though the transition does limit overflow.
            </b-accordion-item>
          </b-accordion>
        </UIComponentCard>
      </b-col>
      <b-col md="6" lg="6">
        <UIComponentCard title="Always open">
          <b-accordion free>
            <b-accordion-item
              title="Accordion Item #1"
              header-class="m-0"
              button-class="fw-semibold"
              visible
            >
              <strong>This is the first item's accordion body.</strong> It is
              hidden by default, until the collapse plugin adds the appropriate
              classes that we use to style each element. These classes control
              the overall appearance, as well as the showing and hiding via CSS
              transitions. You can modify any of this with custom CSS or
              overriding our default variables. It's also worth noting that just
              about any HTML can go within the <code>.accordion-body</code>,
              though the transition does limit overflow.
            </b-accordion-item>
            <b-accordion-item
              title="Accordion Item #2"
              header-class="m-0"
              button-class="fw-semibold"
            >
              <strong>This is the second item's accordion body.</strong> It is
              hidden by default, until the collapse plugin adds the appropriate
              classes that we use to style each element. These classes control
              the overall appearance, as well as the showing and hiding via CSS
              transitions. You can modify any of this with custom CSS or
              overriding our default variables. It's also worth noting that just
              about any HTML can go within the <code>.accordion-body</code>,
              though the transition does limit overflow.
            </b-accordion-item>
            <b-accordion-item
              title="Accordion Item #3"
              header-class="m-0"
              button-class="fw-semibold"
            >
              <strong>This is the third item's accordion body.</strong> It is
              hidden by default, until the collapse plugin adds the appropriate
              classes that we use to style each element. These classes control
              the overall appearance, as well as the showing and hiding via CSS
              transitions. You can modify any of this with custom CSS or
              overriding our default variables. It's also worth noting that just
              about any HTML can go within the <code>.accordion-body</code>,
              though the transition does limit overflow.
            </b-accordion-item>
          </b-accordion>
        </UIComponentCard>
      </b-col>
    </b-row>
  </DefaultLayout>
</template>
<script setup lang="ts">
import DefaultLayout from "@/layouts/DefaultLayout.vue";
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
