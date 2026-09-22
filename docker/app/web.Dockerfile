# syntax=docker/dockerfile:1
# 同域入口镜像：Caddy 提供前端静态产物并反向代理 /api 到内网后端。
FROM node:24.14.0-bookworm-slim@sha256:d8e448a56fc63242f70026718378bd4b00f8c82e78d20eefb199224a4d8e33d8 AS build
WORKDIR /repo
ENV CI=true
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml .npmrc ./
COPY packages ./packages
COPY apps ./apps
RUN corepack pnpm install --frozen-lockfile
RUN corepack pnpm --filter @hof/frontend build

FROM caddy:2.10.2@sha256:c3d7ee5d2b11f9dc54f947f68a734c84e9c9666c92c88a7f30b9cba5da182adb
COPY docker/app/Caddyfile.serve /etc/caddy/Caddyfile
COPY --from=build /repo/apps/frontend/dist /srv
EXPOSE 80
