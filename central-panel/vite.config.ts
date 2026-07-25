import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    // Puerto fijo y distinto del default de admin-start-kit (5173) para poder
    // correr ambos proyectos al mismo tiempo sin colisión.
    port: 5174,
  },
});
