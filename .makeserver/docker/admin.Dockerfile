# Lumée 商家后台：构建 Vue SPA，用 Caddy 提供静态文件
# 构建上下文：lumee 仓库根目录
FROM node:22-alpine AS build
WORKDIR /src
COPY packages ./packages
COPY admin/package.json admin/package-lock.json ./admin/
RUN cd admin && npm ci --no-audit --no-fund
COPY admin ./admin
RUN cd admin && npm run build

FROM caddy:2.10-alpine
COPY --from=build /src/admin/dist /srv
COPY .makeserver/docker/admin.Caddyfile /etc/caddy/Caddyfile
EXPOSE 80
