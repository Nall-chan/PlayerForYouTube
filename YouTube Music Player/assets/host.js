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
    this.liveTiles();
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

  // Schwebende Box mit dem Video (rechts unten im Hauptfenster)
  Runtime.prototype.ensureBox = function () {
    if (this.box && this.box.isConnected) return this.box;
    const id = 'ytmBox' + this.instance;
    let box = document.getElementById(id);
    if (!box) {
      box = document.createElement('div');
      box.id = id;
      box.style.cssText = 'position:fixed;right:16px;bottom:16px;width:356px;height:224px;z-index:2147483000;background:#000;'
        + 'border-radius:10px;overflow:hidden;box-shadow:0 6px 24px #0009;display:none';
      const bar = document.createElement('div');
      bar.style.cssText = 'height:24px;color:#fff;font:12px sans-serif;display:flex;align-items:center;justify-content:space-between;padding:0 8px';
      const label = document.createElement('span');
      label.textContent = this.cfg.playerName || 'YouTube Music';
      const close = document.createElement('span');
      close.textContent = '✕';
      close.style.cursor = 'pointer';
      close.title = (this.cfg.texts && this.cfg.texts.Close) || 'Close';
      close.addEventListener('click', () => { this.exec('pause', {}); box.style.display = 'none'; });
      bar.appendChild(label);
      bar.appendChild(close);
      const holder = document.createElement('div');
      holder.id = id + 'Player';
      box.appendChild(bar);
      box.appendChild(holder);
      document.body.appendChild(box);
    }
    this.box = box;
    return box;
  };

  Runtime.prototype.ensurePlayer = function () {
    if (this.ready) return this.ready;
    this.ready = loadApi().then(() => new Promise(resolve => {
      const box = this.ensureBox();
      box.style.display = 'block';
      this.player = new window.YT.Player(box.id + 'Player', {
        width: 356,
        height: 200,
        playerVars: { playsinline: 1, rel: 0, autoplay: 1, origin: location.origin },
        events: {
          onReady: () => resolve(this.player),
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
      if (command === 'setPlaylist' || command === 'play') this.ensureBox().style.display = 'block';
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
          this.ensureBox().style.display = 'block';
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
    return {
      playerId: this.playerId,
      videoId: (data && data.video_id) || '',
      listId: list.length ? this.listId : '',
      state: p.getPlayerState(),
      currentTime: p.getCurrentTime() || 0,
      duration: p.getDuration() || 0,
      volume: p.getVolume(),
      muted: p.isMuted(),
      hasPrevious: index > 0,
      hasNext: list.length > 0 && (index < list.length - 1 || !!this.more),
      title: (data && data.title) || '',
      author: (data && data.author) || '',
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
    this.notice(text || ('YouTube error ' + code));
    this.toTiles('send', 'Debug', 'player error ' + code);
  };

  Runtime.prototype.notice = function (text) {
    this.toTiles('notice', text);
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
