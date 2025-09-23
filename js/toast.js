(function () {
  // Crea el contenedor si no existe
  function stack() {
    let el = document.querySelector('.toast-stack');
    if (!el) {
      el = document.createElement('div');
      el.className = 'toast-stack';
      document.body.appendChild(el);
    }
    return el;
  }

  // Muestra un toast
  function showToast(msg, type = 'ok', opts = {}) {
    if (!msg) return;
    const lifeMs = opts.lifeMs || 4000;

    const t = document.createElement('div');
    t.className = `toast ${type}`;
    t.innerHTML = `
      <button class="x" aria-label="Cerrar">&times;</button>
      <div class="msg">${msg}</div>
      <div class="life"></div>
    `;

    const holder = stack();
    holder.appendChild(t);

    const close = () => {
      if (!t) return;
      t.style.transition = 'opacity .15s ease, transform .15s ease';
      t.style.opacity = '0';
      t.style.transform = 'translate(-50%,-6px)';
      setTimeout(() => t.remove(), 180);
    };

    t.querySelector('.x').addEventListener('click', close);
    const timer = setTimeout(close, lifeMs);
    t.addEventListener('mouseenter', () => { t.querySelector('.life').style.animationPlayState='paused'; clearTimeout(timer); });
    t.addEventListener('mouseleave', () => { setTimeout(close, 1200); t.querySelector('.life').style.animationPlayState='running'; });

    return close;
  }

  // Exponer global
  window.showToast = showToast;

  // Consumir flashes server-side si existen
  document.addEventListener('DOMContentLoaded', () => {
    const F = window.__FLASH__ || {};
    if (F.ok)  showToast(F.ok,  'ok');
    if (F.err) showToast(F.err, 'err');
  });
})();
