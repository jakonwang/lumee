#!/bin/sh
# 每个容器启动时：补齐 storage 目录结构（shared/storage 第一次是空的）、建公共存储软链、缓存配置
set -e
cd /app

for d in app/public framework/cache/data framework/sessions framework/views logs; do
  mkdir -p "storage/$d"
done
[ -L public/storage ] || ln -s /app/storage/app/public public/storage

php artisan config:cache >/dev/null
php artisan route:cache >/dev/null
php artisan view:cache >/dev/null
php artisan event:cache >/dev/null

exec "$@"
