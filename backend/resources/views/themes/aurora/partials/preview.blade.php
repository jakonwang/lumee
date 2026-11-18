{{--
    Theme editor preview helper (only rendered with a valid preview token):
    - keeps the preview token on internal links so navigation stays in draft mode
    - preserves scroll position across the editor's reloads
    - highlights a section when the editor selects it, and tells the editor when one is clicked
--}}
<style>
    [data-section-id] { position: relative; }
    [data-section-id].lumee-hover::after,
    [data-section-id].lumee-selected::after { content: ''; position: absolute; inset: 0; pointer-events: none; z-index: 5; }
    [data-section-id].lumee-hover::after { outline: 2px dashed rgba(176, 138, 120, .6); outline-offset: -2px; }
    [data-section-id].lumee-selected::after { outline: 2px solid #B08A78; outline-offset: -2px; }
</style>
<script>
(function () {
    const token = new URLSearchParams(location.search).get('_preview');
    if (!token) return;

    // Keep draft mode when navigating inside the preview (forms are disabled — preview is read-only)
    document.querySelectorAll('a[href]').forEach(function (a) {
        const url = new URL(a.getAttribute('href'), location.href);
        if (url.origin !== location.origin) { a.target = '_blank'; return; }
        url.searchParams.set('_preview', token);
        a.href = url.pathname + url.search + url.hash;
    });
    document.querySelectorAll('form').forEach(function (f) {
        f.addEventListener('submit', function (e) { e.preventDefault(); });
    });

    // Restore scroll after the editor reloads this frame
    const key = 'lumee-preview-scroll:' + location.pathname;
    try { const y = sessionStorage.getItem(key); if (y) window.scrollTo(0, Number(y)); } catch (e) {}
    let t;
    window.addEventListener('scroll', function () {
        clearTimeout(t);
        t = setTimeout(function () { try { sessionStorage.setItem(key, String(window.scrollY)); } catch (e) {} }, 100);
    }, { passive: true });

    function select(id, scroll) {
        document.querySelectorAll('.lumee-selected').forEach(function (el) { el.classList.remove('lumee-selected'); });
        const el = id && document.querySelector('[data-section-id="' + CSS.escape(id) + '"]');
        if (!el) return;
        el.classList.add('lumee-selected');
        if (scroll) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    window.addEventListener('message', function (e) {
        if (e.data && e.data.type === 'lumee:select-section') select(e.data.id, true);
    });

    document.querySelectorAll('[data-section-id]').forEach(function (el) {
        el.addEventListener('mouseenter', function () { el.classList.add('lumee-hover'); });
        el.addEventListener('mouseleave', function () { el.classList.remove('lumee-hover'); });
        el.addEventListener('click', function () {
            select(el.dataset.sectionId, false);
            window.parent.postMessage({ type: 'lumee:section-clicked', id: el.dataset.sectionId }, '*');
        });
    });

    window.parent.postMessage({ type: 'lumee:preview-ready', path: location.pathname }, '*');
})();
</script>
