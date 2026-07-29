// Persists per-tab library filters (films/series) in localStorage so each tab
// reopens with the user's last-used filters. Loaded once in <head>; all
// per-page state is read from a [data-libfilter-type] marker on the filter
// form each navigation, so there are no per-page scripts and no accumulating
// listeners. Design doc:
// docs/superpowers/specs/2026-07-12-persistent-library-filters-design.md
(function () {
  'use strict';

  // Bind exactly once for the life of the document. Turbo keeps identical
  // <head> scripts across visits, but guard anyway so a stray re-eval can
  // never double-bind the document-level listeners registered below.
  if (window.PrismarrLibFilters) { return; }

  var EXCLUDE = { q: true, page: true, open: true };

  function isExcluded(k) {
    return Object.prototype.hasOwnProperty.call(EXCLUDE, k);
  }

  function storageKey(type, slug) {
    return 'prismarr_' + type + '_filters:' + slug;
  }

  // Build the tracked query string: drop search/page/open + empty values.
  function trackedQS(search) {
    var params = new URLSearchParams(search || '');
    var out = new URLSearchParams();
    params.forEach(function (v, k) {
      if (isExcluded(k)) { return; }
      if (v === '' || v == null) { return; }
      out.append(k, v);
    });
    return out.toString();
  }

  function readSaved(key) {
    try { return localStorage.getItem(key) || ''; } catch (e) { return ''; }
  }
  function writeSaved(key, qs) {
    try { localStorage.setItem(key, qs); } catch (e) {}
  }
  function clearSaved(key) {
    try { localStorage.removeItem(key); } catch (e) {}
  }

  function bindReset(el, key) {
    el.addEventListener('click', function () { clearSaved(key); });
  }

  // Save/restore for the current page if it is a library page. The page marks
  // itself with data-libfilter-type / data-libfilter-slug on its filter form.
  function syncCurrentPage() {
    var el = document.querySelector('[data-libfilter-type][data-libfilter-slug]');
    if (!el) { return; }
    var type = el.getAttribute('data-libfilter-type');
    var slug = el.getAttribute('data-libfilter-slug');
    if (!type || !slug) { return; }

    var key = storageKey(type, slug);
    var qs = trackedQS(window.location.search);

    if (qs) {
      // Viewing a filtered URL — remember it.
      writeSaved(key, qs);
    } else if (!window.location.search) {
      // Completely empty URL — restore saved filters if any.
      var saved = readSaved(key);
      if (saved) {
        window.location.replace(window.location.pathname + '?' + saved);
        return; // navigating away; nothing else to wire on a dead page
      }
    }

    // Reset/clear controls wipe the saved state before their normal
    // navigation. These elements are recreated on every Turbo body swap, so
    // binding here each navigation never accumulates on a live element.
    var resets = document.querySelectorAll('.js-libfilter-reset');
    for (var i = 0; i < resets.length; i++) {
      bindReset(resets[i], key);
    }
  }

  // Rewrite marked library nav links to carry the saved filters, so the common
  // navigation path lands filtered with no reload flash. Stateless and
  // idempotent: re-reads the DOM/localStorage each call and skips links that
  // already carry a query.
  function rewriteNavLinks() {
    var links = document.querySelectorAll('a.js-libfilter-link');
    for (var i = 0; i < links.length; i++) {
      var a = links[i];
      var href = a.getAttribute('href') || '';
      if (href.indexOf('?') !== -1) { continue; } // already carries a query
      var m = href.match(/\/medias\/([^/]+)\/(films|series)(?:[?#]|$)/);
      if (!m) { continue; }
      var saved = readSaved(storageKey(m[2], m[1]));
      if (saved) { a.setAttribute('href', href + '?' + saved); }
    }
  }

  function onNavigate() {
    rewriteNavLinks();
    syncCurrentPage();
  }

  // turbo:load fires on the initial load and after each Turbo visit;
  // DOMContentLoaded covers a non-Turbo initial load. Both may fire on the
  // first load — onNavigate is idempotent, so that is harmless.
  document.addEventListener('turbo:load', onNavigate);
  document.addEventListener('DOMContentLoaded', onNavigate);

  window.PrismarrLibFilters = {
    onNavigate: onNavigate,
    rewriteNavLinks: rewriteNavLinks,
    _storageKey: storageKey,
    _trackedQS: trackedQS
  };
})();
