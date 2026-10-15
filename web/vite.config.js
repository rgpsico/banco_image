import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Em desenvolvimento, /api é encaminhado para a API Laravel (evita CORS).
export default defineConfig({
  plugins: [vue()],
  server: {
    allowedHosts: ['localhost', '127.0.0.1', 'web'], // 'web' = nome do serviço no docker compose
    proxy: {
      '/api': { target: process.env.VITE_API_PROXY || 'http://localhost:8110', changeOrigin: true },
    },
  },
})
