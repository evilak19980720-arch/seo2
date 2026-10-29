// Injected by the preview router (?__qa=1) for the AI quality check: reads the
// rendered page and writes what it found into a pre#__qa element for --dump-dom.
// It never relies on class names (they are randomised per site).
window.addEventListener('load', function () {
  setTimeout(function () {
    var parse = function (c) {
      var s = c.match(/color\(srgb ([^)]+)\)/);
      if (s) { var q = s[1].split(/[ \/]+/).filter(Boolean).map(Number); return [q[0] * 255, q[1] * 255, q[2] * 255, q[3] === undefined ? 1 : q[3]]; }
      var m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null;
      var p = m[1].split(/[ ,\/]+/).filter(Boolean).map(Number); return [p[0], p[1], p[2], p[3] === undefined ? 1 : p[3]];
    };
    var lum = function (c) { var f = function (v) { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }; return .2126 * f(c[0]) + .7152 * f(c[1]) + .0722 * f(c[2]); };
    var blend = function (t, b) { var a = t[3]; return [t[0] * a + b[0] * (1 - a), t[1] * a + b[1] * (1 - a), t[2] * a + b[2] * (1 - a), 1]; };
    var bgOf = function (el) {
      var layers = [], complex = false;
      for (var e = el; e; e = e.parentElement) {
        var cs = getComputedStyle(e);
        if (cs.backgroundImage !== 'none' && !/^(BODY|HTML)$/.test(e.tagName)) complex = true;
        var c = parse(cs.backgroundColor);
        if (c && c[3] > 0) { layers.push(c); if (c[3] >= .99) break; }
      }
      var bg = [255, 255, 255, 1];
      for (var i = layers.length - 1; i >= 0; i--) bg = blend(layers[i], bg);
      return { bg: bg, complex: complex };
    };
    var out = { contrast: [], overflow: Math.max(0, document.documentElement.scrollWidth - innerWidth), brokenImages: 0, missingAlt: 0, h1: document.querySelectorAll('h1').length, smallText: 0, tapTargets: 0 };
    var all = document.querySelectorAll('body *');
    for (var i = 0; i < all.length; i++) {
      var el = all[i];
      var hasText = false;
      for (var n = el.firstChild; n; n = n.nextSibling) if (n.nodeType === 3 && n.textContent.trim()) { hasText = true; break; }
      if (!hasText) continue;
      var r = el.getBoundingClientRect();
      if (r.width < 2 || r.height < 2 || r.bottom < 0 || r.top > innerHeight || r.right < 0 || r.left > innerWidth) continue;
      var cs = getComputedStyle(el);
      if (cs.visibility !== 'visible') continue;
      var op = 1; for (var e = el; e; e = e.parentElement) op *= +getComputedStyle(e).opacity;
      if (op < .3) continue;
      if (parseFloat(cs.fontSize) < 12) out.smallText++;
      var b = bgOf(el); if (b.complex) continue;
      var under = document.elementsFromPoint(r.left + r.width / 2, r.top + r.height / 2);
      if (under.some(function (u) { return u.tagName === 'IMG' || u.tagName === 'PICTURE' || u.tagName === 'VIDEO'; })) continue;
      var fg = parse(cs.color); if (!fg) continue;
      fg = blend([fg[0], fg[1], fg[2], fg[3] * Math.min(1, op)], b.bg);
      var a = lum(fg), c2 = lum(b.bg), ratio = (Math.max(a, c2) + .05) / (Math.min(a, c2) + .05);
      if (ratio < 3 && out.contrast.length < 20) out.contrast.push({ ratio: Math.round(ratio * 100) / 100, tag: el.tagName.toLowerCase(), text: el.textContent.trim().slice(0, 40) });
    }
    document.querySelectorAll('img').forEach(function (img) {
      if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) out.brokenImages++;
      if (!img.hasAttribute('alt')) out.missingAlt++;
    });
    if (innerWidth < 600) document.querySelectorAll('a,button').forEach(function (a) { var r = a.getBoundingClientRect(); if (r.width > 0 && r.height > 0 && r.height < 24 && getComputedStyle(a).display !== 'inline') out.tapTargets++; });
    var pre = document.createElement('pre'); pre.id = '__qa'; pre.textContent = JSON.stringify(out); document.body.appendChild(pre);
  }, 300);
});
