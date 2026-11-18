# 自定义域名自动 HTTPS（Caddy on-demand TLS）

商家绑定自己的域名并验证 DNS 后，平台需要为这个域名签发 HTTPS 证书。证书数量不确定、随时增加，所以用 Caddy 的 **on-demand TLS**：某个域名第一次被 HTTPS 访问时，Caddy 先问 Laravel「这个域名能不能签？」，得到允许后再向 Let's Encrypt 申请证书。之后的续期也由 Caddy 自动完成。

## 流程

1. 商家在后台「设置 → 域名」添加 `shop.brand.com`，按提示把 CNAME 指向 `LUMEE_CNAME_TARGET`（根域名用 A 记录指向 `LUMEE_SERVER_IP`）。
2. 商家点「验证」，系统查到 DNS 正确后，把域名标记为已验证。
3. 买家第一次打开 `https://shop.brand.com`：
   - Caddy 请求 `GET http://127.0.0.1:8080/internal/tls/ask?domain=shop.brand.com`。
   - Laravel（`App\Http\Controllers\TlsAskController`）返回 200，Caddy 申请证书，几秒后页面打开。
4. 以后的访问直接使用缓存的证书，到期前 Caddy 会自动续期。

## 哪些域名会被允许

| 域名 | 结果 |
| --- | --- |
| 已验证的自定义域名，且店铺套餐包含自定义域名（试用期、基础版及以上） | 200 |
| `{handle}.{LUMEE_STOREFRONT_DOMAIN}`，且店铺存在 | 200 |
| 平台根域名，以及 `www.` 加平台根域名 | 200 |
| 未验证的域名、已删除的域名、降级到不含自定义域名套餐的店铺、随便指过来的域名 | 404 |

这样别人把任意域名指向你的服务器，也不能让 Caddy 去申请证书，不会耗尽 Let's Encrypt 的频率限制。

## 谁可以调用这个接口

- **本机（127.0.0.1 或 ::1）的请求**：Caddy 和 Laravel 在同一台机器上时，就是这种情况。
- **带 `?token=` 的请求**：token 要等于 `.env` 里的 `LUMEE_TLS_ASK_TOKEN`。Caddy 和 Laravel 不在同一台机器时使用。
- 其他请求一律返回 403。

示例 Caddyfile 还用 `respond /internal/* 404` 挡住了外部访问。因为外部请求经过 Caddy 转发后，来源地址也会变成 127.0.0.1，所以这一步不能省。

## 部署步骤

1. 安装 Caddy 2.7 或更高版本，把 [deploy/Caddyfile](../deploy/Caddyfile) 复制到 `/etc/caddy/Caddyfile`，然后修改：
   - 邮箱
   - 项目路径
   - php-fpm socket
   - 后台域名
2. 在服务器 `.env` 里设置以下几项：
   ```
   LUMEE_STOREFRONT_SCHEME=https
   LUMEE_STOREFRONT_PORT=
   LUMEE_STOREFRONT_DOMAIN=lumee.shop
   LUMEE_CNAME_TARGET=stores.lumee.shop
   LUMEE_SERVER_IP=<服务器公网 IP>
   ```
3. 设置 DNS：
   - `stores.lumee.shop` 的 A 记录指向服务器。
   - `*.lumee.shop` 的 A 记录指向服务器，店铺的免费子域名靠它访问。
4. 防火墙开放 80 和 443 端口：Let's Encrypt 用 HTTP-01 或 TLS-ALPN 验证，需要这两个端口。
5. 执行 `systemctl reload caddy`。

Laravel 已经信任来自本机的反向代理（`bootstrap/app.php` 中的 `trustProxies`），所以在 Caddy 后面生成的链接都是 `https://`。

## 可选：免费子域名使用通配符证书

店铺很多时，可以给 `*.lumee.shop` 申请一张通配符证书，需要使用 DNS-01 验证和带 DNS 插件的 Caddy 构建，例如 `caddy-dns/cloudflare`。这样新店铺第一次访问时不用等待签发证书。

自定义域名仍然使用 on-demand 方式。
