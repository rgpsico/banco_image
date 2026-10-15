import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Em desenvolvimento, /api é encaminhado para a API Laravel (evita CORS).
export default defineConfig({
  plugins: [vue()],
  server: {
    // 'web' = nome do serviço no docker compose. Domínios extras (servidor): VITE_ALLOWED_HOSTS=dominio1,dominio2
    allowedHosts: ['localhost', '127.0.0.1', 'web', ...(process.env.VITE_ALLOWED_HOSTS ? process.env.VITE_ALLOWED_HOSTS.split(',') : [])],
    proxy: {
      '/api': { target: process.env.VITE_API_PROXY || 'http://localhost:8110', changeOrigin: true },
    },
  },
})
