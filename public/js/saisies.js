/* saisies.js — Saisies hub page interactivity.
 * Loaded by public/modules/saisies.php.
 *
 * Behaviour:
 *   "Démarrer (session)" button on the Transferts card:
 *     POSTs to /api/racking-session-start.php with the CSRF token,
 *     then follows the redirect to the new session shell.
 */
'use strict';

(function () {
  var btn = document.getElementById('sh-start-racking-session');
  if (!btn) return;

  btn.addEventListener('click', function () {
    btn.disabled = true;
    btn.textContent = 'Ouverture…';

    var csrf = btn.dataset.csrf || '';

    fetch('/api/racking-session-start.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ csrf: csrf }),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok && data.redirect) {
          window.location.href = data.redirect;
        } else {
          alert('Erreur : ' + (data.error || 'Impossible de démarrer la session.'));
          btn.disabled = false;
          btn.textContent = 'Démarrer (session) ⟶';
        }
      })
      .catch(function () {
        alert('Erreur réseau. Réessayez.');
        btn.disabled = false;
        btn.textContent = 'Démarrer (session) ⟶';
      });
  });
}());
