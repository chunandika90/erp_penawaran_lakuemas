(function () {
  function openModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.add('open');
  }
  function closeModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('open');
  }
  document.querySelectorAll('[data-open-modal]').forEach(function (btn) {
    btn.addEventListener('click', function () { openModal(btn.getAttribute('data-open-modal')); });
  });
  document.querySelectorAll('[data-close-modal]').forEach(function (btn) {
    btn.addEventListener('click', function () { closeModal(btn.getAttribute('data-close-modal')); });
  });
  document.querySelectorAll('.modal-scrim').forEach(function (scrim) {
    scrim.addEventListener('click', function (e) { if (e.target === scrim) scrim.classList.remove('open'); });
  });

  window.__submitDeleteForm = function (action, fields) {
    var form = document.createElement('form');
    form.method = 'post';
    form.style.display = 'none';
    function addField(name, value) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value;
      form.appendChild(input);
    }
    addField('csrf_token', window.CSRF_TOKEN || '');
    addField('action', action);
    Object.keys(fields || {}).forEach(function (k) { addField(k, fields[k]); });
    document.body.appendChild(form);
    form.submit();
  };

  // Searchable dropdown ("combobox") — pilih dari daftar yang ada lewat klik,
  // atau ketik value baru (backend yang nentuin apa itu bikin record baru atau enggak).
  window.initCombobox = function (input, itemsSource, opts) {
    if (!input || input.dataset.comboInit) return;
    input.dataset.comboInit = '1';
    opts = opts || {};

    var wrap = document.createElement('div');
    wrap.className = 'combo-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    var dd = document.createElement('div');
    dd.className = 'combo-dropdown';
    wrap.appendChild(dd);

    function items() { return typeof itemsSource === 'function' ? itemsSource() : (itemsSource || []); }
    function labelOf(it) { return typeof it === 'string' ? it : it.label; }

    function render() {
      var q = input.value.trim().toLowerCase();
      var all = items();
      var matches = (q === '' ? all : all.filter(function (it) { return labelOf(it).toLowerCase().indexOf(q) !== -1; })).slice(0, 30);
      dd.innerHTML = '';
      matches.forEach(function (it) {
        var el = document.createElement('div');
        el.className = 'combo-option';
        el.textContent = labelOf(it);
        el.addEventListener('mousedown', function (e) {
          e.preventDefault();
          input.value = labelOf(it);
          dd.style.display = 'none';
          if (opts.onSelect) opts.onSelect(it);
          input.dispatchEvent(new Event('change'));
        });
        dd.appendChild(el);
      });
      var exact = all.some(function (it) { return labelOf(it).toLowerCase() === q; });
      if (q !== '' && !exact) {
        var el = document.createElement('div');
        el.className = 'combo-option new';
        el.textContent = '+ Buat baru: "' + input.value.trim() + '"';
        el.addEventListener('mousedown', function (e) {
          e.preventDefault();
          dd.style.display = 'none';
        });
        dd.appendChild(el);
      }
      dd.style.display = dd.children.length ? 'block' : 'none';
    }

    input.addEventListener('focus', render);
    input.addEventListener('input', render);
    input.addEventListener('blur', function () { setTimeout(function () { dd.style.display = 'none'; }, 150); });
  };

  // Input Rupiah — auto-format delimiter ribuan pas ngetik ("150000" -> "150.000"),
  // gak ada desimal/",00". Value asli (digit polos, tanpa titik) yang dikirim pas submit,
  // biar backend yang masih pakai (float) $_POST[...] tetap kebaca bener.
  window.initRupiahInput = function (input) {
    if (!input || input.dataset.rupiahInit) return;
    input.dataset.rupiahInit = '1';
    input.type = 'text';
    input.setAttribute('inputmode', 'numeric');
    input.removeAttribute('step');

    function format() {
      var cursorFromEnd = input.value.length - input.selectionStart;
      var digits = input.value.replace(/[^\d]/g, '');
      input.value = digits === '' ? '' : Number(digits).toLocaleString('id-ID');
      var pos = Math.max(0, input.value.length - cursorFromEnd);
      if (document.activeElement === input) input.setSelectionRange(pos, pos);
    }

    var initial = Math.round(parseFloat(input.value) || 0);
    input.value = initial ? initial.toLocaleString('id-ID') : (input.value === '0' ? '0' : '');
    input.addEventListener('input', format);

    var form = input.closest('form');
    if (form && !form.dataset.rupiahSubmitHook) {
      form.dataset.rupiahSubmitHook = '1';
      form.addEventListener('submit', function () {
        form.querySelectorAll('.rupiah-input').forEach(function (el) {
          el.value = el.value.replace(/[^\d]/g, '');
        });
      });
    }
  };

  document.querySelectorAll('.rupiah-input').forEach(window.initRupiahInput);

  // Toggle sidebar jadi drawer di mobile (<=860px) -- tombol hamburger di
  // pojok kiri atas, scrim buat nutup pas klik di luar.
  (function () {
    var toggle = document.getElementById('mobile-nav-toggle');
    var sidebar = document.getElementById('app-sidebar');
    var scrim = document.getElementById('app-shell-scrim');
    if (!toggle || !sidebar || !scrim) return;
    function close() {
      sidebar.classList.remove('open');
      scrim.classList.remove('open');
      toggle.classList.remove('open');
    }
    toggle.addEventListener('click', function () {
      var willOpen = !sidebar.classList.contains('open');
      sidebar.classList.toggle('open', willOpen);
      scrim.classList.toggle('open', willOpen);
      toggle.classList.toggle('open', willOpen);
    });
    scrim.addEventListener('click', close);
    sidebar.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', close); });
  })();
})();
