// No inline scripts in Portero's screens, so a strict Content Security Policy keeps working.
(function () {
  document.addEventListener('click', function (event) {
    var copy = event.target.closest('[data-portero-copy]');
    if (copy) {
      var value = copy.getAttribute('data-portero-copy');
      var done = function () {
        var label = copy.textContent;
        copy.textContent = copy.getAttribute('data-copied');
        copy.classList.add('pt-copied');
        setTimeout(function () { copy.textContent = label; copy.classList.remove('pt-copied'); }, 1600);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(value).then(done);
      } else {
        var area = document.createElement('textarea');
        area.value = value;
        document.body.appendChild(area);
        area.select();
        document.execCommand('copy');
        area.remove();
        done();
      }
    }
    var confirmer = event.target.closest('[data-portero-confirm]');
    if (confirmer && !window.confirm(confirmer.getAttribute('data-portero-confirm'))) {
      event.preventDefault();
    }
  });
  document.addEventListener('change', function (event) {
    if (event.target.matches('[data-portero-autosubmit]')) {
      event.target.form.submit();
    }
    if (event.target.matches('input[name="source"]')) {
      showSource(event.target.value);
    }
    if (event.target.matches('input[name="preset"]')) {
      var label = event.target.getAttribute('data-label');
      var protocols = (event.target.getAttribute('data-protocols') || '').split(',');
      var name = document.querySelector('[name="name"]');
      var button = document.querySelector('[name="buttonLabel"]');
      if (name && (!name.value || name.getAttribute('data-auto') === '1')) { name.value = label; name.setAttribute('data-auto', '1'); }
      if (button && (!button.value || button.getAttribute('data-auto') === '1')) {
        button.value = button.getAttribute('data-template').replace('{provider}', label);
        button.setAttribute('data-auto', '1');
      }
      var method = document.querySelector('.pt-method');
      if (method) { method.hidden = protocols.length < 2; }
    }
  });
  // F11: Craft's "Confirm your identity" box asks for a password, which people from a provider don't have.
  var confirmTag = document.querySelector('meta[name="portero-confirm"]');
  if (confirmTag) {
    new MutationObserver(function () {
      var box = document.querySelector('.login-modal .login-modal-form');
      if (!box || box.querySelector('.portero-confirm')) { return; }
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn submit portero-confirm';
      button.textContent = confirmTag.getAttribute('data-label');
      button.addEventListener('click', function () {
        window.open(confirmTag.getAttribute('content'), 'portero-confirm', 'width=560,height=720');
      });
      box.insertBefore(button, box.firstChild);
    }).observe(document.body, {childList: true, subtree: true});
    window.addEventListener('message', function (event) {
      if (event.origin !== window.location.origin || !event.data || event.data.portero !== 'confirmed') { return; }
      var manager = window.Craft && window.Craft.elevatedSessionManager;
      if (manager && manager.loginModal) {
        manager.success = true;
        manager.loginModal.hide();
      }
    });
  }
  function showSource(value) {
    document.querySelectorAll('[data-source]').forEach(function (part) {
      part.hidden = part.getAttribute('data-source') !== value;
    });
  }
  document.addEventListener('DOMContentLoaded', function () {
    if (document.querySelector('[data-portero-confirmed]') && window.opener) {
      window.opener.postMessage({portero: 'confirmed'}, window.location.origin);
      window.close();
    }
    var picked = document.querySelector('input[name="source"]:checked');
    if (picked) { showSource(picked.value); }
    if (document.querySelector('.login, body.login') && document.querySelector('.portero-login')) {
      var box = document.querySelector('.alternative-login-methods');
      if (box) { box.classList.remove('hidden'); }
    }
  });
})();
