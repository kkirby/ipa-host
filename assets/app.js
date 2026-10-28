(function () {
  'use strict';

  var csrfMeta = document.querySelector('meta[name="csrf-token"]');
  var csrf = csrfMeta ? csrfMeta.content : '';

  // iPadOS Safari reports itself as a Mac; touch support gives it away.
  var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) ||
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

  /* ---------------------------------------------------------- toast */
  var toastEl;
  function toast(msg) {
    if (!toastEl) {
      toastEl = document.createElement('div');
      toastEl.className = 'toast';
      document.body.appendChild(toastEl);
    }
    toastEl.textContent = msg;
    toastEl.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { toastEl.classList.remove('show'); }, 1800);
  }

  /* ---------------------------------------------------------- copy */
  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy') ? resolve() : reject(); } catch (e) { reject(e); }
      document.body.removeChild(ta);
    });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) return;
    e.preventDefault();
    copyText(btn.getAttribute('data-copy')).then(
      function () { toast('Copied'); },
      function () { window.prompt('Copy:', btn.getAttribute('data-copy')); }
    );
  });

  /* ---------------------------------------------------------- confirm */
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) e.preventDefault();
  });

  document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
  });

  /* ---------------------------------------------------------- QR modal */
  var modal = document.getElementById('qr-modal');
  var qrLink = '';

  function openQr(link, name) {
    if (!modal || typeof qrcode === 'undefined') {
      window.prompt('Open this link on your iPhone:', link);
      return;
    }
    var qr = qrcode(0, 'M');
    qr.addData(link);
    qr.make();
    document.getElementById('qr-code').innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
    document.getElementById('qr-name').textContent = name || '';
    qrLink = link;
    modal.hidden = false;
  }

  function closeQr() { if (modal) modal.hidden = true; }

  if (modal) {
    modal.addEventListener('click', function (e) {
      if (e.target === modal || e.target.closest('[data-close]')) closeQr();
    });
    document.getElementById('qr-copy').addEventListener('click', function () {
      copyText(qrLink).then(function () { toast('Link copied'); });
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeQr(); });
  }

  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-qr]');
    if (!el) return;
    // On iOS, Install buttons go straight to the itms-services link.
    if (el.classList.contains('install') && isIOS) return;
    e.preventDefault();
    openQr(el.getAttribute('data-qr'), el.getAttribute('data-name'));
  });

  if (!isIOS) {
    document.querySelectorAll('a.install').forEach(function (a) {
      a.title = 'Not on an iPhone/iPad — shows a QR code to scan with your device';
    });
    document.querySelectorAll('.not-ios').forEach(function (el) { el.hidden = false; });
  }

  /* ---------------------------------------------------------- app filter */
  var search = document.getElementById('app-search');
  if (search) {
    search.addEventListener('input', function () {
      var q = search.value.trim().toLowerCase();
      document.querySelectorAll('#app-grid .app-card').forEach(function (card) {
        card.hidden = q !== '' && card.getAttribute('data-search').indexOf(q) === -1;
      });
    });
  }

  /* ---------------------------------------------------------- chunked upload */
  var drop = document.getElementById('dropzone');
  if (!drop) return;

  var input = document.getElementById('file-input');
  var list = document.getElementById('upload-list');
  var notesEl = document.getElementById('upload-notes');
  var endpoint = drop.getAttribute('data-endpoint');
  var defaultChunk = parseInt(drop.getAttribute('data-chunk-size'), 10) || 4 * 1024 * 1024;
  var queue = [];
  var busy = false;
  var anySuccess = false;

  ['dragenter', 'dragover'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); });
  });
  drop.addEventListener('drop', function (e) { addFiles(e.dataTransfer.files); });
  input.addEventListener('change', function () { addFiles(input.files); input.value = ''; });
  // Allow dropping anywhere on the page.
  document.addEventListener('dragover', function (e) { e.preventDefault(); });
  document.addEventListener('drop', function (e) {
    if (!drop.contains(e.target)) { e.preventDefault(); addFiles(e.dataTransfer.files); }
  });

  window.addEventListener('beforeunload', function (e) {
    if (busy) { e.preventDefault(); e.returnValue = ''; }
  });

  function addFiles(files) {
    Array.prototype.forEach.call(files || [], function (file) {
      var li = document.createElement('li');
      li.innerHTML = '<div class="top"><span class="name truncate"></span><span class="status muted"></span></div><div class="progress"><span></span></div>';
      li.querySelector('.name').textContent = file.name;
      list.appendChild(li);
      if (!/\.ipa$/i.test(file.name)) {
        setStatus(li, 'Not an .ipa file', 'error');
        return;
      }
      queue.push({ file: file, li: li, notes: notesEl ? notesEl.value : '' });
    });
    if (notesEl && queue.length) notesEl.value = '';
    next();
  }

  function setStatus(li, text, state, html) {
    var s = li.querySelector('.status');
    if (html) s.innerHTML = html; else s.textContent = text;
    if (state) li.className = state;
  }

  function setProgress(li, frac) {
    li.querySelector('.progress > span').style.width = Math.min(100, frac * 100).toFixed(1) + '%';
  }

  function next() {
    if (busy) return;
    var item = queue.shift();
    if (!item) {
      if (anySuccess) setTimeout(function () { location.reload(); }, 1200);
      return;
    }
    busy = true;
    upload(item).then(function () {
      anySuccess = true;
    }, function (err) {
      setStatus(item.li, err.message || 'Upload failed', 'error', err.html);
    }).then(function () {
      busy = false;
      next();
    });
  }

  function request(url, opts) {
    opts = opts || {};
    opts.headers = Object.assign({ 'X-CSRF-Token': csrf, 'Accept': 'application/json' }, opts.headers || {});
    opts.credentials = 'same-origin';
    return fetch(url, opts).then(function (res) {
      return res.text().then(function (text) {
        var data = null;
        try { data = JSON.parse(text); } catch (e) { /* non-JSON (e.g. 413 page) */ }
        return { status: res.status, ok: res.ok, data: data || {} };
      });
    });
  }

  function form(obj) {
    var fd = new URLSearchParams();
    Object.keys(obj).forEach(function (k) { fd.append(k, obj[k]); });
    return fd;
  }

  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function upload(item) {
    var file = item.file;
    var li = item.li;
    var chunk = defaultChunk;
    var id, uploadId;
    var started = Date.now();
    setStatus(li, 'Starting…', 'active');

    return request(endpoint + '&step=init', {
      method: 'POST',
      body: form({ name: file.name, size: file.size, notes: item.notes })
    }).then(function (r) {
      if (!r.ok) throw new Error(r.data.error || 'Could not start upload (HTTP ' + r.status + ')');
      id = uploadId = r.data.id;
      chunk = Math.min(chunk, r.data.chunkSize || chunk);
      return sendFrom(0, 0);
    }).then(function () {
      id = null; // the server owns the file from here; nothing to cancel
      setStatus(li, 'Processing…');
      return request(endpoint + '&step=finish&id=' + uploadId, { method: 'POST' });
    }).then(function (r) {
      if (r.status === 409 && r.data.url) {
        var e = new Error(r.data.error);
        e.html = escapeHtml(r.data.error) + ' <a href="' + escapeHtml(r.data.url) + '">View</a>';
        throw e;
      }
      if (!r.ok) throw new Error(r.data.error || 'Processing failed (HTTP ' + r.status + ')');
      setProgress(li, 1);
      setStatus(li, '', 'done', 'Done · <a href="' + escapeHtml(r.data.url) + '">' + escapeHtml(r.data.build.name + ' ' + r.data.build.version) + '</a>');
    }).catch(function (err) {
      if (id) request(endpoint + '&step=cancel&id=' + id, { method: 'POST' });
      throw err;
    });

    function sendFrom(offset, attempt) {
      if (offset >= file.size) return Promise.resolve();
      var end = Math.min(file.size, offset + chunk);
      return request(endpoint + '&step=chunk&id=' + id + '&offset=' + offset, {
        method: 'POST',
        headers: { 'Content-Type': 'application/octet-stream' },
        body: file.slice(offset, end)
      }).then(function (r) {
        if (r.ok) {
          var got = r.data.received;
          var secs = (Date.now() - started) / 1000;
          var rate = secs > 0 ? got / secs : 0;
          setProgress(li, got / file.size);
          setStatus(li, fmt(got) + ' / ' + fmt(file.size) + (rate ? ' · ' + fmt(rate) + '/s' : ''));
          return sendFrom(got, 0);
        }
        if (r.status === 413 && chunk > 256 * 1024) {
          // Web server body limit is smaller than PHP's: shrink and retry.
          chunk = Math.max(256 * 1024, Math.floor(chunk / 2));
          return sendFrom(offset, attempt);
        }
        if (r.status === 409 && typeof r.data.received === 'number') {
          return sendFrom(r.data.received, attempt);
        }
        if (r.status >= 500 || r.status === 0) return retry(offset, attempt, new Error('Server error (HTTP ' + r.status + ')'));
        throw new Error(r.data.error || 'Upload failed (HTTP ' + r.status + ')');
      }, function (netErr) {
        return retry(offset, attempt, netErr);
      });
    }

    function retry(offset, attempt, err) {
      if (attempt >= 5) throw new Error('Network error, gave up after retries');
      setStatus(li, 'Connection problem, retrying…');
      return sleep(1000 * Math.pow(2, attempt)).then(function () { return sendFrom(offset, attempt + 1); });
    }
  }

  function fmt(n) {
    var u = ['B', 'KB', 'MB', 'GB'];
    var i = 0;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (i ? n.toFixed(1) : n) + ' ' + u[i];
  }
})();
