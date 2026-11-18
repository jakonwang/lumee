# Lumée 后端镜像：FrankenPHP（Caddy + PHP 8.2）直接对外提供 HTTP，前面由入口 Caddy 转发
# 构建上下文：lumee 仓库根目录

# 1. 前台样式（backend 的 Vite / Tailwind）
FROM node:22-alpine AS assets
WORKDIR /src
COPY packages ./packages
COPY backend/package.json ./backend/
RUN cd backend && npm install --no-audit --no-fund
COPY backend ./backend
RUN cd backend && npm run build

# 2. Composer 依赖
FROM composer:2 AS vendor
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY backend ./
RUN composer dump-autoload --optimize --no-dev --no-scripts

# 3. 运行镜像
FROM dunglas/frankenphp:1-php8.2-alpine
RUN install-php-extensions pdo_mysql gd intl zip bcmath opcache pcntl exif
WORKDIR /app
COPY --from=vendor /app /app
COPY --from=assets /src/backend/public/build /app/public/build
COPY .makeserver/docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY .makeserver/docker/frankenphp.Caddyfile /etc/ms/Caddyfile
COPY .makeserver/docker/app-entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint \
 && rm -f .env public/storage \
 && php artisan package:discover --ansi
ENV APP_ENV=production
EXPOSE 80
ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/ms/Caddyfile", "--adapter", "caddyfile"]
