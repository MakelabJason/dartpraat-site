// Speler (mp3 uit de feed), YouTube pas laden na een klik (youtube-nocookie), e-mailadres kopiëren.
(function () {
  'use strict';
  // Cache verlopen en de server kan niet na het antwoord doorwerken: laat refresh.php verversen, los van deze pagina.
  var refresh = document.body.dataset.refresh;
  if (refresh) {
    try { if (!navigator.sendBeacon(refresh)) throw 0; } catch (_) { fetch(refresh, { method: 'POST', keepalive: true }).catch(function () {}); }
  }

  var player = document.getElementById('player');
  var audio = document.getElementById('audio');
  var title = document.getElementById('player-title');

  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest('a[data-audio], a[data-yt]');
    if (!a) return;
    if (a.dataset.yt && /^[A-Za-z0-9_-]{11}$/.test(a.dataset.yt)) {
      e.preventDefault();
      var f = document.createElement('iframe');
      f.src = 'https://www.youtube-nocookie.com/embed/' + a.dataset.yt + '?autoplay=1&rel=0';
      f.title = a.getAttribute('aria-label') || 'YouTube';
      f.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
      f.allowFullscreen = true;
      var box = document.createElement('div');
      box.className = 'feature playing';
      box.appendChild(f);
      a.replaceWith(box);
      if (!audio.paused) audio.pause();
      return;
    }
    if (a.dataset.audio && /^https?:\/\//.test(a.dataset.audio) && audio.canPlayType('audio/mpeg')) {
      e.preventDefault();
      if (audio.getAttribute('src') !== a.dataset.audio) audio.src = a.dataset.audio;
      title.textContent = a.dataset.title || '';
      player.hidden = false;
      document.body.classList.add('has-player');
      audio.play().catch(function () {});
    }
  });

  document.getElementById('player-close').addEventListener('click', function () {
    audio.pause();
    player.hidden = true;
    document.body.classList.remove('has-player');
  });

  var copy = document.getElementById('copy');
  if (copy) copy.addEventListener('click', function () {
    var mail = document.getElementById('mail');
    var text = mail.textContent;
    var done = function () { copy.textContent = 'Gekopieerd'; setTimeout(function () { copy.textContent = 'Kopieer'; }, 1800); };
    var fallback = function () { var r = document.createRange(); r.selectNodeContents(mail); var s = getSelection(); s.removeAllRanges(); s.addRange(r); copy.textContent = 'Geselecteerd'; };
    try { navigator.clipboard.writeText(text).then(done, fallback); } catch (_) { fallback(); }
  });
})();
