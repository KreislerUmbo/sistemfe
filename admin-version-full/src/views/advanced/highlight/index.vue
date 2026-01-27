<template>
  <DefaultLayout>
    <b-row class="justify-content-center">
      <b-col md="6" lg="6">
        <UIComponentCard title="HTML Code">
          <pre
            class="rounded language-html"
          ><code class="language-html">&lt;div class=&quot;card-header&quot;&gt;
  &lt;h4 class=&quot;card-title&quot;&gt;Highlight HTML&lt;/h4&gt;
  &lt;p class=&quot;text-muted mb-0&quot;&gt;Escape code&lt;/p&gt;
&lt;/div&gt;
  </code></pre>
        </UIComponentCard>

        <UIComponentCard title="Css Code">
          <pre
            class="rounded language-css"
          ><code class="language-css">font-family : 'Be Vietnam Pro', sans-serif;
  </code></pre>
        </UIComponentCard>
      </b-col>

      <b-col md="6" lg="6">
        <UIComponentCard title="Javascript Code">
          <div class="">
            <pre
              class="rounded language-javascript"
            ><code class="language-javascript">// Dropdown stop
  var dropdownMenus = document.querySelectorAll('.dropdown-menu.stop');
  dropdownMenus.forEach(function(dropdownMenu) {
      dropdownMenu.addEventListener('click', function(event) {
      event.stopPropagation();
    });
  });
  </code></pre>
          </div>
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
