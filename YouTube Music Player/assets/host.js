/* YouTube Music Player – Laufzeit des Browser-Players (Kachel-Gerät).
   Läuft im Visu-Hauptfenster (als <script> eingefügt, damit Timer einen Kategoriewechsel überleben),
   in den Symcon-Apps ohne Hauptfenster in der Kachel selbst. Je Instanz ein Player (IFrame-API) in einer
   schwebenden Box, sichtbar mindestens 200×200 px (YouTube API Services Policies).
   Meldet den Zustand an die eingehängte Kachel (requestAction) oder, ohne Kachel, an den WebHook der
   Instanz und holt dort auch Befehle ab. */
(function () {
  'use strict';
  if (window.__ytmHostGet) return;
  const runtimes = {};
  const API_URL = 'https://www.youtube.com/iframe_api';
  const REPORT_INTERVAL = 10000;
  const POLL_INTERVAL = 2000;
  const VIDEO_W = 356;
  const VIDEO_H = 200;
  const BAR_H = 24;
  const MIN_VIDEO = 200;

  function randomId() {
    const bytes = new Uint8Array(12);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
  }

  // IFrame-API einmal je Fenster laden
  function loadApi() {
    if (window.YT && window.YT.Player) return Promise.resolve();
    if (!window.__ytmApiPromise) {
      window.__ytmApiPromise = new Promise((resolve, reject) => {
        const tag = document.createElement('script');
        tag.src = API_URL;
        document.head.appendChild(tag);
        let tries = 0;
        const timer = setInterval(() => {
          if (window.YT && window.YT.Player) { clearInterval(timer); resolve(); }
          else if (++tries > 150) { clearInterval(timer); window.__ytmApiPromise = null; reject(new Error('iframe_api timeout')); }
        }, 100);
      });
    }
    return window.__ytmApiPromise;
  }

  function Runtime(cfg) {
    this.cfg = cfg;
    this.instance = cfg.instance;
    this.playerId = randomId();
    this.player = null;
    this.ready = null;
    this.tiles = [];           // eingehängte Kacheln dieses Browsers: {send(ident, value), render(state), notice(text), frame}
    this.visu = '';            // ID der Visualisierung, in der gespielt wird (aus der Adresse des Hauptfensters)
    this.listId = '';
    this.more = null;          // Fortsetzung langer Listen (wanderndes Fenster)
    this.extending = false;
    this.seen = new Set();     // schon geladene Titel der aktuellen Liste
    this.last = null;          // zuletzt bekannte Video-ID mit Titel (für die kurze Lücke beim Titelwechsel)
    this.lastReport = '';
    this.lastReportAt = 0;
    this.commandAfter = 0;
    this.box = null;
    this.polling = false;
    this.timer = setInterval(() => this.tick(), POLL_INTERVAL);
  }

  // Abbau beim Ersetzen durch eine neuere Laufzeit (nach einem Update des Moduls)
  Runtime.prototype.dispose = function () {
    clearInterval(this.timer);
    try { if (this.player && this.player.destroy) this.player.destroy(); } catch (e) { /* egal */ }
    if (this.box) this.box.remove();
    this.tiles = [];
  };

  // Mehrere Kacheln (Links) derselben Instanz im selben Browser zeigen denselben Player
  Runtime.prototype.liveTiles = function () {
    // ohne Hauptfenster (Apps) läuft die Laufzeit in der Kachel selbst
    this.tiles = this.tiles.filter(t => !t.frame || t.frame.isConnected);
    return this.tiles;
  };

  Runtime.prototype.tileAlive = function () {
    return this.liveTiles().length > 0;
  };

  Runtime.prototype.attach = function (tile) {
    // Eine neu geladene Kachel behält ihren Rahmen: alte Anmeldung desselben Rahmens ersetzen
    this.tiles = this.liveTiles().filter(t => !tile.frame || t.frame !== tile.frame);
    this.tiles.push(tile);
    if (tile.frame) this.visu = visuOf(location.hash);
    const s = this.state();
    if (s && tile.render) { try { tile.render(s); } catch (e) { /* egal */ } }
    this.report(true);
  };

  // An alle eingehängten Kacheln; die erste, die annimmt, meldet an die Instanz
  Runtime.prototype.toTiles = function (method, a, b) {
    let done = false;
    this.liveTiles().forEach(t => {
      if (method === 'send' && done) return;
      try { t[method](a, b); done = true; } catch (e) { /* Kachel gerade verworfen */ }
    });
    return done;
  };

  // Visualisierung verlassen (Einstiegsseite oder andere Visu): anhalten und Mini-Player ausblenden
  Runtime.prototype.leave = function () {
    this.visu = '';
    if (this.player && this.player.pauseVideo) this.player.pauseVideo();
    if (this.box) this.box.style.display = 'none';
  };

  // Box mit dem Video im Hauptfenster: schwebend (verschiebbar), über der Cover-Fläche einer Kachel
  // (Einstellung „Video anzeigen“) oder unsichtbar geparkt („Wiedergabe ohne sichtbaren Player“)
  Runtime.prototype.ensureBox = function () {
    if (this.box && this.box.isConnected) return this.box;
    const id = 'ytmBox' + this.instance;
    let box = document.getElementById(id);
    if (!box) {
      box = document.createElement('div');
      box.id = id;
      box.style.cssText = 'position:fixed;left:0;top:0;width:' + VIDEO_W + 'px;height:' + (VIDEO_H + BAR_H) + 'px;z-index:2147483000;'
        + 'background:#000;border-radius:10px;overflow:hidden;box-shadow:0 6px 24px #0009;display:none';
      const bar = document.createElement('div');
      bar.className = 'ytm-bar';
      bar.style.cssText = 'height:' + BAR_H + 'px;color:#fff;font:12px sans-serif;display:flex;align-items:center;justify-content:space-between;'
        + 'padding:0 8px;cursor:move;user-select:none;touch-action:none';
      const label = document.createElement('span');
      label.textContent = this.cfg.playerName || 'YouTube Music';
      const close = document.createElement('span');
      close.textContent = '✕';
      close.style.cursor = 'pointer';
      close.title = (this.cfg.texts && this.cfg.texts.Close) || 'Close';
      close.addEventListener('click', () => { this.exec('pause', {}); box.style.display = 'none'; });
      close.addEventListener('pointerdown', e => e.stopPropagation());
      bar.appendChild(label);
      bar.appendChild(close);
      const holder = document.createElement('div');
      holder.id = id + 'Player';
      box.appendChild(bar);
      box.appendChild(holder);
      document.body.appendChild(box);
      this.dragBox(bar);
    }
    this.box = box;
    return box;
  };

  // Box zeigen und ihre Lage laufend nachführen (Kachel scrollt, Fenstergröße ändert sich)
  Runtime.prototype.showBox = function () {
    const box = this.ensureBox();
    box.style.display = 'block';
    this.placed = '';
    if (this.looping) return;
    this.looping = true;
    const step = () => {
      if (!this.box || this.box.style.display === 'none' || !this.box.isConnected) { this.looping = false; return; }
      this.place();
      window.requestAnimationFrame(step);
    };
    window.requestAnimationFrame(step);
  };

  Runtime.prototype.place = function () {
    let t = null;
    if (this.cfg.hiddenPlayback) t = { left: -10000, top: 0, w: VIDEO_W, h: VIDEO_H, bar: false };
    else if (this.cfg.videoInTile) t = this.tileSlot();
    if (!t) { const p = this.floatPos(); t = { left: p.left, top: p.top, w: VIDEO_W, h: VIDEO_H, bar: true }; }
    const key = [t.left, t.top, t.w, t.h].map(Math.round).join(',') + t.bar;
    if (key === this.placed) return;
    this.placed = key;
    const box = this.box;
    box.style.left = Math.round(t.left) + 'px';
    box.style.top = Math.round(t.top) + 'px';
    box.style.width = Math.round(t.w) + 'px';
    box.style.height = Math.round(t.h + (t.bar ? BAR_H : 0)) + 'px';
    box.style.boxShadow = t.bar ? '0 6px 24px #0009' : 'none';
    box.firstChild.style.display = t.bar ? 'flex' : 'none';
    if (this.player && this.player.setSize) this.player.setSize(Math.round(t.w), Math.round(t.h));
  };

  // Größte sichtbare Cover-Fläche einer Kachel dieser Instanz, mindestens 200×200 px (YouTube API Services Policies)
  Runtime.prototype.tileSlot = function () {
    let best = null;
    this.liveTiles().forEach(t => {
      const s = t.slot ? t.slot() : null;
      if (!s || s.width < MIN_VIDEO || s.height < MIN_VIDEO) return;
      const f = t.frame ? t.frame.getBoundingClientRect() : { left: 0, top: 0, width: window.innerWidth, height: window.innerHeight };
      // Fläche muss ganz in der Kachel und im Fenster liegen
      if (s.left < 0 || s.top < 0 || s.right > f.width + 1 || s.bottom > f.height + 1) return;
      const left = f.left + s.left, top = f.top + s.top;
      if (left < 0 || top < 0 || left + s.width > window.innerWidth || top + s.height > window.innerHeight) return;
      if (!best || s.width * s.height > best.w * best.h) best = { left, top, w: s.width, h: s.height, bar: false };
    });
    return best;
  };

  // Gemerkte Lage der schwebenden Box (je Instanz im Browser), sonst rechts unten; immer im Fenster
  Runtime.prototype.floatPos = function () {
    if (!this.pos) {
      try { this.pos = JSON.parse(localStorage.getItem('ytm-box-' + this.instance) || 'null'); } catch (e) { this.pos = null; }
    }
    const w = window.innerWidth, h = window.innerHeight;
    const p = this.pos || { left: w - VIDEO_W - 16, top: h - VIDEO_H - BAR_H - 16 };
    return {
      left: Math.max(0, Math.min(w - VIDEO_W, p.left)),
      top: Math.max(0, Math.min(h - VIDEO_H - BAR_H, p.top)),
    };
  };

  // Schwebende Box an der Titelleiste verschieben
  Runtime.prototype.dragBox = function (bar) {
    let start = null;
    bar.addEventListener('pointerdown', e => {
      const p = this.floatPos();
      start = { x: e.clientX, y: e.clientY, left: p.left, top: p.top };
      bar.setPointerCapture(e.pointerId);
    });
    bar.addEventListener('pointermove', e => {
      if (!start) return;
      this.pos = { left: start.left + e.clientX - start.x, top: start.top + e.clientY - start.y };
      this.place();
    });
    const end = () => {
      if (!start) return;
      start = null;
      this.pos = this.floatPos();
      try { localStorage.setItem('ytm-box-' + this.instance, JSON.stringify(this.pos)); } catch (e) { /* kein Speicher */ }
    };
    bar.addEventListener('pointerup', end);
    bar.addEventListener('pointercancel', end);
  };

  Runtime.prototype.ensurePlayer = function () {
    if (this.ready) return this.ready;
    this.ready = loadApi().then(() => new Promise(resolve => {
      const box = this.ensureBox();
      this.showBox();
      this.player = new window.YT.Player(box.id + 'Player', {
        width: VIDEO_W,
        height: VIDEO_H,
        // Bedienung macht die Kachel: ohne Bedienleiste des Players (offizielle Parameter, nichts wird überdeckt);
        // Klicks im Player selbst lassen sich nicht abfangen (anderer Ursprung)
        playerVars: { playsinline: 1, rel: 0, autoplay: 1, controls: 0, fs: 0, iv_load_policy: 3, disablekb: 1, origin: location.origin },
        events: {
          onReady: () => { this.placed = ''; resolve(this.player); },
          onStateChange: e => {
            this.report(true);
            // Letzter Titel des Fensters zu Ende: nächstes Fenster der Liste laden
            if (e && e.data === 0 && this.atWindowEnd()) this.extend();
          },
          onError: e => this.error(e && e.data),
        },
      });
    })).catch(e => { this.ready = null; this.notice(String(e && e.message || e)); throw e; });
    return this.ready;
  };

  // Befehle wie bei der Lounge: play, pause, next, previous, seekTo, setVolume, setPlaylist
  Runtime.prototype.exec = function (command, args) {
    args = args || {};
    if (command !== 'setPlaylist' && !this.player) return Promise.resolve(false);
    return this.ensurePlayer().then(p => {
      // Der Player muss beim Abspielen sichtbar sein, auch nachdem die Box geschlossen wurde
      if (command === 'setPlaylist' || command === 'play') this.showBox();
      switch (command) {
        case 'setPlaylist': {
          const start = Number(args.currentTime) || 0;
          this.listId = String(args.listId || '');
          const ids = Array.isArray(args.videoIds) ? args.videoIds.filter(id => /^[A-Za-z0-9_-]{11}$/.test(id)) : [];
          // Lange Listen kommen als Fenster; more sagt dem Modul, wo es weitergeht
          this.more = ids.length && args.more && typeof args.more === 'object' ? args.more : null;
          this.seen = new Set();
          if (ids.length) {
            // Vom Modul aufgelöste Liste (persönliche Listen, Start beim gewählten Titel)
            p.loadPlaylist(ids, Math.max(0, Math.min(ids.length - 1, Number(args.index) || 0)), start);
          } else if (this.listId) {
            // Radio eines Titels bildet YouTube selbst; sonst beginnt die Liste beim ersten Eintrag
            p.loadPlaylist({ list: this.listId, listType: 'playlist', index: 0, startSeconds: start });
          } else if (args.videoId) {
            p.loadVideoById({ videoId: String(args.videoId), startSeconds: start });
          }
          break;
        }
        case 'play': p.playVideo(); break;
        case 'pause': p.pauseVideo(); break;
        case 'next': if (this.atWindowEnd()) this.extend(); else p.nextVideo(); break;
        case 'previous': p.previousVideo(); break;
        case 'seekTo': p.seekTo(Number(args.newTime) || 0, true); break;
        case 'setVolume': p.setVolume(Math.max(0, Math.min(100, Number(args.volume) || 0))); if (p.isMuted()) p.unMute(); break;
        case 'mute': p.mute(); break;
        case 'unMute': p.unMute(); break;
        default: return false;
      }
      setTimeout(() => this.report(true), 300);
      return true;
    });
  };

  // Wanderndes Fenster: am Ende die nächsten Titel holen (WebHook, geht auch ohne eingehängte Kachel), die bisherigen fallen raus
  Runtime.prototype.atWindowEnd = function () {
    const p = this.player;
    if (!this.more || !p || !p.getPlaylist) return false;
    const list = p.getPlaylist() || [];
    return list.length > 0 && p.getPlaylistIndex() >= list.length - 1;
  };
  Runtime.prototype.extend = function () {
    if (this.extending || !this.more) return;
    this.extending = true;
    const loaded = (this.player && this.player.getPlaylist && this.player.getPlaylist()) || [];
    loaded.forEach(id => this.seen.add(id));
    this.hook('more', JSON.stringify(this.more)).then(answer => {
      const all = answer && Array.isArray(answer.videoIds) ? answer.videoIds.filter(id => /^[A-Za-z0-9_-]{11}$/.test(id)) : [];
      // Ein Radio wiederholt Titel zwischen den Fenstern: schon geladene weglassen
      const ids = all.filter(id => !this.seen.has(id));
      this.more = all.length && answer.more ? answer.more : null;
      if (this.seen.size > 1000) this.seen = new Set(loaded);
      if (ids.length && this.player) this.player.loadPlaylist(ids, 0, 0);
      return !ids.length && !!this.more; // nur Wiederholungen: gleich das nächste Fenster
    }).then(again => {
      this.extending = false;
      if (again) this.extend();
    }, () => { this.extending = false; });
  };

  Runtime.prototype.state = function () {
    const p = this.player;
    if (!p || !p.getPlayerState) return null;
    const data = p.getVideoData ? p.getVideoData() : {};
    const list = p.getPlaylist ? (p.getPlaylist() || []) : [];
    const index = p.getPlaylistIndex ? p.getPlaylistIndex() : -1;
    // Beim Titelwechsel meldet der Player kurz keine Video-ID: aus der Liste nehmen, sonst die letzte bekannte
    // (sonst zeigen die Kacheln kurz „Keine Wiedergabe“ und die Warteschlange baut sich neu auf)
    let videoId = (data && data.video_id) || (index >= 0 && list[index]) || '';
    if (videoId) {
      const same = this.last && this.last.videoId === videoId;
      this.last = {
        videoId,
        title: (data && data.title) || (same ? this.last.title : ''),
        author: (data && data.author) || (same ? this.last.author : ''),
      };
    } else if (this.last && p.getPlayerState() !== 0) {
      videoId = this.last.videoId;
    }
    const known = this.last && this.last.videoId === videoId ? this.last : {};
    return {
      playerId: this.playerId,
      videoId,
      listId: list.length ? this.listId : '',
      state: p.getPlayerState(),
      currentTime: p.getCurrentTime() || 0,
      duration: p.getDuration() || 0,
      volume: p.getVolume(),
      muted: p.isMuted(),
      hasPrevious: index > 0,
      hasNext: list.length > 0 && (index < list.length - 1 || !!this.more),
      title: known.title || '',
      author: known.author || '',
    };
  };

  // An die Kachel bzw. den WebHook; bei Änderung sofort, beim Spielen alle 10 s
  Runtime.prototype.report = function (force) {
    const s = this.state();
    if (!s) return;
    const key = [s.videoId, s.state, s.volume, s.muted, s.hasPrevious, s.hasNext].join('|');
    const now = Date.now();
    if (!force && key === this.lastReport && (s.state !== 1 || now - this.lastReportAt < REPORT_INTERVAL)) return;
    this.lastReport = key;
    this.lastReportAt = now;
    this.toTiles('render', s);
    const json = JSON.stringify(s);
    if (this.toTiles('send', 'TileState', json)) return;
    this.hook('state', json);
  };

  Runtime.prototype.hook = function (type, data) {
    if (!this.cfg.hook || !this.cfg.secret) return Promise.resolve(null);
    const body = new URLSearchParams({ secret: this.cfg.secret, type: type, data: data || '', after: String(this.commandAfter) });
    return fetch(this.cfg.hook, { method: 'POST', body: body, credentials: 'same-origin', cache: 'no-store' })
      .then(r => r.ok ? r.json() : null)
      .then(answer => { if (answer && Array.isArray(answer.commands)) answer.commands.forEach(c => this.command(c)); return answer; })
      .catch(() => null);
  };

  // Befehl aus der Instanz (über die Kachel oder den WebHook); jede ID nur einmal
  Runtime.prototype.command = function (c) {
    // Ohne playerId gilt der Befehl allen Browser-Playern der Instanz (Skriptbefehle)
    if (!c || (c.playerId && c.playerId !== this.playerId) || !(c.id > this.commandAfter)) return;
    this.commandAfter = c.id;
    this.exec(String(c.command), c.args || {});
  };

  Runtime.prototype.tick = function () {
    if (this.visu && visuOf(location.hash) !== this.visu) this.leave();
    const s = this.state();
    if (!s) return;
    this.report(false);
    // Ohne eingehängte Kachel kommen Befehle nur über den WebHook
    if (!this.tileAlive() && (s.state === 1 || s.state === 2 || s.state === 3) && !this.polling) {
      this.polling = true;
      this.hook('poll', '').finally(() => { this.polling = false; });
    }
  };

  Runtime.prototype.error = function (code) {
    const t = this.cfg.texts || {};
    const text = (code === 150 || code === 101) && /^(\d{1,3}\.){3}\d{1,3}$|^\[/.test(location.hostname)
      ? t['The video could not be played. Open the visualization via its host name (not an IP address).']
      : (code === 150 || code === 101 ? t['Playback is not allowed for this video in embedded players.'] : 'YouTube error ' + code);
    // Symcon-Apps (ohne Hauptfenster): Titel in der YouTube-Music-App öffnen (Android/iOS leiten den Link an die App weiter)
    const s = this.state();
    const app = this.liveTiles().length > 0 && this.liveTiles().every(tile => !tile.frame);
    const link = app && (code === 150 || code === 101) && s && s.videoId ? 'https://music.youtube.com/watch?v=' + s.videoId : '';
    this.notice(text || ('YouTube error ' + code), link);
    this.toTiles('send', 'Debug', 'player error ' + code);
  };

  Runtime.prototype.notice = function (text, link) {
    this.toTiles('notice', text, link || '');
  };

  // Adresse der Kachel-Visu: #/<VisuID>/<KategorieID>…; die Einstiegsseite hat keine Visu-ID
  function visuOf(hash) {
    const m = /^#\/(\d+)/.exec(hash || '');
    return m ? m[1] : '';
  }
  const onHashChange = () => {
    Object.keys(runtimes).forEach(id => {
      const r = runtimes[id];
      if (r.visu && visuOf(location.hash) !== r.visu) r.leave();
    });
  };
  window.addEventListener('hashchange', onHashChange);

  window.__ytmHostDispose = function () {
    window.removeEventListener('hashchange', onHashChange);
    Object.keys(runtimes).forEach(id => { runtimes[id].dispose(); delete runtimes[id]; });
  };

  window.__ytmHostGet = function (cfg) {
    let r = runtimes[cfg.instance];
    if (!r) {
      r = new Runtime(cfg);
      runtimes[cfg.instance] = r;
    } else if (cfg.secret) {
      r.cfg = cfg; // neuer Schlüssel/neue Texte nach Neuladen der Kachel
    }
    return r;
  };
})();
