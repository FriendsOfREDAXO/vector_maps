/*
 * vector_maps – Lader (klassisches Skript).
 *
 * Die eigentliche Komponente ist ein ES-Modul (vectormaps.module.js, importiert MapLibre GL JS 6). Dieser Lader holt
 * es per dynamischem import() nach – so funktioniert jede Einbindung: <script src>, <script defer>, type="module",
 * nachträglich eingefügt (Lazy-Loader, PJAX, Optimierer, die Script-Tags umschreiben) und alte Einbindungen aus 1.x.
 * Aus vectormaps.min.js wird vectormaps.module.min.js (gleicher Ordner, gleiche ?v=-Version).
 *
 * Fertig, sobald window.VectorMaps.ready erfüllt ist bzw. das Ereignis „vectormaps:ready“ kam.
 */
(function () {
    'use strict';
    if (window.__vectorMapsLoader) return;
    window.__vectorMapsLoader = true;

    // Promise für eigenen Code, der schon vor dem Modul läuft (das Modul löst es auf)
    var api = window.VectorMaps = window.VectorMaps || {};
    if (!api.ready) {
        api.ready = new Promise(function (resolve) { api.__resolveReady = resolve; });
    }

    // eigene Adresse: currentScript (klassisch) bzw. Suche nach dem Script-Tag (als Modul oder nachgeladen)
    var src = document.currentScript && document.currentScript.src;
    if (!src) {
        var scripts = document.getElementsByTagName('script');
        for (var i = scripts.length - 1; i >= 0; i--) {
            if (/\/vectormaps(\.min)?\.js(\?|$)/.test(scripts[i].src)) { src = scripts[i].src; break; }
        }
    }
    if (!src) {
        console.error('[vector_maps] Lader: eigene Skript-Adresse nicht gefunden.');
        return;
    }
    var url = new URL(src, location.href);
    url.pathname = url.pathname.replace(/vectormaps(\.min)?\.js$/, function (m, min) { return 'vectormaps.module' + (min || '') + '.js'; });

    import(url.href).catch(function (err) {
        console.error('[vector_maps] Modul konnte nicht geladen werden (MapLibre 6 braucht einen Browser mit ES-Modulen und WebGL2):', err);
    });
})();
