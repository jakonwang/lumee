/**
 * Printable invoice / packing slip for one order, opened in a new window and sent to the printer.
 * Built from the order the admin already loaded; every value is HTML-escaped.
 */
import type { Order } from '@/api/orders'
import { t } from '@/i18n'

const esc = (v: unknown) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!)

export function printOrder(order: Order, kind: 'invoice' | 'packing', store: { name: string }) {
  const a = (order.shipping_address ?? {}) as Record<string, string | undefined>
  const money = (v: string) => `${order.currency} ${v}`
  const address = [
    [a.first_name, a.last_name].filter(Boolean).join(' '),
    a.company, a.address1, a.address2,
    [a.city, a.province, a.zip].filter(Boolean).join(', '),
    a.country, a.phone,
  ].filter(Boolean).map(esc).join('<br>')
  const items = (order.items ?? []).map((i) => `
    <tr>
      <td>${esc(i.title)}${i.variant_title ? `<div class="muted">${esc(i.variant_title)}</div>` : ''}${i.sku ? `<div class="muted">SKU ${esc(i.sku)}</div>` : ''}</td>
      <td class="num">${esc(i.quantity)}</td>
      ${kind === 'invoice' ? `<td class="num">${esc(money(i.price))}</td><td class="num">${esc(money(i.total))}</td>` : '<td class="num check">☐</td>'}
    </tr>`).join('')
  const totals = kind === 'invoice' ? `
    <table class="totals">
      <tr><td>${esc(t('小计'))}</td><td class="num">${esc(money(order.subtotal))}</td></tr>
      ${order.discount_total !== '0.00' ? `<tr><td>${esc(t('折扣'))}${order.discount_code ? ` (${esc(order.discount_code)})` : ''}</td><td class="num">−${esc(money(order.discount_total))}</td></tr>` : ''}
      <tr><td>${esc(t('运费'))}</td><td class="num">${esc(money(order.shipping_total))}</td></tr>
      ${order.tax_total !== '0.00' ? `<tr><td>${esc(order.taxes_included ? t('含税') : t('税费'))}</td><td class="num">${esc(money(order.tax_total))}</td></tr>` : ''}
      ${order.gift_card_total && order.gift_card_total !== '0.00' ? `<tr><td>${esc(t('礼品卡'))}</td><td class="num">−${esc(money(order.gift_card_total))}</td></tr>` : ''}
      <tr class="grand"><td>${esc(t('合计'))}</td><td class="num">${esc(money(order.total))}</td></tr>
      ${order.refunded_total !== '0.00' ? `<tr><td>${esc(t('已退款'))}</td><td class="num">−${esc(money(order.refunded_total))}</td></tr>` : ''}
    </table>` : ''
  const title = kind === 'invoice' ? t('发票') : t('装箱单')
  const html = `<!doctype html><html><head><meta charset="utf-8"><title>${esc(title)} ${esc(order.name)}</title>
<style>
  * { box-sizing: border-box; }
  body { font: 13px/1.5 -apple-system, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif; color: #1C2621; margin: 0; padding: 40px; }
  .top { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #0E8A5F; padding-bottom: 16px; margin-bottom: 24px; }
  h1 { font-size: 22px; margin: 0; } .store { font-size: 16px; font-weight: 600; }
  .muted { color: #6B7570; font-size: 12px; }
  .cols { display: flex; gap: 48px; margin-bottom: 24px; } .cols h3 { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: #6B7570; margin: 0 0 6px; }
  table { width: 100%; border-collapse: collapse; } th { text-align: left; font-size: 12px; color: #6B7570; border-bottom: 1px solid #DDE3E0; padding: 8px 0; }
  td { padding: 10px 0; border-bottom: 1px solid #EEF1EF; vertical-align: top; } .num { text-align: right; white-space: nowrap; }
  .check { font-size: 18px; } .totals { width: 280px; margin: 16px 0 0 auto; } .totals td { border: 0; padding: 4px 0; }
  .grand td { font-weight: 700; font-size: 15px; border-top: 1px solid #DDE3E0; padding-top: 8px; }
  .note { margin-top: 24px; padding: 12px; background: #F4F7F5; border-radius: 6px; }
  @media print { body { padding: 0; } @page { margin: 16mm; } }
</style></head><body>
  <div class="top">
    <div><div class="store">${esc(store.name)}</div></div>
    <div style="text-align:right"><h1>${esc(title)}</h1><div>${esc(order.name)}</div><div class="muted">${esc(new Date(order.created_at).toLocaleDateString())}</div></div>
  </div>
  <div class="cols">
    <div><h3>${esc(t('收货地址'))}</h3>${address || '—'}</div>
    <div><h3>${esc(t('联系邮箱'))}</h3>${esc(order.email)}${order.shipping_method?.name ? `<h3 style="margin-top:12px">${esc(t('配送方式'))}</h3>${esc(order.shipping_method.name)}` : ''}</div>
  </div>
  <table>
    <thead><tr><th>${esc(t('商品'))}</th><th class="num">${esc(t('数量'))}</th>${kind === 'invoice' ? `<th class="num">${esc(t('单价'))}</th><th class="num">${esc(t('金额'))}</th>` : `<th class="num">${esc(t('已装箱'))}</th>`}</tr></thead>
    <tbody>${items}</tbody>
  </table>
  ${totals}
  ${order.note ? `<div class="note"><strong>${esc(t('买家备注'))}：</strong>${esc(order.note)}</div>` : ''}
  <script>window.onload = () => { window.print() }<\/script>
</body></html>`
  const w = window.open('', '_blank', 'width=900,height=1000')
  if (!w) return false
  w.document.open()
  w.document.write(html)
  w.document.close()
  return true
}
