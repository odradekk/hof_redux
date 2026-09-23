# syntax=docker/dockerfile:1
# 后端固定版本镜像：构建期打包 TS（含 @hof/shared），运行期只含生产依赖。
FROM node:24.14.0-bookworm-slim@sha256:d8e448a56fc63242f70026718378bd4b00f8c82e78d20eefb199224a4d8e33d8 AS build
WORKDIR /repo
ENV CI=true
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml .npmrc ./
COPY packages ./packages
COPY apps ./apps
COPY content ./content
RUN corepack pnpm install --frozen-lockfile
# 内容发布检查：校验失败即中断镜像构建，未验证的内容不得进入运行镜像。
RUN corepack pnpm --filter @hof/content build
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
# 运行镜像只装载已验证的发布快照与当前指针，不装载可编辑源与 Schema；
# 后端经指针加载快照并核对完整性与版本门控。
COPY content/releases ./content/releases
COPY content/current-release ./content/current-release
USER node
EXPOSE 3000
CMD ["node", "dist/main.js"]
