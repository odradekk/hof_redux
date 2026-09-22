import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";

export default defineConfig({
  plugins: [vue()],
  server: {
    host: true,
    // 经 dev Caddy 同域访问时，HMR 的 ws 需回到外部端口（compose 注入 VITE_HMR_CLIENT_PORT）。
    hmr: process.env.VITE_HMR_CLIENT_PORT ? { clientPort: Number(process.env.VITE_HMR_CLIENT_PORT) } : true,
    proxy: {
      // 不经 Caddy 直接访问 vite 时的开发便利路径；经 Caddy 时请求不会到这里。
      "/api": {
        target: process.env.VITE_DEV_API_TARGET ?? "http://127.0.0.1:3000",
        changeOrigin: false,
      },
    },
  },
});
