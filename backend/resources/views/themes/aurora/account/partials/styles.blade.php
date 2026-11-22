@push('head')
<style>
    .acct { padding-block: 56px; }
    .auth-card { max-width: 440px; margin: 0 auto; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-card); padding: 36px 32px; box-shadow: var(--shadow-soft); }
    .auth-card h1 { font-size: 34px; margin: 6px 0 8px; }
    .auth-card .lead { color: var(--ink-muted); font-size: 14px; margin: 0 0 24px; line-height: 1.6; }
    .form-stack { display: grid; gap: 12px; }
    .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .grid3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
    .field.invalid { border-color: var(--danger); }
    .err { color: var(--danger); font-size: 13px; margin: -4px 0 0; }
    .check { display: flex; gap: 8px; align-items: flex-start; font-size: 14px; color: var(--ink-muted); }
    .check input { margin-top: 3px; accent-color: var(--rose-gold-500); }
    .alt { text-align: center; font-size: 14px; color: var(--ink-muted); margin-top: 20px; }
    .alt a { color: var(--rose-gold-700); text-decoration: underline; }
    .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
    .acct-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 28px; }
    .acct-head h1 { font-size: 40px; margin: 6px 0 0; }
    .link-btn { background: none; border: none; padding: 0; color: var(--ink-muted); font-size: 14px; text-decoration: underline; cursor: pointer; }
    .acct-grid { display: grid; grid-template-columns: 1fr 340px; gap: 40px; align-items: start; }
    .panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-card); padding: 24px; margin-bottom: 20px; }
    .panel h2 { font-family: var(--font-display); font-size: 22px; margin: 0 0 16px; font-weight: 600; }
    .orders { width: 100%; border-collapse: collapse; font-size: 14px; }
    .orders th { text-align: start; font-weight: 500; color: var(--ink-muted); font-size: 12px; letter-spacing: .08em; text-transform: uppercase; padding: 0 8px 10px; }
    .orders td { padding: 14px 8px; border-top: 1px solid var(--border); vertical-align: middle; }
    .orders a { color: var(--rose-gold-700); text-decoration: underline; }
    .pill { display: inline-block; font-size: 12px; padding: 2px 10px; border-radius: 999px; background: var(--rose-gold-100); color: var(--rose-gold-700); white-space: nowrap; }
    .pill.muted { background: var(--surface-muted, #f3f3f3); color: var(--ink-muted); }
    .muted { color: var(--ink-muted); font-size: 14px; }
    .notice { padding: 14px 16px; border-radius: 10px; border: 1px dashed var(--rose-gold-300); font-size: 14px; line-height: 1.6; margin-bottom: 20px; }
    .pager { display: flex; justify-content: space-between; margin-top: 16px; font-size: 14px; }
    .pager a { color: var(--rose-gold-700); }
    details.panel summary { cursor: pointer; font-weight: 500; }
    details.panel[open] summary { margin-bottom: 16px; }
    @media (max-width: 860px) { .acct-grid { grid-template-columns: 1fr; } .orders .hide-sm { display: none; } }
    @media (max-width: 520px) { .grid2, .grid3 { grid-template-columns: 1fr; } .auth-card { padding: 28px 20px; } }
</style>
@endpush
