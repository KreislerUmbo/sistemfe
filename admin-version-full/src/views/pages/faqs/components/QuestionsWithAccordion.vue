<template>
  <b-col md="6" lg="6">
    <UIComponentCard title="Questions With Accordion">
      <b-accordion>
        <b-accordion-item title="What is Rizz?" header-class="m-0" visible>
          <strong>This is the first item's accordion body.</strong> It is hidden
          by default, until the collapse plugin adds the appropriate classes
          that we use to style each element. These classes control the overall
          appearance, as well as the showing and hiding via CSS transitions. You
          can modify any of this with custom CSS or overriding our default
          variables. It's also worth noting that just about any HTML can go
          within the <code>.accordion-body</code>, though the transition does
          limit overflow.
        </b-accordion-item>
        <b-accordion-item title="How buy Rizz on coin?" header-class="m-0">
          <strong>This is the second item's accordion body.</strong> It is
          hidden by default, until the collapse plugin adds the appropriate
          classes that we use to style each element. These classes control the
          overall appearance, as well as the showing and hiding via CSS
          transitions. You can modify any of this with custom CSS or overriding
          our default variables. It's also worth noting that just about any HTML
          can go within the <code>.accordion-body</code>, though the transition
          does limit overflow.
        </b-accordion-item>
        <b-accordion-item
          title="What cryptocurrency can i use to buy Rizz?"
          header-class="m-0"
        >
          <strong>This is the third item's accordion body.</strong> It is hidden
          by default, until the collapse plugin adds the appropriate classes
          that we use to style each element. These classes control the overall
          appearance, as well as the showing and hiding via CSS transitions. You
          can modify any of this with custom CSS or overriding our default
          variables. It's also worth noting that just about any HTML can go
          within the <code>.accordion-body</code>, though the transition does
          limit overflow.
        </b-accordion-item>
      </b-accordion>
    </UIComponentCard>
  </b-col>
</template>
<script setup lang="ts">
import UIComponentCard from "@/components/UIComponentCard.vue";
</script>
