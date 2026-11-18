# Lumée 开放 API（v1）

接口地址：`https://<平台域名>/api/v1`，请求和响应都是 JSON，错误信息为英文。

## 认证

在后台「设置 → 开发者」创建访问令牌，选择需要的权限。令牌只在创建时显示一次，平台只保存它的哈希值。调用时放在请求头里：

```bash
curl -H "Authorization: Bearer lm_xxxxxxxx" https://<平台域名>/api/v1/products
```

| 权限 | 可以做什么 |
| --- | --- |
| `read_products` | 读取商品、规格、分类 |
| `write_products` | 创建、修改、删除商品（包含读取权限） |
| `write_inventory` | 调整库存 |
| `read_orders` | 读取订单 |
| `write_orders` | 发货、修改物流单号（包含读取权限） |
| `read_customers` | 读取客户 |

- 每个令牌每分钟最多 120 次请求，超出返回 `429`。
- 令牌只能访问创建它的店铺。
- 店铺暂停营业（试用结束、欠费）时返回 `402`；令牌缺少某项权限时返回 `403`。
- 令牌被撤销后立即失效，返回 `401`。

## 接口

| 方法 | 路径 | 权限 | 说明 |
| --- | --- | --- | --- |
| GET | `/` | 任意 | 当前店铺和令牌信息 |
| GET | `/products?q=&status=&page=&per_page=` | read_products | 商品列表（分页，`per_page` 最大 100） |
| GET | `/products/{id}` | read_products | 商品详情（规格、图片、分类） |
| POST | `/products` | write_products | 创建商品（字段同后台：title、description、status、options、variants…） |
| PUT/PATCH | `/products/{id}` | write_products | 修改商品（只传要改的字段） |
| DELETE | `/products/{id}` | write_products | 删除商品 |
| GET | `/collections` | read_products | 分类列表 |
| POST | `/inventory/adjust` | write_inventory | 设置或增减库存，见下方示例 |
| GET | `/orders?status=&q=&page=` | read_orders | 订单列表 |
| GET | `/orders/{id}` | read_orders | 订单详情 |
| POST | `/orders/{id}/fulfill` | write_orders | 标记发货：`{"tracking_company":"DHL","tracking_number":"…","notify":true}` |
| PUT | `/orders/{id}/tracking` | write_orders | 修改物流信息 |
| GET | `/customers?q=&page=` | read_customers | 客户列表 |
| GET | `/customers/{id}` | read_customers | 客户详情 |

调整库存的请求体（按 `variant_id` 或 `sku` 指定规格）：

```json
{ "sku": "GR-1", "available": 42 }
{ "variant_id": 123, "adjustment": -2 }
```

- `available`：直接设为这个数量。
- `adjustment`：在现有库存上增加或减少。
- 两个字段只需要传其中一个。

金额都是字符串，单位是元或美元等主币单位（例如 `"49.90"`），币种以店铺设置为准。

## Webhooks

在「设置 → 开发者 → Webhooks」添加接收地址，并选择要订阅的事件。

| 事件 | 触发时机 |
| --- | --- |
| `orders/create` | 买家下单 |
| `orders/paid` | 订单付款成功 |
| `orders/fulfilled` | 订单发货 |
| `orders/cancelled` | 订单取消 |
| `orders/refunded` | 订单退款（含部分退款） |
| `products/create` | 新建商品 |
| `products/update` | 修改商品或库存 |
| `products/delete` | 删除商品 |
| `customers/create` | 新客户 |

请求是 `POST`，请求体为 JSON：

```json
{
  "id": "3f1c…",
  "topic": "orders/paid",
  "created_at": "2026-11-18T10:00:00+00:00",
  "store_id": 12,
  "data": { "...": "和 REST API 返回的对象相同" }
}
```

请求头：

- `X-Lumee-Topic`：事件名称。
- `X-Lumee-Event-Id`：事件 ID，和请求体里的 `id` 相同，可以用来去重。
- `X-Lumee-Store-Id`：店铺 ID。
- `X-Lumee-Hmac-Sha256`：签名，算法是 `base64(hmac_sha256(原始请求体, 签名密钥))`。

校验签名示例（PHP）：

```php
$expected = base64_encode(hash_hmac('sha256', file_get_contents('php://input'), $secret, true));
if (! hash_equals($expected, $_SERVER['HTTP_X_LUMEE_HMAC_SHA256'] ?? '')) { http_response_code(401); exit; }
```

### 重试规则

- 对方在 10 秒内返回 2xx 才算成功，不跟随跳转。
- 失败后依次在 1 分钟、5 分钟、30 分钟、2 小时、6 小时后重试，最多尝试 6 次。
- 一个事件 6 次都失败，算这个订阅失败一次；连续失败 10 次后，订阅会被自动停用。重新启用后，失败次数清零。
- 后台可以发送测试事件（`webhooks/test`），查看最近 50 条发送记录，并重新发送某个事件。重新发送时事件 ID 不变。
- 接收地址必须是 `https://` 开头的公网地址。内网、回环、保留地址一律拒绝，发送时会固定连接到已经校验过的 IP（防止 SSRF）。

## OAuth 应用（第三方应用）

第三方开发者可以做「应用」，安装到任何 Lumée 店铺，流程和 Shopify 应用类似。

### 1. 注册应用

- 在后台右上角菜单选择「我的应用（开发者）」，点击创建应用，填写以下信息：
  - 名称、介绍、图标；
  - 应用地址：商家在后台点「打开」时进入的页面，会附带 `?store_id=…&store=…`；
  - 回调地址（Redirect URI）；
  - 要申请的权限；
  - 卸载通知地址（可选）。
- 创建后会得到：
  - `client_id`；
  - `client_secret`：只显示一次，可以重新生成；
  - 签名密钥：用来校验卸载通知。
- 应用默认是草稿状态，只有开发者本人能安装到自己的店铺测试。提交审核、平台通过后，才会出现在所有商家的「应用 → 应用市场」里。
- 如果已上架的应用申请了更多权限，需要重新审核。

### 2. 授权码流程（OAuth 2.0 + PKCE）

1. 把商家带到授权页：

   ```
   https://<后台域名>/oauth/authorize?client_id=…&redirect_uri=https://your-app.com/callback
     &scope=read_products%20write_inventory&state=<随机串>
     &code_challenge=<base64url(sha256(verifier))>&code_challenge_method=S256
   ```

   - 商家登录后台后，选择要安装的店铺，确认权限。
   - `scope` 只能是应用登记过的权限，不填时默认是全部登记的权限。
   - PKCE 是可选的，只支持 S256。

2. 商家同意后，浏览器会跳回 `redirect_uri?code=…&state=…&store_id=…`。
   - 请核对 `state` 和你发出去的是否一致。
   - 如果商家拒绝，会跳回 `?error=access_denied&state=…`。
   - 授权码 10 分钟内有效，只能使用一次。

3. 在你的服务器上用授权码换取令牌：

   ```bash
   curl -X POST https://<平台域名>/api/oauth/token \
     -d grant_type=authorization_code -d code=… -d client_id=… -d client_secret=… \
     -d redirect_uri=https://your-app.com/callback -d code_verifier=…
   ```

   返回：

   ```json
   { "access_token": "lm_…", "token_type": "bearer", "scope": "read_products write_inventory",
     "store": { "id": 12, "name": "Aurora Jewels", "url": "https://…", "currency": "USD" } }
   ```

   - 出错时按 RFC 6749 的格式返回：`invalid_client`（401）或 `invalid_grant`（400）。
   - 同一个授权码第二次使用时，系统会同时吊销它换出的令牌。

拿到的令牌就是一个普通的店铺 API 令牌：和上面的 REST API 用法相同，权限相同，限流也相同（每分钟 120 次）。同一个应用在同一个店铺只保留一个有效令牌，重新授权后旧令牌立即失效。

### 3. 应用订阅 Webhook

应用安装后，用自己的令牌管理 Webhook：

```bash
POST   /api/v1/webhooks      {"url": "https://your-app.com/hooks", "topics": ["products/update"]}   → 返回 secret
GET    /api/v1/webhooks
DELETE /api/v1/webhooks/{id}
```

- 订阅事件需要对应的读取权限：订单事件需要 `read_orders`，商品事件需要 `read_products`，客户事件需要 `read_customers`。
- 应用只能看到自己创建的 Webhook。
- 商家卸载应用时，这些 Webhook 会一起删除。

### 4. 卸载

商家在「应用」页面卸载后：

- 令牌立即失效；
- 应用创建的 Webhook 被删除；
- 如果登记了卸载通知地址，平台会发送一个 POST：
  - 请求体：`{"topic":"app/uninstalled","store_id":12,"app_id":3,"uninstalled_at":"…"}`
  - 请求头：`X-Lumee-Hmac-Sha256: base64(hmac_sha256(请求体, 签名密钥))`
- 进行中的应用收费（月费）会被取消，不再续费。

### 5. 应用收费

应用可以向店铺收费：月费（每 30 天）或一次性费用，币种为美元。商家在后台批准后，费用计入店铺的 Lumée 平台账单（与套餐费一起收取）。平台保留 20%，开发者获得 80%，收入显示在「我的应用（开发者）」页面。

只能用安装应用时拿到的令牌调用，店铺自建的 API 令牌会返回 403。

```
POST /api/v1/app_charges
{
  "name": "Pro plan",
  "price": 19.99,
  "interval": "every_30_days",        // 或 "one_time"
  "trial_days": 7,                    // 每个店铺只能试用一次
  "return_url": "https://your-app.com/billing",   // 必须在应用地址或回调地址的域名下
  "test": false                       // true：开发测试用，不会真的扣费
}
→ 201 { "data": { "id": 5, "status": "pending", "confirmation_url": "https://admin…/apps/charges/…", … } }
```

1. 把商家带到 `confirmation_url`，商家在后台看到收费说明后点「批准」或「拒绝」。
2. 处理完后，商家会回到 `return_url?charge_id=5`。用 `GET /api/v1/app_charges/5` 查看 `status`：`active`（已批准）或 `declined`（已拒绝）。
3. 批准一个新的月费，会自动取消这个应用之前的月费（相当于换套餐）。
4. `DELETE /api/v1/app_charges/5` 取消月费；已经收过的一次性费用不能取消。
5. `GET /api/v1/app_charges` 列出这个应用在该店铺的所有收费。

### 6. 嵌入后台（App Bridge）

在应用设置里勾选「嵌入后台打开」后，商家点「打开」时，应用页面会显示在后台里（iframe），而不是打开新窗口。

- 应用地址会带上 `store_id`、`store`、`embedded=1`、`host`（后台地址）和 `session`（会话令牌）。
- 会话令牌是 JWT（HS256），用应用的**签名密钥**签名，有效期 60 秒。声明：`iss`（后台地址）、`dest`（店铺地址）、`aud`（client id）、`sub`（员工 ID）、`store_id`、`store`、`exp`、`nbf`、`iat`、`jti`。
- 应用后端验证令牌：检查签名、`aud` 等于自己的 client id、`exp` 没有过期。这样不需要在 iframe 里使用第三方 cookie。
- 在页面里加载 `https://<平台域名>/app-bridge.js`：

```js
const bridge = LumeeAppBridge.create()
const token = await bridge.getSessionToken()   // 过期后自动向后台要一个新的
await fetch('/api/settings', { headers: { Authorization: `Bearer ${token}` } })
bridge.toast('已保存')
bridge.setTitle('设置')
bridge.redirect('/orders')                     // 只能跳转到后台内的页面
```

后台只接受来自应用地址同一来源的消息。

## 主题市场

开发者可以把主题发布到主题市场：

1. 在任意店铺的「在线商店 › 主题」里自定义好主题，点「导出」得到 `.zip` 主题包。
2. 在「我的主题（开发者）」新建主题，填写介绍和截图地址（https），上传主题包，提交审核。
3. 平台审核通过后，所有商家都能在主题库的「主题市场」里一键添加，图片会复制到商家自己的店铺。
4. 上传新版本后，已上架的主题会重新进入审核；已经安装的店铺不受影响。
