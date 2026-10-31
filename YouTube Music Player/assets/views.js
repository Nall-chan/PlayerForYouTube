/* YouTube Music Player – Inhalte der Voll-Ansicht: Suche, Start, Neu, Entdecken, Mediathek, Detailseiten,
   Warteschlange, Liedtext. Daten kommen über die Instanz (requestAction 'Query' → handleMessage 'result'),
   abgespielt wird immer auf dem in der Kachel gewählten Gerät. */
window.YTMViews = function (api) {
  'use strict';
  const T = api.T;
  const $ = id => document.getElementById(id);
  const CACHE_MS = 10 * 60 * 1000;
  const PLAYABLE = ['song', 'video', 'episode'];
  const FILTERS = [['', 'All'], ['song', 'Songs'], ['video', 'Videos'], ['album', 'Albums'], ['artist', 'Artists'], ['playlist', 'Playlists']];
  const MAIN = {
    search: { title: 'Search', icon: 'search' },
    home: { title: 'Start', icon: 'home', query: { kind: 'browse', id: 'FEmusic_home' } },
    new: { title: 'New', icon: 'new', query: { kind: 'browse', id: 'FEmusic_new_releases' } },
    explore: { title: 'Explore', icon: 'explore', query: { kind: 'browse', id: 'FEmusic_moods_and_genres' } },
    charts: { title: 'Charts', icon: 'charts', query: { kind: 'browse', id: 'FEmusic_charts' } },
    library: { title: 'Library', icon: 'library', query: { kind: 'browse', id: 'FEmusic_liked_playlists' } },
    queue: { title: 'Queue', icon: 'queue' },
    lyrics: { title: 'Lyrics', icon: 'lyrics' },
  };
  const pending = {};      // Anfrage-ID → Rückruf
  const cache = {};        // Schlüssel → {at, data}
  let stack = [];          // geöffnete Seiten, die letzte ist sichtbar
  let search = { query: '', filter: '' };
  let shownTrack = '';     // Titel, zu dem Warteschlange/Liedtext gerade angezeigt werden

  // Anfrage an die Instanz; Antworten aus dem Zwischenspeicher, solange sie frisch sind
  function query(q, done, useCache) {
    const key = JSON.stringify(q);
    const hit = cache[key];
    if (useCache !== false && hit && Date.now() - hit.at < CACHE_MS) { done(hit.data, ''); return; }
    const request = Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
    pending[request] = (data, error) => {
      if (data && useCache !== false) cache[key] = { at: Date.now(), data };
      done(data, error);
    };
    api.action('Query', JSON.stringify(Object.assign({ request }, q)));
  }
  function onResult(msg) {
    const cb = pending[msg.request];
    if (!cb) return;
    delete pending[msg.request];
    cb(msg.data || null, msg.error || '');
  }

  // Navigation
  function renderNav() {
    const nav = $('nav');
    nav.textContent = '';
    Object.keys(MAIN).forEach(name => {
      const b = document.createElement('button');
      b.className = 'nav-item' + (stack[0] && stack[0].main === name ? ' sel' : '');
      b.innerHTML = api.icon(MAIN[name].icon) + '<span></span>';
      b.lastChild.textContent = T(MAIN[name].title);
      b.title = T(MAIN[name].title);
      b.onclick = () => show(name);
      nav.append(b);
    });
  }
  function show(name) {
    if (!MAIN[name]) name = 'home';
    try { sessionStorage.setItem(api.storageKey, name); } catch (e) { /* kein Speicher */ }
    stack = [{ main: name, title: T(MAIN[name].title), query: MAIN[name].query }];
    renderNav();
    renderPage();
  }
  function open(page) {
    stack.push(page);
    renderPage();
  }
  function back() {
    if (stack.length > 1) { stack.pop(); renderPage(); }
  }

  // Seite aufbauen
  function renderPage() {
    const page = stack[stack.length - 1];
    $('vBack').hidden = stack.length < 2;
    $('vTitle').textContent = page.title || '';
    $('vSub').textContent = page.subtitle || '';
    const listId = page.playlistId || '';
    $('vPlay').hidden = !listId;
    $('vPlay').lastChild.textContent = T('Play');
    $('vPlay').onclick = () => api.play({ videoId: '', listId });
    const body = $('vBody');
    body.textContent = '';
    body.scrollTop = 0;
    if (page.main === 'search') { renderSearch(body); return; }
    if (page.main === 'queue') { renderQueue(body); return; }
    if (page.main === 'lyrics') { renderLyrics(body); return; }
    load(body, page.query, data => renderShelves(body, data, page));
  }

  // quiet: bisherigen Inhalt stehen lassen, bis die Antwort da ist (Titelwechsel in Warteschlange/Liedtext)
  function load(body, q, render, quiet) {
    const page = stack[stack.length - 1];
    if (!quiet) status(body, T('Loading…'));
    query(q, (data, error) => {
      if (stack[stack.length - 1] !== page) return; // inzwischen weiter navigiert
      const top = body.scrollTop;
      body.textContent = '';
      if (quiet) body.scrollTop = top;
      if (!data) { status(body, error || T('Nothing found')); return; }
      render(data);
    });
  }
  function status(body, text) {
    const p = document.createElement('p');
    p.className = 'status';
    p.textContent = text;
    body.append(p);
  }

  // Regale: Titel als Liste (Detailseiten, Suche), sonst als Kartenreihe
  // seen: schon gezeigte Einträge der Seite; Fortsetzungen hängen nur Neues an (der TV-Client wiederholt Einträge)
  function renderShelves(body, data, page, seen) {
    seen = seen || new Set();
    // Stimmungen/Genres teilen sich die browseId und unterscheiden sich nur in params
    const key = i => i.videoId || (i.browseId ? i.browseId + '|' + (i.params || '') : '') || i.playlistId || i.title;
    const continued = seen.size > 0;
    // Reiter (Mediathek mit Anmeldung): Auswahl wie die Filter der Suche, jeder Reiter lädt seinen Inhalt nach
    if (!continued && (data.tabs || []).length && data.client) {
      const chips = document.createElement('div');
      chips.className = 'chips';
      const results = document.createElement('div');
      // Gewählter Reiter je Kachel gemerkt (wie der Bereich)
      const tabKey = api.storageKey + '-tab';
      let remembered = '';
      try { remembered = sessionStorage.getItem(tabKey) || ''; } catch (e) { /* kein Speicher */ }
      let restore = null;
      data.tabs.forEach(tab => {
        const c = text('button', tab.title, 'chip' + (tab.selected ? ' sel' : ''));
        if (remembered === tab.title && !tab.selected) restore = c;
        c.onclick = () => {
          try { sessionStorage.setItem(tabKey, tab.title); } catch (e) { /* kein Speicher */ }
          chips.querySelectorAll('.chip').forEach(x => x.classList.toggle('sel', x === c));
          results.textContent = '';
          status(results, T('Loading…'));
          query({ kind: 'more', token: tab.token, client: data.client }, (next, error) => {
            if (stack[stack.length - 1] !== page || !c.classList.contains('sel')) return;
            results.textContent = '';
            if (next) renderShelves(results, Object.assign({}, next, { tabs: [] }), page); else status(results, error || T('Nothing found'));
          });
        };
        chips.append(c);
      });
      body.append(chips, results);
      if (restore) { restore.click(); return; }
      body = results;
    }
    const shelves = (data.shelves || []).map(s => Object.assign({}, s, { items: (s.items || []).filter(i => {
      const k = key(i);
      if (k && seen.has(k)) return false;
      if (k) seen.add(k);
      return true;
    }) })).filter(s => s.items.length);
    if (!shelves.length) { if (!continued) status(body, T('Nothing found')); return; }
    // Künstlerseite: Radio zum ersten Top-Song (eine eigene Radio-ID des Künstlers liefert der Parser nicht)
    if (page.artist && stack[stack.length - 1] === page) {
      const first = shelves.flatMap(s => s.items).find(i => i.videoId && PLAYABLE.includes(i.type));
      if (first) {
        $('vPlay').hidden = false;
        $('vPlay').lastChild.textContent = T('Radio');
        $('vPlay').onclick = () => api.play({ videoId: first.videoId, listId: 'RDAMVM' + first.videoId });
      }
    }
    shelves.forEach(shelf => {
      const items = shelf.items;
      if (shelf.title) {
        const h = document.createElement('h2');
        h.textContent = shelf.title;
        body.append(h);
      }
      // Stimmungen und Genres haben keine Bilder: als Raster von Schaltflächen
      if (items.every(i => i.type === 'category')) { body.append(categories(items, page)); return; }
      const songs = items.filter(i => i.videoId && PLAYABLE.includes(i.type)).length;
      // Suche immer als Liste (gemischte Treffer), Detailseiten und Mediathek, wenn überwiegend Titel
      const asList = page.main === 'search' || ((page.detail || page.main === 'library') && songs * 2 >= items.length);
      const row = asList ? list(items, page) : cards(items, page);
      // Einzelnes Regal ohne Überschrift (z. B. Mediathek): Raster statt einer langen Reihe
      if (!asList && !shelf.title && shelves.length === 1) row.classList.add('grid');
      body.append(row);
    });
    if (data.continuation && data.client) {
      const more = document.createElement('button');
      more.className = 'more';
      more.textContent = T('Load more');
      // Automatisch nachladen, sobald der Knopf beim Scrollen sichtbar wird (Knopf bleibt als Rückfall)
      const observer = new IntersectionObserver(entries => {
        if (entries.some(e => e.isIntersecting) && !more.disabled) more.click();
      }, { root: $('vBody'), rootMargin: '0px 0px 300px 0px' });
      observer.observe(more);
      more.onclick = () => {
        observer.disconnect();
        more.disabled = true;
        query({ kind: 'more', token: data.continuation, client: data.client }, (next, error) => {
          more.remove();
          if (next) renderShelves(body, next, page, seen); else status(body, error || T('Nothing found'));
        }, false);
      };
      body.append(more);
    }
  }

  function cards(items, page) {
    const row = document.createElement('div');
    row.className = 'cards';
    items.forEach(item => {
      const c = document.createElement('div');
      c.className = 'card' + (item.type === 'artist' ? ' round' : '');
      c.append(thumb(item), text('b', item.title), text('small', item.subtitle));
      c.onclick = () => activate(item, page);
      row.append(c);
    });
    return row;
  }
  function categories(items, page) {
    const grid = document.createElement('div');
    grid.className = 'categories';
    items.forEach(item => {
      const b = text('button', item.title, 'category');
      b.onclick = () => activate(item, page);
      grid.append(b);
    });
    return grid;
  }
  function list(items, page, currentId) {
    const box = document.createElement('div');
    box.className = 'list';
    items.forEach(item => {
      const r = document.createElement('div');
      r.className = 'row' + (currentId && item.videoId === currentId ? ' sel' : '') + (item.type === 'artist' ? ' round' : '');
      r.dataset.videoId = item.videoId || '';
      const t = document.createElement('span');
      t.className = 'row-txt';
      t.append(text('b', item.title), text('small', item.subtitle));
      // Titel ohne eigenes Bild (Albumseite): Bild der geöffneten Seite
      const cover = !item.thumbnail && page && page.cover ? Object.assign({}, item, { thumbnail: page.cover }) : item;
      r.append(thumb(cover), t, text('span', item.duration || '', 'row-dur'));
      r.onclick = () => activate(item, page);
      box.append(r);
    });
    return box;
  }
  // Bild ohne Referer: Google verweigert manche Vorschaubilder mit der Adresse der Visu als Referer (live geprüft).
  // Nicht für die ganze Kachel abschalten, der eingebettete Player braucht den Referer.
  function thumb(item) {
    const i = document.createElement('span');
    i.className = 'thumb';
    if (/^https:\/\//.test(item.thumbnail || '')) {
      const img = document.createElement('img');
      img.referrerPolicy = 'no-referrer';
      img.loading = 'lazy';
      img.alt = '';
      img.src = item.thumbnail;
      img.onerror = () => img.remove();
      i.append(img);
    }
    return i;
  }
  function text(tag, value, cls) {
    const e = document.createElement(tag);
    e.textContent = value || '';
    if (cls) e.className = cls;
    return e;
  }

  // Tipp auf einen Eintrag: Titel spielen, sonst Detailseite öffnen
  function activate(item, page) {
    if (item.videoId && (PLAYABLE.includes(item.type) || !item.browseId)) {
      // Im Zusammenhang der Liste (Album, Playlist, Warteschlange), sonst als Radio zum Titel
      const listId = (page && page.playlistId) || item.playlistId || 'RDAMVM' + item.videoId;
      api.play({ videoId: item.videoId, listId });
      return;
    }
    if (item.browseId) {
      // Album-ID (MPREb_…) ist keine Playlist: ohne playlistId gibt es kein „Abspielen“
      const listId = item.type === 'album' || item.type === 'playlist'
        ? (item.playlistId || (/^VL/.test(item.browseId) ? item.browseId.slice(2) : '')) : '';
      // Album mit Playlist als Playlist öffnen: gezeigte Titel = gespielte Titel (die Albumseite zeigt teils andere
      // Fassungen) und mit Bildern (Titel der Albumseite haben keine)
      const id = item.type === 'album' && item.playlistId ? 'VL' + item.playlistId : item.browseId;
      open({
        title: item.title, subtitle: item.subtitle, detail: true, playlistId: listId, artist: item.type === 'artist',
        cover: item.thumbnail || '', query: { kind: 'browse', id, params: item.params || '' },
      });
      return;
    }
    if (item.playlistId) api.play({ videoId: '', listId: item.playlistId });
  }

  // Suche mit Filtern; das Feld nimmt auch Links und IDs zum direkten Abspielen
  function renderSearch(body) {
    const form = document.createElement('div');
    form.className = 'search';
    const input = document.createElement('input');
    input.type = 'search';
    input.placeholder = T('Search or paste a link');
    input.value = search.query;
    const go = document.createElement('button');
    go.className = 'primary';
    go.innerHTML = api.icon('search');
    const chips = document.createElement('div');
    chips.className = 'chips';
    FILTERS.forEach(([value, label]) => {
      const c = document.createElement('button');
      c.className = 'chip' + (search.filter === value ? ' sel' : '');
      c.textContent = T(label);
      c.onclick = () => { search.filter = value; run(); };
      chips.append(c);
    });
    const results = document.createElement('div');
    form.append(input, go);
    body.append(form, chips, results);
    const run = () => {
      search.query = input.value.trim();
      chips.querySelectorAll('.chip').forEach((c, i) => c.classList.toggle('sel', FILTERS[i][0] === search.filter));
      results.textContent = '';
      if (!search.query) return;
      const link = api.parseLink(search.query);
      if (link && /[/.?=]/.test(search.query)) { api.play(link); return; }
      const page = stack[stack.length - 1];
      status(results, T('Loading…'));
      query({ kind: 'search', query: search.query, filter: search.filter }, (data, error) => {
        if (stack[stack.length - 1] !== page) return;
        results.textContent = '';
        if (!data) { status(results, error || T('Nothing found')); return; }
        renderShelves(results, data, page);
      });
    };
    go.onclick = run;
    input.onkeydown = e => { if (e.key === 'Enter') run(); };
    if (search.query) run(); else setTimeout(() => input.focus(), 0);
  }

  // Warteschlange und Liedtext zum Titel des gewählten Geräts
  function renderQueue(body, quiet) {
    const s = api.current();
    shownTrack = s.videoId || '';
    if (!s.videoId) { body.textContent = ''; status(body, T('Nothing playing')); return; }
    load(body, { kind: 'queue', videoId: s.videoId, playlistId: s.listId || '' }, data => {
      const page = stack[stack.length - 1];
      page.playlistId = data.playlistId || '';
      body.append(list(data.items || [], page, s.videoId));
      const sel = body.querySelector('.row.sel');
      if (sel) sel.scrollIntoView({ block: 'center', behavior: quiet ? 'smooth' : 'auto' });
    }, quiet);
  }
  function renderLyrics(body, quiet) {
    const s = api.current();
    shownTrack = s.videoId || '';
    if (!s.videoId) { body.textContent = ''; status(body, T('Nothing playing')); return; }
    load(body, { kind: 'lyrics', videoId: s.videoId }, data => {
      if (!data.text) { status(body, T('No lyrics for this title')); return; }
      if (Array.isArray(data.lines) && data.lines.length) {
        // Synchroner Liedtext: Zeilen mit Start/Ende, die aktuelle hebt syncLyrics hervor
        const box = document.createElement('div');
        box.className = 'lyrics timed';
        data.lines.forEach(line => {
          const row = text('div', line.text, 'lyric');
          row.dataset.start = line.start;
          row.dataset.end = line.end;
          box.append(row);
        });
        body.append(box);
      } else {
        body.append(text('div', data.text, 'lyrics'));
      }
      body.append(text('small', data.source || '', 'lyrics-src'));
      body.scrollTop = 0;
      lyricLine = -1;
    }, quiet);
  }

  // Aktuelle Zeile nach der Abspielposition markieren und mittig halten (nicht, solange der Nutzer selbst scrollt)
  let lyricLine = -1;
  let userScroll = 0;
  function syncLyrics() {
    const page = stack[stack.length - 1];
    if (!page || page.main !== 'lyrics') return;
    const rows = $('vBody').querySelectorAll('.lyric');
    if (!rows.length) return;
    const ms = api.position() * 1000;
    let current = -1;
    rows.forEach((row, i) => { if (ms >= Number(row.dataset.start)) current = i; });
    if (current === lyricLine) return;
    lyricLine = current;
    rows.forEach((row, i) => {
      row.classList.toggle('now', i === current);
      row.classList.toggle('past', i < current);
    });
    if (current >= 0 && Date.now() - userScroll > 4000) rows[current].scrollIntoView({ block: 'center', behavior: 'smooth' });
  }
  setInterval(syncLyrics, 250);
  ['wheel', 'touchmove', 'pointerdown'].forEach(ev => $('vBody').addEventListener(ev, () => { userScroll = Date.now(); }, { passive: true }));

  // Titelwechsel: in der Warteschlange nur die Markierung verschieben, wenn der Titel schon in der Liste steht;
  // sonst (und beim Liedtext) leise neu laden, ohne die Anzeige vorher zu leeren
  function trackChanged(videoId) {
    const page = stack[stack.length - 1];
    // Leere ID (kurze Lücke beim Titelwechsel) nicht als „Keine Wiedergabe“ werten
    if (!page || (page.main !== 'queue' && page.main !== 'lyrics') || !videoId || videoId === shownTrack) return;
    const body = $('vBody');
    if (page.main === 'queue') {
      const rows = [...body.querySelectorAll('.row')];
      const hit = rows.find(r => r.dataset.videoId === videoId);
      if (hit) {
        shownTrack = videoId;
        rows.forEach(r => r.classList.toggle('sel', r === hit));
        hit.scrollIntoView({ block: 'center', behavior: 'smooth' });
        return;
      }
      renderQueue(body, true);
      return;
    }
    renderLyrics(body, true);
  }

  // Ziehen mit der Maus wie Wischen auf dem Touchscreen (Touch scrollt der Browser selbst):
  // waagerecht in einer Kartenreihe scrollt die Reihe, sonst die Seite. Nach dem Ziehen kein Klick auf den Eintrag.
  function dragScroll(body) {
    let drag = null;
    body.addEventListener('pointerdown', e => {
      if (e.pointerType !== 'mouse' || e.button !== 0 || e.target.closest('input, button.chip, .search')) return;
      drag = { x: e.clientX, y: e.clientY, row: e.target.closest('.cards:not(.grid)'), axis: '', moved: false };
    });
    window.addEventListener('pointermove', e => {
      if (!drag) return;
      const dx = e.clientX - drag.x, dy = e.clientY - drag.y;
      if (!drag.axis) {
        if (Math.abs(dx) < 6 && Math.abs(dy) < 6) return;
        drag.axis = drag.row && Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
        drag.moved = true;
        body.classList.add('dragging');
      }
      if (drag.axis === 'x') drag.row.scrollLeft -= dx; else body.scrollTop -= dy;
      drag.x = e.clientX;
      drag.y = e.clientY;
    });
    window.addEventListener('pointerup', () => {
      if (drag && drag.moved) {
        // den Klick, der auf das Loslassen folgt, verwerfen
        const stop = ev => { ev.stopPropagation(); ev.preventDefault(); };
        window.addEventListener('click', stop, { capture: true, once: true });
        setTimeout(() => window.removeEventListener('click', stop, { capture: true }), 0);
      }
      body.classList.remove('dragging');
      drag = null;
    });
  }
  dragScroll($('vBody'));

  $('vBack').innerHTML = api.icon('back');
  $('vBack').title = T('Back');
  $('vBack').onclick = back;
  $('vPlay').innerHTML = api.icon('play') + '<span></span>';
  $('vPlay').lastChild.textContent = T('Play');
  // Erst beim ersten Wechsel in die Voll-Ansicht laden (kleine Kacheln fragen nichts an)
  function ensure() {
    if (stack.length) return;
    let name = 'home';
    try { name = sessionStorage.getItem(api.storageKey) || 'home'; } catch (e) { /* kein Speicher */ }
    show(name);
  }
  // Suche von außen (Klick auf Künstler bzw. Album des laufenden Titels)
  function searchFor(text, filter) {
    search = { query: text, filter: filter || '' };
    show('search');
  }

  return { show, ensure, onResult, trackChanged, searchFor };
};
