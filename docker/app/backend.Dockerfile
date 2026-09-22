# syntax=docker/dockerfile:1
# 后端固定版本镜像：构建期打包 TS（含 @hof/shared），运行期只含生产依赖。
FROM node:24.14.0-bookworm-slim@sha256:d8e448a56fc63242f70026718378bd4b00f8c82e78d20eefb199224a4d8e33d8 AS build
WORKDIR /repo
ENV CI=true
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml .npmrc ./
COPY packages ./packages
COPY apps ./apps
RUN corepack pnpm install --frozen-lockfile
RUN corepack pnpm --filter @hof/backend build
RUN corepack pnpm deploy --legacy --filter @hof/backend --prod /deploy

FROM node:24.14.0-bookworm-slim@sha256:d8e448a56fc63242f70026718378bd4b00f8c82e78d20eefb199224a4d8e33d8
ENV NODE_ENV=production \
    HOF_CONTENT_MANIFEST=/app/content/release.json \
    HOF_MIGRATIONS_DIR=/app/migrations \
    HOF_HOST=0.0.0.0 \
    HOF_PORT=3000
WORKDIR /app
COPY --from=build /deploy ./
COPY --from=build /repo/apps/backend/dist ./dist
COPY apps/backend/migrations ./migrations
COPY content/release.json ./content/release.json
USER node
EXPOSE 3000
CMD ["node", "dist/main.js"]
