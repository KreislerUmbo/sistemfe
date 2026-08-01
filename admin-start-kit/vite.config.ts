import { fileURLToPath, URL } from 'node:url';

import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import Components from 'unplugin-vue-components/vite';
import { BootstrapVueNextResolver } from 'bootstrap-vue-next';

// https://vitejs.dev/config/
export default defineConfig({
  base: "/",
  server: {
    host: true,
    allowedHosts: [
      'agencia-demo.sistemafe.test',
      'umbo.sistemafe.test',
      '.sistemafe.test',   // wildcard: cubre cualquier subdominio nuevo que agregues después, sin tener que volver a tocar este archivo
    ],
  },
  plugins: [
    vue(),
    Components({
      resolvers: [BootstrapVueNextResolver()],
    }),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  }
});
