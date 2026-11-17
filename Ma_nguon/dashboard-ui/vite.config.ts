import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { defineConfig } from 'vite'

const base = process.env.NODE_ENV === 'production' ? '/dashboard-ui/' : '/'

export default defineConfig({
  base,
  plugins: [react(), tailwindcss()],
})
