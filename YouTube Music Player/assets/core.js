/* YouTube Music Player – Kern der Kachel: Konfiguration, Größenstufen, Geräteauswahl, Anzeige, Befehle.
   Eigenes Gerät: beim Kachel-Gerät der Browser-Player (host.js), sonst die Instanz (requestAction).
   Andere Geräte am Account (laut Einstellung „Steuerbare Geräte“) bedient die Kachel über die eigene
   Instanz, die die Freigabe prüft; deren Zustand kommt als remoteState. */
(function () {
  'use strict';
  const cfg = window.YTM_CONFIG || {};
  const texts = cfg.texts || {};
  const T = key => texts[key] || key;
  const $ = id => document.getElementById(id);
  const app = $('app');
  const own = cfg.instance;
  const isTileDevice = cfg.deviceType === 0;
  let state = cfg.state || {};          // Zustand des eigenen Geräts
  let stateAt = Date.now();
  // Metadaten je videoId: beim Kachel-Gerät spielt jede Visu ihren eigenen Titel, der Server meldet sie für alle
  const metaCache = {};
  const metaRequested = {};
  if (cfg.state && cfg.state.videoId && cfg.meta && cfg.meta.title) metaCache[cfg.state.videoId] = cfg.meta;
  let runtime = null;
  let views = null;
  // Listen, die das Modul gerade auflöst (Anfrage-ID → Argumente von setPlaylist)
  const pendingLists = {};
  let seeking = false;

  // Steuerbare Geräte: id → {id, name, type, own, state, at}; Zeitpunkt des Zustands in Browser-Zeit
  const devices = {};
  function setDevices(list, serverTime) {
    const offset = Date.now() - (Number(serverTime) || 0) * 1000;
    Object.keys(devices).forEach(id => delete devices[id]);
    (list || []).forEach(d => {
      const s = d.state || {};
      devices[d.id] = Object.assign({}, d, { state: s, at: s.time ? Number(s.time) * 1000 + offset : Date.now() });
    });
    if (!devices[own]) devices[own] = { id: own, name: cfg.playerName || '', type: cfg.deviceType, own: true, state: {}, at: Date.now() };
  }
  setDevices(cfg.devices, cfg.serverTime);

  // Gewähltes Gerät je Instanz im Browser-Tab merken
  const selKey = 'ytm-device-' + own;
  let selected = own;
  try { selected = Number(sessionStorage.getItem(selKey)) || own; } catch (e) { /* kein Speicher */ }
  if (!devices[selected]) selected = own;
  function select(id) {
    selected = devices[id] ? id : own;
    try { sessionStorage.setItem(selKey, String(selected)); } catch (e) { /* kein Speicher */ }
    render();
  }

  // Angezeigtes Gerät: eigenes (lokaler Zustand) oder fremdes (remoteState mit Metadaten)
  function current() {
    if (selected !== own && devices[selected]) {
      const d = devices[selected];
      return { s: d.state || {}, at: d.at, meta: d.state || {} };
    }
    return { s: state, at: stateAt, meta: ownMeta() };
  }
  function ownMeta() {
    const id = state.videoId || '';
    if (!id) return {};
    if (!metaCache[id] && !metaRequested[id]) { metaRequested[id] = true; action('Metadata', id); }
    return metaCache[id] || {};
  }

  // Symbole als SVG (24×24)
  const PATHS = {
    play: 'M8 5.5v13a1 1 0 0 0 1.5.86l10.5-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5z',
    pause: 'M7 5h3.5v14H7zM13.5 5H17v14h-3.5z',
    prev: 'M6 5h2.5v14H6zM19 5.6v12.8a1 1 0 0 1-1.55.83L9.7 13.66a2 2 0 0 1 0-3.32l7.75-5.57A1 1 0 0 1 19 5.6z',
    next: 'M15.5 5H18v14h-2.5zM5 5.6v12.8a1 1 0 0 0 1.55.83l7.75-5.57a2 2 0 0 0 0-3.32L6.55 4.77A1 1 0 0 0 5 5.6z',
    heart: 'M12 20.5s-7.5-4.6-9.6-9.2C.9 7.9 3 4.5 6.5 4.5c2.1 0 3.6 1.2 4.5 2.7.9-1.5 2.4-2.7 4.5-2.7 3.5 0 5.6 3.4 4.1 6.8-2.1 4.6-9.6 9.2-9.6 9.2zm0-2.4c2.5-1.7 6.3-4.6 7.7-7.6 1-2.2-.3-4.3-2.6-4.3-1.7 0-3 1.2-3.8 2.8L12 11l-1.3-2c-.8-1.6-2.1-2.8-3.8-2.8-2.3 0-3.6 2.1-2.6 4.3 1.4 3 5.2 5.9 7.7 7.6z',
    heartOn: 'M12 20.5s-7.5-4.6-9.6-9.2C.9 7.9 3 4.5 6.5 4.5c2.1 0 3.6 1.2 4.5 2.7.9-1.5 2.4-2.7 4.5-2.7 3.5 0 5.6 3.4 4.1 6.8-2.1 4.6-9.6 9.2-9.6 9.2z',
    volume: 'M4 9.5v5h3.5l4.5 4V5.5l-4.5 4zM15 8.2a5 5 0 0 1 0 7.6l-1.1-1.3a3.3 3.3 0 0 0 0-5zM17.6 5.3a9 9 0 0 1 0 13.4l-1.1-1.3a7.3 7.3 0 0 0 0-10.8z',
    muted: 'M4 9.5v5h3.5l4.5 4V5.5l-4.5 4zM15.3 9.4l1.3-1.3 2.1 2.1 2.1-2.1 1.3 1.3-2.1 2.1 2.1 2.1-1.3 1.3-2.1-2.1-2.1 2.1-1.3-1.3 2.1-2.1z',
    device: 'M3 5h18v11H3zm1.8 1.8v7.4h14.4V6.8zM8 19h8v1.6H8z',
    cast: 'M3 5h18v14h-7v-1.8h5.2V6.8H4.8V9H3zm0 6a10 10 0 0 1 10 10h-1.8A8.2 8.2 0 0 0 3 12.8zm0 3.6A6.4 6.4 0 0 1 9.4 21H7.6A4.6 4.6 0 0 0 3 16.4zM3 18a3 3 0 0 1 3 3H3z',
    transfer: 'M4 11.1h12.3l-4.5-4.5L13.1 5.3 19.8 12l-6.7 6.7-1.3-1.3 4.5-4.5H4z',
    close: 'M6.4 5.1 12 10.7l5.6-5.6 1.3 1.3-5.6 5.6 5.6 5.6-1.3 1.3-5.6-5.6-5.6 5.6-1.3-1.3 5.6-5.6-5.6-5.6z',
    down: 'M5.3 8.3 12 15l6.7-6.7 1.3 1.3-8 8-8-8z',
    back: 'M15.4 5.3 8.7 12l6.7 6.7-1.3 1.3-8-8 8-8z',
    search: 'M10.5 3a7.5 7.5 0 0 1 6 12l4.3 4.3-1.4 1.4-4.3-4.3A7.5 7.5 0 1 1 10.5 3zm0 1.9a5.6 5.6 0 1 0 0 11.2 5.6 5.6 0 0 0 0-11.2z',
    home: 'M12 3.5 21 11v9.5h-6.3v-6h-5.4v6H3V11zm0 2.4-7.2 6v6.8h2.7v-6h9v6h2.7v-6.8z',
    new: 'M12 2.5l2.4 5 5.5.7-4 3.9 1 5.4L12 15l-4.9 2.5 1-5.4-4-3.9 5.5-.7zM5 19h14v1.8H5z',
    explore: 'M12 2.5a9.5 9.5 0 1 1 0 19 9.5 9.5 0 0 1 0-19zm0 1.8a7.7 7.7 0 1 0 0 15.4 7.7 7.7 0 0 0 0-15.4zm4.2 3.5-2.4 6-6 2.4 2.4-6zm-4.2 3a1.2 1.2 0 1 0 0 2.4 1.2 1.2 0 0 0 0-2.4z',
    library: 'M4 4h1.8v16H4zm4 0h1.8v16H8zm4.2.6 1.7-.6 5.6 15.1-1.7.6z',
    charts: 'M4 19h16v1.8H4zm1-7h3v6H5zm5.5-5h3v11h-3zM16 10h3v8h-3z',
    queue: 'M3 6h13v1.8H3zm0 5h13v1.8H3zm0 5h8v1.8H3zm12 0 6-3.5v7z',
    lyrics: 'M4 5h16v1.8H4zm0 4.5h16v1.8H4zm0 4.5h10v1.8H4zm13.5-1V19a2.5 2.5 0 1 1-1.8-2.4V12H21v1.8z',
  };
  const icon = name => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="' + PATHS[name] + '"/></svg>';

  function action(ident, value) { try { requestAction(ident, value); } catch (e) { /* außerhalb von Symcon */ } }
  function debug(text) { action('Debug', String(text).slice(0, 1000)); }
  window.addEventListener('error', e => debug('error: ' + (e.message || '') + ' @' + (e.lineno || 0)));

  // Laufzeit im Hauptfenster (gleicher Ursprung); ohne Zugriff (Apps) in der Kachel selbst
  function hostWindow() {
    try { if (window.top !== window && window.top.document.head) return window.top; } catch (e) { /* kein Zugriff */ }
    return window;
  }
  function startRuntime() {
    const host = hostWindow();
    // Ältere Laufzeit (vor einem Update) abbauen und durch die aktuelle ersetzen
    if (host.__ytmHostGet && host.__ytmHostVersion !== cfg.hostVersion) {
      try {
        if (host.__ytmHostDispose) host.__ytmHostDispose();
        else host.document.querySelectorAll('[id^="ytmBox"]').forEach(b => b.remove()); // Laufzeit ohne Abbau-Funktion
      } catch (e) { /* egal */ }
      host.__ytmHostGet = undefined;
    }
    if (!host.__ytmHostGet) {
      const tag = host.document.createElement('script');
      tag.textContent = $('ytmHostCode').textContent;
      host.document.head.appendChild(tag);
      host.__ytmHostVersion = cfg.hostVersion;
    }
    runtime = host.__ytmHostGet(cfg);
    runtime.attach({
      send: action,
      render: s => { state = s; stateAt = Date.now(); render(); },
      notice: notice,
      frame: host === window ? null : window.frameElement,
    });
    const s = runtime.state();
    if (s && s.videoId) {
      state = s;
    } else {
      // Ohne eigenen Titel: der zuletzt gemeldete (evtl. aus einer anderen Visu) nur zum Fortsetzen, angehalten
      state = Object.assign({}, state, { state: state.videoId ? 2 : -1 });
    }
    stateAt = Date.now();
  }

  function notice(text) {
    const n = $('notice');
    n.textContent = text;
    n.hidden = !text;
    clearTimeout(notice.timer);
    if (text) notice.timer = setTimeout(() => { n.hidden = true; }, 8000);
  }

  // Befehl an das gewählte Gerät
  function command(name, args) { commandTo(selected, name, args || {}); }

  // Befehl an ein bestimmtes Gerät: eigenes direkt, andere über die eigene Instanz (prüft die Freigabe)
  function commandTo(target, name, args) {
    if (target !== own) {
      action('Command', JSON.stringify({ target, command: name, args }));
      return;
    }
    if (!isTileDevice) {
      action('Command', JSON.stringify({ target: own, command: name, args }));
      return;
    }
    // Nach dem Neuladen der Seite hat der Browser-Player keinen Titel, die Kachel kennt den letzten vom Server
    const loaded = runtime.state();
    if ((name === 'play' || name === 'seekTo') && !(loaded && loaded.videoId) && state.videoId) {
      const at = name === 'seekTo' ? Number(args.newTime) || 0 : Math.floor(position({ s: state, at: stateAt }));
      commandTo(own, 'setPlaylist', { videoId: state.videoId, listId: state.listId || '', currentTime: at });
      return;
    }
    // Listen löst das Modul in Titel auf: der eingebettete Player kann persönliche Listen nicht laden
    // und beginnt ein Radio (RDAMVM…) nicht mit dem gewählten Titel
    if (name === 'setPlaylist' && args.listId && !args.videoIds) {
      const request = Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
      pendingLists[request] = args;
      action('ResolveList', JSON.stringify({ request, listId: args.listId, videoId: args.videoId || '' }));
      return;
    }
    runtime.exec(name, args);
  }

  // Übergabe wie Spotify Connect: Ziel startet Titel, Liste und Position, Quelle pausiert
  function transfer(from, to) {
    const c = from === own ? { s: state, at: stateAt } : { s: (devices[from] || {}).state || {}, at: (devices[from] || {}).at };
    if (!c.s.videoId) { notice(T('Nothing is playing on this device')); return; }
    commandTo(to, 'setPlaylist', { videoId: c.s.videoId, listId: c.s.listId || '', currentTime: Math.floor(position(c)) });
    commandTo(from, 'pause', {});
    select(to);
    closeDevices();
  }

  // Link oder ID → setPlaylist (watch?v=…&list=…, playlist?list=…, youtu.be/…, reine IDs)
  function parseLink(input) {
    input = String(input || '').trim();
    if (/^[A-Za-z0-9_-]{11}$/.test(input)) return { videoId: input, listId: '' };
    if (/^(PL|RD|OL|LM|VL)[A-Za-z0-9_-]+$/.test(input)) return { videoId: '', listId: input.replace(/^VL/, '') };
    let url;
    try { url = new URL(/^https?:\/\//.test(input) ? input : 'https://' + input); } catch (e) { return null; }
    let videoId = url.searchParams.get('v') || '';
    if (url.hostname === 'youtu.be') videoId = url.pathname.slice(1, 12);
    const listId = url.searchParams.get('list') || '';
    if (!/^[A-Za-z0-9_-]{11}$/.test(videoId)) videoId = '';
    if (!videoId && !listId) return null;
    return { videoId, listId };
  }

  function fmt(seconds) {
    seconds = Math.max(0, Math.round(seconds || 0));
    const h = Math.floor(seconds / 3600), m = Math.floor(seconds / 60) % 60, s = seconds % 60;
    return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(s).padStart(2, '0');
  }
  const setFill = el => el.style.setProperty('--fill', ((el.value - el.min) / (el.max - el.min) * 100) + '%');

  function position(c) {
    const s = c.s;
    const base = Number(s.currentTime !== undefined ? s.currentTime : s.position) || 0;
    const running = s.state === 1 ? (Date.now() - c.at) / 1000 : 0;
    const duration = Number(s.duration) || 0;
    return duration > 0 ? Math.min(duration, base + running) : base + running;
  }
  const isPlaying = s => s.state === 1 || s.state === 3;

  const deviceLabel = d => (d.own && isTileDevice ? T('This device') : d.name);

  function render() {
    const c = current();
    const s = c.s, meta = c.meta;
    const title = meta.title || s.title || (s.videoId ? s.videoId : T('Nothing playing'));
    const sub = [meta.artist || s.author || '', meta.album || ''].filter(Boolean).join(' · ');
    const cover = meta.cover || (s.videoId ? 'https://i.ytimg.com/vi/' + s.videoId + '/hqdefault.jpg' : '');
    const image = cover ? 'url("' + cover + '")' : '';
    $('title').textContent = title;
    $('sub').textContent = sub;
    $('miniTitle').textContent = title;
    $('miniSub').textContent = sub;
    $('art').style.backgroundImage = image;
    $('miniArt').style.backgroundImage = image;
    $('bg').style.backgroundImage = image;
    app.classList.toggle('has-cover', !!cover);
    const play = isPlaying(s) ? 'pause' : 'play';
    $('bPlay').innerHTML = icon(play);
    $('bPlay').title = T(isPlaying(s) ? 'Pause' : 'Play');
    $('miniPlay').innerHTML = icon(play);
    // Like nur, wenn der Status bekannt ist (Anmeldung im Account)
    const like = meta.like || '';
    $('bLike').hidden = !s.videoId || like === '';
    $('bLike').classList.toggle('on', like === 'LIKE');
    $('bLike').innerHTML = icon(like === 'LIKE' ? 'heartOn' : 'heart');
    $('bLike').title = T(like === 'LIKE' ? 'Remove like' : 'Like');
    if (Number(s.volume) >= 0 && document.activeElement !== $('volume')) { $('volume').value = s.volume; setFill($('volume')); }
    $('volIcon').innerHTML = icon(s.muted ? 'muted' : 'volume');
    $('volIcon').title = T(s.muted ? 'Unmute' : 'Mute');
    app.classList.toggle('muted', !!s.muted);
    // Gerät: Auswahl nur, wenn es mehr als das eigene gibt
    const d = devices[selected];
    $('devIcon').innerHTML = icon(d.type === 1 ? 'cast' : 'device');
    $('deviceName').textContent = deviceLabel(d);
    $('bDevice').classList.toggle('remote', selected !== own);
    $('bDevice').disabled = Object.keys(devices).length < 2;
    // Mini-Player der Voll-Ansicht: zeigt, wohin ein angetippter Titel geht
    $('miniDevIcon').innerHTML = $('devIcon').innerHTML;
    $('miniDevName').textContent = deviceLabel(d);
    $('miniDevice').title = T('Devices');
    $('miniDevice').classList.toggle('remote', selected !== own);
    $('miniDevice').disabled = $('bDevice').disabled;
    if (!$('devices').hidden) renderDevices();
    if (views) views.trackChanged(s.videoId || '');
    renderTime();
  }
  function renderTime() {
    const c = current();
    const duration = Number(c.s.duration) || 0;
    const pos = position(c);
    $('tCur').textContent = fmt(pos);
    $('tDur').textContent = duration > 0 ? '-' + fmt(duration - pos) : '';
    if (!seeking) { $('seek').value = duration > 0 ? Math.round(pos / duration * 1000) : 0; setFill($('seek')); }
  }

  // Geräteliste: Tipp wählt das Gerät zum Steuern, der Pfeil übergibt die Wiedergabe des gewählten Geräts dorthin
  function renderDevices() {
    const list = $('devList');
    list.textContent = '';
    const from = current().s;
    Object.values(devices).sort((a, b) => (b.own - a.own) || a.name.localeCompare(b.name)).forEach(d => {
      const s = d.own ? state : d.state || {};
      const row = document.createElement('div');
      row.className = 'dev-row' + (d.id === selected ? ' sel' : '');
      const ico = document.createElement('span');
      ico.className = 'dev-ico';
      // Typ -1: Gerät hat sich seit dem Start noch nicht beim Account gemeldet
      ico.innerHTML = icon(d.type === 1 ? 'cast' : 'device');
      const txt = document.createElement('span');
      txt.className = 'dev-txt';
      const name = document.createElement('b');
      name.textContent = deviceLabel(d);
      const info = document.createElement('small');
      const title = (d.own ? ownMeta().title : s.title) || s.title || '';
      info.textContent = s.videoId ? (isPlaying(s) ? '▶ ' : '❚❚ ') + title : T('Nothing playing');
      txt.append(name, info);
      row.append(ico, txt);
      row.onclick = () => { select(d.id); closeDevices(); };
      if (d.id !== selected && from.videoId) {
        const move = document.createElement('button');
        move.className = 'icon-btn dev-move';
        move.title = T('Transfer playback here');
        move.innerHTML = icon('transfer');
        move.onclick = e => { e.stopPropagation(); transfer(selected, d.id); };
        row.append(move);
      }
      list.append(row);
    });
  }
  function openDevices() {
    action('Devices', '');
    $('devices').hidden = false;
    renderDevices();
  }
  function closeDevices() { $('devices').hidden = true; }

  // Größenstufen nach der Größe der Kachel
  function layout() {
    const w = app.clientWidth, h = app.clientHeight;
    const size = h < 200 ? 'mini' : (w >= 640 && h >= 420 ? 'full' : 'compact');
    ['mini', 'compact', 'full'].forEach(s => app.classList.toggle('size-' + s, s === size));
    app.classList.toggle('narrow', size === 'mini' ? w < 520 : w < 460);
    app.classList.toggle('low', h < 260);
    app.classList.toggle('tiny', size === 'mini' && w < 400);
    app.classList.toggle('np-open', nowPlaying && size === 'full');
    app.classList.toggle('wide', size === 'full' && w >= 900);
    if (size === 'full' && views) views.ensure();
  }

  // „Läuft gerade“ aufgeklappt: bleibt beim Wechsel zwischen Kachel und Vollbild erhalten (die Visu baut die Kachel neu auf)
  const npKey = 'ytm-now-playing-' + own;
  let nowPlaying = false;
  try { nowPlaying = sessionStorage.getItem(npKey) === '1'; } catch (e) { /* kein Speicher */ }
  function setNowPlaying(open) {
    nowPlaying = open;
    try { sessionStorage.setItem(npKey, open ? '1' : '0'); } catch (e) { /* kein Speicher */ }
    layout();
  }
  new ResizeObserver(layout).observe(app);

  // Nachrichten der Instanz
  window.handleMessage = function (data) {
    let msg;
    try { msg = JSON.parse(data); } catch (e) { return; }
    if (!msg) return;
    switch (msg.type) {
      case 'reload':
        location.reload();
        break;
      case 'device':
        if (msg.state && msg.state.videoId && msg.meta && msg.meta.title) metaCache[msg.state.videoId] = msg.meta;
        // Kachel-Gerät: die Anzeige folgt nur dem Player dieses Browsers
        if (!isTileDevice && msg.state) { state = msg.state; stateAt = Date.now(); }
        render();
        break;
      case 'remoteState':
        if (devices[msg.device]) {
          devices[msg.device].state = msg.state || {};
          devices[msg.device].at = Date.now();
          if (selected === msg.device || !$('devices').hidden) render();
        }
        break;
      case 'devices':
        setDevices(msg.devices, msg.serverTime);
        if (!devices[selected]) selected = own;
        render();
        break;
      case 'playlist': {
        // Nur die anfragende Kachel; ohne aufgelöste Titel versucht der Player die Liste selbst
        const args = pendingLists[msg.request];
        if (!args) break;
        delete pendingLists[msg.request];
        runtime.exec('setPlaylist', Object.assign({}, args, { videoIds: msg.videoIds || [], index: msg.index || 0, more: msg.more || null }));
        break;
      }
      case 'result':
        if (views) views.onResult(msg);
        break;
      case 'meta':
        if (msg.videoId && msg.meta) metaCache[msg.videoId] = msg.meta;
        if (msg.videoId === state.videoId) render();
        break;
      case 'command':
        if (runtime) runtime.command(msg);
        break;
    }
  };

  const toggle = () => command(isPlaying(current().s) ? 'pause' : 'play');
  $('bPrev').innerHTML = icon('prev');
  $('bNext').innerHTML = icon('next');
  $('miniNext').innerHTML = icon('next');
  $('npClose').innerHTML = icon('down');
  $('devClose').innerHTML = icon('close');
  $('bQueue').innerHTML = icon('queue');
  $('bLyrics').innerHTML = icon('lyrics');
  $('bQueue').title = T('Queue');
  $('bLyrics').title = T('Lyrics');
  $('bQueue').onclick = () => { setNowPlaying(false); views.show('queue'); };
  $('bLyrics').onclick = () => { setNowPlaying(false); views.show('lyrics'); };
  $('bPrev').title = T('Previous');
  $('bNext').title = T('Next');
  $('devTitle').textContent = T('Devices');
  $('bPrev').onclick = () => command('previous');
  $('bNext').onclick = () => command('next');
  $('bPlay').onclick = toggle;
  $('volIcon').onclick = () => command(current().s.muted ? 'unMute' : 'mute');
  $('miniPlay').onclick = e => { e.stopPropagation(); toggle(); };
  $('miniNext').onclick = e => { e.stopPropagation(); command('next'); };
  $('mini').onclick = () => setNowPlaying(true);
  $('npClose').onclick = () => setNowPlaying(false);
  $('bDevice').onclick = openDevices;
  $('miniDevice').onclick = e => { e.stopPropagation(); openDevices(); };
  $('devClose').onclick = closeDevices;
  $('volume').oninput = e => setFill(e.target);
  $('volume').onchange = e => command('setVolume', { volume: Number(e.target.value) });
  $('seek').oninput = e => { seeking = true; setFill(e.target); const d = Number(current().s.duration) || 0; $('tCur').textContent = fmt(e.target.value / 1000 * d); };
  $('seek').onchange = e => {
    seeking = false;
    const d = Number(current().s.duration) || 0;
    if (d > 0) command('seekTo', { newTime: Math.round(e.target.value / 1000 * d) });
  };
  $('bLike').onclick = () => {
    const c = current();
    if (!c.s.videoId) return;
    const rating = c.meta.like === 'LIKE' ? 'none' : 'like';
    action('Rating', JSON.stringify({ videoId: c.s.videoId, rating }));
    // Fremdes Gerät meldet die neue Bewertung erst mit seinem nächsten Zustand
    if (selected !== own) { c.s.like = rating === 'like' ? 'LIKE' : 'INDIFFERENT'; render(); }
  };
  // Inhalte der Voll-Ansicht; abgespielt wird auf dem gewählten Gerät
  views = window.YTMViews({
    T, icon, action, parseLink,
    play: args => command('setPlaylist', args),
    current: () => current().s,
    storageKey: 'ytm-view-' + own,
  });

  if (isTileDevice) startRuntime();
  // Fehlersuche: YouTube sperrt eingebettete Musikvideos, wenn die Visu über eine IP-Adresse geöffnet ist
  debug('tile loaded: origin ' + location.origin + ', main window ' + (hostWindow() !== window ? 'yes' : 'no') + ', app ' + (window.RequestAction ? 'yes' : 'no'));
  layout();
  render();
  setInterval(renderTime, 1000);
})();
