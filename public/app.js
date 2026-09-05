(function () {
  'use strict';

  const API = 'api/';
  // Plain line-style icons (currentColor) used in place of color emoji for rename/filter
  // actions, so they stay monochrome and match the row's hover/muted text color.
  const PENCIL_ICON = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
  const REFRESH_ICON = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>';
  const SPIN_ICON = '<svg class="btn-spinner-icon" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>';
  const MORE_ICON = '<svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg>';
  const SEARCH_ICON = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>';
  const EYE_SLASH_ICON = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a21.62 21.62 0 0 1 5.06-6.06M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a21.6 21.6 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/></svg>';
  const UP_ICON = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5"/><path d="m5 12 7-7 7 7"/></svg>';
  const DOWN_ICON = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"/><path d="m19 12-7 7-7-7"/></svg>';
  const BOOKMARK_ICON = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21 12 16l-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>';
  const BOOKMARK_FILLED_ICON = '<svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21 12 16l-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>';
  const ALL_ITEMS_ICON = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/><line x1="9" y1="6" x2="20" y2="6"/><line x1="9" y1="12" x2="20" y2="12"/><line x1="9" y1="18" x2="20" y2="18"/></svg>';
  const UNREAD_ICON = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3" fill="currentColor" stroke="none"/></svg>';
  // chevron.right shape; rotated -90deg via .chevron.collapsed in CSS to read as chevron.down when expanded
  const CHEVRON_ICON = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>';
  const REFRESH_INTERVAL_PRESETS = [15, 30, 60, 120, 240, 360, 720, 1440];
  const FILTER_KEY = 'rss_last_filter';
  const SELECTED_ITEM_KEY = 'rss_selected_item_id';
  const PANE_ORDER = ['sidebar', 'items', 'reading'];
  const SORT_ORDER_KEY = 'rss_sort_order';
  const SORT_ORDER_UNREAD_KEY = 'rss_sort_order_unread';
  const SORT_FEEDS_ALPHA_KEY = 'rss_sort_feeds_alpha';
  const BASE_TITLE = document.title;

  const state = {
    folders: [],
    feeds: [],
    savedSearches: [],
    tags: [],
    items: [],
    filter: { type: 'all', id: null },
    selectedItemId: null,
    selectedIndex: -1,
    lastBuildDate: null,
    serverName: null,
    phpVersion: null,
    bulkMode: false,
    bulkSelectedIds: new Set(),
    sortOrder: 'desc',
    sortOrderUnread: 'desc',
    sortFeedsAlphabetically: false,
    pane: 'items',
    paneFeed: null,
  };

  let hoveredItem = null; // { item, rowEl } for the currently mouse-hovered row, or null

  // ---------- API helpers ----------

  async function apiFetch(path, opts = {}) {
    const headers = opts.headers ? { ...opts.headers } : {};
    const isFormData = opts.body instanceof FormData;
    if (!isFormData) {
      headers['Content-Type'] = 'application/json';
    }
    const res = await fetch(API + path, { ...opts, headers });
    let data = null;
    let parseFailed = false;
    try {
      data = await res.json();
    } catch (e) {
      parseFailed = true;
    }
    if (!res.ok) {
      if (res.status === 401 && path !== 'auth.php') {
        showLoginScreen(true);
      }
      throw new Error((data && data.error) || `Request failed (${res.status})`);
    }
    if (parseFailed) {
      throw new Error(`Server returned an invalid response (status ${res.status}). This usually means a PHP error occurred on the server — check the terminal running "php -S" for the actual error.`);
    }
    return data;
  }

  const get = (path) => apiFetch(path, { method: 'GET' });
  const post = (path, body) => apiFetch(path, { method: 'POST', body: JSON.stringify(body || {}) });
  const patch = (path, body) => apiFetch(path, { method: 'PATCH', body: JSON.stringify(body || {}) });
  const del = (path) => apiFetch(path, { method: 'DELETE' });

  // Sidebar UI prefs (collapsed folders, sort order, last-selected filter) are mirrored to the
  // server (feeds.json) in addition to localStorage, since browser storage alone isn't reliable
  // across restarts for every browser/privacy-setting combination (e.g. Safari can clear a site's
  // localStorage between launches). The server merges whatever keys are sent, so a partial object
  // is fine here. Failures are swallowed — this is a nice-to-have, not something to surface a toast for.
  function saveUiPref(partial) {
    patch('settings.php', { ui_prefs: partial }).catch(() => {});
  }

  // ---------- Toast ----------

  let toastTimer = null;
  function toast(message) {
    const el = document.getElementById('toast');
    el.textContent = message;
    el.hidden = false;
    clearTimeout(toastTimer);
    // Longer messages (mainly error explanations) get more time on screen instead of
    // vanishing before they can be read; short confirmations still clear quickly.
    const duration = Math.min(10000, Math.max(3500, message.length * 60));
    toastTimer = setTimeout(() => { el.hidden = true; }, duration);
  }

  // ---------- Sanitizer (allow-list, no raw innerHTML of feed content) ----------

  const ALLOWED_TAGS = new Set([
    'P', 'A', 'B', 'I', 'EM', 'STRONG', 'BR', 'UL', 'OL', 'LI', 'BLOCKQUOTE', 'CODE', 'PRE', 'IMG',
    'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'SPAN', 'DIV',
    'TABLE', 'THEAD', 'TBODY', 'TFOOT', 'TR', 'TD', 'TH', 'CAPTION', 'FIGURE', 'FIGCAPTION', 'HR',
  ]);
  const ALLOWED_ATTRS = {
    A: ['href', 'title'],
    IMG: ['src', 'alt', 'title'],
    TD: ['colspan', 'rowspan'],
    TH: ['colspan', 'rowspan'],
  };

  function sanitizeHtml(html) {
    const wrapper = document.createElement('div');
    if (!html) return wrapper;
    const parsed = new DOMParser().parseFromString(html, 'text/html');
    sanitizeInto(parsed.body, wrapper);
    return wrapper;
  }

  function sanitizeInto(sourceParent, destParent) {
    for (const node of Array.from(sourceParent.childNodes)) {
      if (node.nodeType === Node.TEXT_NODE) {
        destParent.appendChild(document.createTextNode(node.textContent));
        continue;
      }
      if (node.nodeType !== Node.ELEMENT_NODE) {
        continue;
      }
      const tag = node.tagName;
      if (!ALLOWED_TAGS.has(tag)) {
        // Drop the element but keep walking into its children's text.
        sanitizeInto(node, destParent);
        continue;
      }
      const el = document.createElement(tag);
      const allowedAttrs = ALLOWED_ATTRS[tag] || [];
      for (const attr of allowedAttrs) {
        const value = node.getAttribute(attr);
        if (!value) continue;
        if (attr === 'href' || attr === 'src') {
          if (!/^https?:\/\//i.test(value)) continue;
        }
        if (attr === 'colspan' || attr === 'rowspan') {
          if (!/^[1-9][0-9]?$/.test(value)) continue;
        }
        el.setAttribute(attr, value);
      }
      if (tag === 'A') {
        el.setAttribute('target', '_blank');
        el.setAttribute('rel', 'noopener noreferrer');
      }
      sanitizeInto(node, el);
      destParent.appendChild(el);
    }
  }

  // ---------- Search-keyword highlighting ----------

  // JS-idiomatic equivalent of the PHP fold_accents() in src/bootstrap.php, used
  // there for accent-insensitive server-side search matching.
  function foldAccentsJs(s) {
    return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  }

  // Wraps whole-word, accent/case-insensitive matches of any of queryWords in
  // <mark class="search-highlight"> within rootEl, mirroring the word-boundary
  // matching already used server-side in public/api/items.php.
  function highlightMatches(rootEl, queryWords) {
    if (!queryWords || queryWords.length === 0) return;
    const pattern = new RegExp(
      '(?<![\\p{L}\\p{N}])(' + queryWords.map((w) => w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|') + ')(?![\\p{L}\\p{N}])',
      'giu'
    );
    const walker = document.createTreeWalker(rootEl, NodeFilter.SHOW_TEXT);
    const textNodes = [];
    let node;
    while ((node = walker.nextNode())) {
      textNodes.push(node);
    }
    for (const textNode of textNodes) {
      const text = textNode.textContent;
      const folded = foldAccentsJs(text);
      pattern.lastIndex = 0;
      if (!pattern.test(folded)) continue;
      pattern.lastIndex = 0;

      const frag = document.createDocumentFragment();
      let lastIndex = 0;
      let match;
      while ((match = pattern.exec(folded))) {
        if (match.index > lastIndex) {
          frag.appendChild(document.createTextNode(text.slice(lastIndex, match.index)));
        }
        const mark = document.createElement('mark');
        mark.className = 'search-highlight';
        mark.textContent = text.slice(match.index, match.index + match[0].length);
        frag.appendChild(mark);
        lastIndex = match.index + match[0].length;
        if (match[0].length === 0) pattern.lastIndex++;
      }
      if (lastIndex < text.length) {
        frag.appendChild(document.createTextNode(text.slice(lastIndex)));
      }
      textNode.parentNode.replaceChild(frag, textNode);
    }
  }

  // ---------- Data loading ----------

  async function loadFeeds() {
    const data = await get('feeds.php');
    state.folders = data.folders;
    state.feeds = data.feeds;
    state.savedSearches = data.saved_searches || [];
    renderSidebar();
    populateFolderSelect();
    renderLastUpdated();
    renderAppFooter();
    if (state.filter.type === 'feed') {
      const feed = state.feeds.find((f) => f.id === state.filter.id);
      if (feed) setPaneTitle(feed.title, feed.url, feed.item_count || 0, feed);
    }
  }

  async function loadItems() {
    let path = 'items.php?limit=200&order=' + effectiveSortOrder();
    if (state.filter.type === 'feed') {
      path += '&feed_id=' + encodeURIComponent(state.filter.id);
    } else if (state.filter.type === 'folder') {
      path += '&folder_id=' + encodeURIComponent(state.filter.id);
    } else if (state.filter.type === 'unread') {
      path += '&unread_only=1';
    } else if (state.filter.type === 'starred') {
      path += '&starred_only=1';
    } else if (state.filter.type === 'tag') {
      path += '&tag=' + encodeURIComponent(state.filter.id);
    } else if (state.filter.type === 'search' || state.filter.type === 'saved_search') {
      path += '&q=' + encodeURIComponent(state.filter.query || '');
    }
    const data = await get(path);
    state.items = data.items;
    renderItems();
  }

  async function loadTags() {
    const data = await get('tags.php');
    state.tags = data.tags || [];
  }

  async function pollForUpdates() {
    if (document.hidden) return;
    if (!document.getElementById('settings-overlay').hidden) return;
    try {
      // Only refresh sidebar counts/badges — never the item list itself, so
      // an item you're mid-way through reading (e.g. an expanded summary
      // that just got marked read) doesn't silently vanish out from under
      // you when the currently-viewed list happens to be "Unread".
      await loadFeeds();
    } catch (e) {
      // Silent — a background poll failing shouldn't interrupt the user with a toast.
    }
  }

  function totalUnread() {
    return state.feeds.reduce((sum, f) => sum + (f.unread_count || 0), 0);
  }

  function totalItems() {
    return state.feeds.reduce((sum, f) => sum + (f.item_count || 0), 0);
  }

  function totalStarred() {
    return state.feeds.reduce((sum, f) => sum + (f.starred_count || 0), 0);
  }

  function setPaneCount(count) {
    const countEl = document.getElementById('item-pane-count');
    if (typeof count === 'number') {
      countEl.textContent = String(count);
      countEl.hidden = false;
    } else {
      countEl.hidden = true;
    }
  }

  function setPaneTitle(name, feedUrl, count, feed) {
    document.getElementById('item-pane-title-text').textContent = name;
    setPaneCount(count);
    const urlEl = document.getElementById('item-pane-feed-url');
    if (feedUrl) {
      urlEl.textContent = feedUrl;
      urlEl.href = feedUrl;
      urlEl.hidden = false;
    } else {
      urlEl.hidden = true;
      urlEl.removeAttribute('href');
    }

    const fixedEl = document.getElementById('item-pane-refresh-interval-fixed');
    const wrap = document.getElementById('item-pane-refresh-interval');
    const btn = document.getElementById('refresh-interval-btn');
    if (feed && feed.refresh_interval_locked) {
      state.paneFeed = feed;
      fixedEl.textContent = '⏱ ' + formatIntervalShort(feed.refresh_interval_minutes) + ' (defined in the feed)';
      fixedEl.hidden = false;
      wrap.hidden = true;
      closeRefreshIntervalPopover();
    } else if (feed) {
      state.paneFeed = feed;
      fixedEl.hidden = true;
      wrap.hidden = false;
      btn.textContent = '⏱ ' + formatIntervalShort(feed.refresh_interval_minutes);
    } else {
      state.paneFeed = null;
      fixedEl.hidden = true;
      wrap.hidden = true;
      closeRefreshIntervalPopover();
    }
  }

  function formatIntervalShort(minutes) {
    minutes = Number(minutes) || 0;
    if (minutes % 1440 === 0) return (minutes / 1440) + 'd';
    if (minutes % 60 === 0) return (minutes / 60) + 'h';
    return minutes + 'm';
  }

  function formatIntervalLong(minutes) {
    minutes = Number(minutes) || 0;
    if (minutes % 1440 === 0) {
      const days = minutes / 1440;
      return days === 1 ? 'Every day' : `Every ${days} days`;
    }
    if (minutes % 60 === 0) {
      const hours = minutes / 60;
      return hours === 1 ? 'Every hour' : `Every ${hours} hours`;
    }
    return `Every ${minutes} minutes`;
  }

  function renderRefreshIntervalPopover(feed) {
    const popover = document.getElementById('refresh-interval-popover');
    popover.innerHTML = '';
    const current = Number(feed.refresh_interval_minutes);
    const closest = REFRESH_INTERVAL_PRESETS.reduce((best, m) =>
      Math.abs(m - current) < Math.abs(best - current) ? m : best
    );
    REFRESH_INTERVAL_PRESETS.forEach((minutes) => {
      const optBtn = document.createElement('button');
      optBtn.type = 'button';
      const isActive = minutes === closest;
      optBtn.textContent = (isActive ? '✓ ' : '') + formatIntervalLong(minutes);
      optBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        setFeedRefreshInterval(feed, minutes);
      });
      popover.appendChild(optBtn);
    });
  }

  function openRefreshIntervalPopover() {
    if (!state.paneFeed) return;
    renderRefreshIntervalPopover(state.paneFeed);
    document.getElementById('refresh-interval-popover').hidden = false;
  }

  function closeRefreshIntervalPopover() {
    document.getElementById('refresh-interval-popover').hidden = true;
  }

  async function setFeedRefreshInterval(feed, minutes) {
    closeRefreshIntervalPopover();
    if (minutes === Number(feed.refresh_interval_minutes)) return;
    try {
      await patch('feeds.php?id=' + encodeURIComponent(feed.id), { refresh_interval_minutes: minutes });
      feed.refresh_interval_minutes = minutes;
      const stored = state.feeds.find((f) => f.id === feed.id);
      if (stored) stored.refresh_interval_minutes = minutes;
      document.getElementById('refresh-interval-btn').textContent = '⏱ ' + formatIntervalShort(minutes);
      toast(`Refresh interval set to ${formatIntervalLong(minutes).toLowerCase()}`);
    } catch (err) {
      toast('Failed to update refresh interval: ' + err.message);
    }
  }

  function setFilter(filter) {
    if (state.bulkMode) {
      state.bulkMode = false;
      state.bulkSelectedIds.clear();
      updateBulkActionsUI();
    }
    closeMobileReadingPane();
    state.filter = filter;
    updateSortOrderButton();
    updateSaveSearchButton();
    // Free-text search is transient — don't persist it, so a reload lands back on the last real
    // selection. A saved search IS a real, named selection, so it persists like folders/feeds do.
    if (filter.type === 'search') return;
    try {
      localStorage.setItem(FILTER_KEY, JSON.stringify({ type: filter.type, id: filter.id }));
    } catch (e) {
      // Ignore storage errors (e.g. private browsing quota).
    }
    saveUiPref({ last_filter: { type: filter.type, id: filter.id } });
  }

  function updateSaveSearchButton() {
    document.getElementById('save-search-btn').hidden = state.filter.type !== 'search';
  }

  function restorePersistedFilter() {
    // The server-persisted value (loaded in loadSettings) survives even if the browser has
    // forgotten localStorage since the last visit; fall back to localStorage otherwise.
    let persisted = state.serverLastFilter;
    if (!persisted || typeof persisted.type !== 'string') {
      try {
        persisted = JSON.parse(localStorage.getItem(FILTER_KEY));
      } catch (e) {
        return;
      }
    }
    if (!persisted || typeof persisted.type !== 'string') return;

    if (persisted.type === 'all') {
      setFilter({ type: 'all', id: null });
      setPaneTitle('All Items', null);
    } else if (persisted.type === 'unread') {
      setFilter({ type: 'unread', id: null });
      setPaneTitle('Unread', null);
    } else if (persisted.type === 'starred') {
      if (totalStarred() > 0) {
        setFilter({ type: 'starred', id: null });
        setPaneTitle('Saved', null);
      }
    } else if (persisted.type === 'folder') {
      if (persisted.id === null) {
        setFilter({ type: 'folder', id: null });
        setPaneTitle('Ungrouped', null);
      } else {
        const folder = state.folders.find((f) => f.id === persisted.id);
        if (folder) {
          setFilter({ type: 'folder', id: folder.id });
          setPaneTitle(folder.name, null);
        }
      }
    } else if (persisted.type === 'feed') {
      const feed = state.feeds.find((f) => f.id === persisted.id);
      if (feed) {
        setFilter({ type: 'feed', id: feed.id });
        setPaneTitle(feed.title, feed.url, feed.item_count || 0, feed);
      }
    } else if (persisted.type === 'saved_search') {
      const ss = state.savedSearches.find((s) => s.id === persisted.id);
      if (ss) {
        document.getElementById('search-input').value = ss.query;
        setFilter({ type: 'saved_search', id: ss.id, query: ss.query });
        setPaneTitle(ss.name, null);
      }
    } else if (persisted.type === 'tag') {
      const tag = state.tags.find((t) => t.name === persisted.id);
      if (tag) {
        setFilter({ type: 'tag', id: tag.name });
        setPaneTitle('#' + tag.name, null);
      }
    }
  }

  function clearSearchInput() {
    document.getElementById('search-input').value = '';
  }

  async function runSearch(rawValue) {
    const query = rawValue.trim();
    if (query === '') {
      if (state.filter.type === 'search') {
        setFilter({ type: 'all', id: null });
        setPaneTitle('All Items', null);
        renderSidebar();
        await loadItems();
      }
      return;
    }
    setFilter({ type: 'search', id: null, query });
    setPaneTitle(`Search: "${query}"`, null);
    renderSidebar();
    await loadItems();
  }

  async function loadSettings() {
    const data = await get('settings.php');
    state.appVersion = data.app_version;
    state.latestVersion = data.latest_version;
    state.lastBuildDate = data.last_build_date;
    state.serverName = data.server_name;
    state.phpVersion = data.php_version;

    // Server-persisted sidebar UI prefs take priority over whatever's in localStorage — see
    // saveUiPref for why. state.collapsed/sortOrder/sortOrderUnread were already seeded from
    // localStorage at module load time above, so only override them when the server actually
    // has a value (e.g. very first run before anything's ever been saved).
    const prefs = data.ui_prefs || {};
    if (Array.isArray(prefs.collapsed_folders)) {
      state.collapsed = new Set(prefs.collapsed_folders);
    }
    if (prefs.sort_order === 'asc' || prefs.sort_order === 'desc') {
      state.sortOrder = prefs.sort_order;
    }
    if (prefs.sort_order_unread === 'asc' || prefs.sort_order_unread === 'desc') {
      state.sortOrderUnread = prefs.sort_order_unread;
    }
    if (typeof prefs.sort_feeds_alphabetically === 'boolean') {
      state.sortFeedsAlphabetically = prefs.sort_feeds_alphabetically;
    }
    if (Number.isInteger(prefs.sidebar_width)) {
      state.sidebarWidth = applySidebarWidth(prefs.sidebar_width);
    }
    if (Number.isInteger(prefs.item_pane_width)) {
      state.itemPaneWidth = applyItemPaneWidth(prefs.item_pane_width);
    }
    if (typeof prefs.sidebar_collapsed === 'boolean') {
      state.sidebarCollapsed = prefs.sidebar_collapsed;
      applySidebarCollapsed(state.sidebarCollapsed);
    }
    state.serverLastFilter = prefs.last_filter || null;
    state.serverSelectedItemId = typeof prefs.selected_item_id === 'string' ? prefs.selected_item_id : null;
  }

  function renderAppFooter() {
    const el = document.getElementById('app-footer-text');
    const parts = [];
    if (state.appVersion) {
      parts.push('v' + state.appVersion);
    }
    if (state.lastBuildDate) {
      parts.push('Build: ' + new Date(state.lastBuildDate).toLocaleDateString());
    }
    if (state.serverName) {
      parts.push(state.serverName);
    }
    if (state.phpVersion) {
      parts.push('PHP ' + state.phpVersion);
    }
    const activeCount = state.feeds.filter(f => f.enabled !== false).length;
    parts.push(`${activeCount} active feed${activeCount === 1 ? '' : 's'} of ${state.feeds.length}`);
    el.textContent = parts.join(' · ');

    const badge = document.getElementById('app-footer-update-badge');
    if (state.latestVersion) {
      badge.textContent = `Update available: v${state.latestVersion}`;
      badge.hidden = false;
    } else {
      badge.hidden = true;
    }
  }

  function renderLastUpdated() {
    const el = document.getElementById('last-updated');
    const timestamps = state.feeds.map((f) => f.last_fetched).filter(Boolean);
    if (timestamps.length === 0) {
      el.textContent = '';
      return;
    }
    const latest = timestamps.reduce((a, b) => (a > b ? a : b));
    el.textContent = 'Updated ' + timeAgo(latest);
    el.title = new Date(latest).toLocaleString();
  }

  // ---------- Rendering: sidebar ----------

  function populateFolderSelect() {
    const select = document.getElementById('add-feed-folder');
    const current = select.value;
    select.innerHTML = '<option value="">No folder</option>';
    for (const folder of state.folders) {
      const opt = document.createElement('option');
      opt.value = folder.id;
      opt.textContent = folder.name;
      select.appendChild(opt);
    }
    select.value = current;
  }

  const COLLAPSED_KEY = 'rss_collapsed_folders';
  const UNGROUPED_KEY = '__ungrouped__';
  const TAGS_KEY = '__tags__';
  const SAVED_SEARCHES_KEY = '__saved_searches__';

  function loadCollapsedSet() {
    try {
      return new Set(JSON.parse(localStorage.getItem(COLLAPSED_KEY) || '[]'));
    } catch (e) {
      return new Set();
    }
  }

  state.collapsed = loadCollapsedSet();

  const SIDEBAR_WIDTH_KEY = 'rss_sidebar_width';
  const SIDEBAR_WIDTH_MIN = 180;
  const SIDEBAR_WIDTH_MAX = 600;
  const SIDEBAR_WIDTH_DEFAULT = 300; // mirrors #sidebar's CSS default

  function clampSidebarWidth(px) {
    if (!Number.isFinite(px)) return SIDEBAR_WIDTH_DEFAULT;
    return Math.round(Math.min(SIDEBAR_WIDTH_MAX, Math.max(SIDEBAR_WIDTH_MIN, px)));
  }

  function applySidebarWidth(px) {
    const clamped = clampSidebarWidth(px);
    document.getElementById('sidebar').style.width = clamped + 'px';
    return clamped;
  }

  function loadSidebarWidth() {
    try {
      return clampSidebarWidth(parseInt(localStorage.getItem(SIDEBAR_WIDTH_KEY), 10));
    } catch (e) {
      return SIDEBAR_WIDTH_DEFAULT;
    }
  }

  state.sidebarWidth = applySidebarWidth(loadSidebarWidth());

  const SIDEBAR_COLLAPSED_KEY = 'rss_sidebar_collapsed';

  function applySidebarCollapsed(collapsed) {
    document.getElementById('sidebar').classList.toggle('collapsed', collapsed);
    document.getElementById('sidebar-toggle-btn').setAttribute('aria-expanded', String(!collapsed));
  }

  function loadSidebarCollapsed() {
    try {
      return localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === '1';
    } catch (e) {
      return false;
    }
  }

  function persistSidebarCollapsed(collapsed) {
    try {
      localStorage.setItem(SIDEBAR_COLLAPSED_KEY, collapsed ? '1' : '0');
    } catch (e) {
      // Tolerate storage errors (private browsing quota etc.), same as other prefs.
    }
    saveUiPref({ sidebar_collapsed: collapsed });
  }

  state.sidebarCollapsed = loadSidebarCollapsed();
  applySidebarCollapsed(state.sidebarCollapsed);

  const ITEM_PANE_WIDTH_KEY = 'rss_item_pane_width';
  const ITEM_PANE_WIDTH_MIN = 280;
  const ITEM_PANE_WIDTH_MAX = 800;
  const ITEM_PANE_WIDTH_DEFAULT = 420; // mirrors #item-pane's CSS default

  function clampItemPaneWidth(px) {
    if (!Number.isFinite(px)) return ITEM_PANE_WIDTH_DEFAULT;
    return Math.round(Math.min(ITEM_PANE_WIDTH_MAX, Math.max(ITEM_PANE_WIDTH_MIN, px)));
  }

  function applyItemPaneWidth(px) {
    const clamped = clampItemPaneWidth(px);
    document.getElementById('item-pane').style.width = clamped + 'px';
    return clamped;
  }

  function loadItemPaneWidth() {
    try {
      return clampItemPaneWidth(parseInt(localStorage.getItem(ITEM_PANE_WIDTH_KEY), 10));
    } catch (e) {
      return ITEM_PANE_WIDTH_DEFAULT;
    }
  }

  state.itemPaneWidth = applyItemPaneWidth(loadItemPaneWidth());

  function loadSortOrder() {
    try {
      const saved = localStorage.getItem(SORT_ORDER_KEY);
      return saved === 'asc' ? 'asc' : 'desc';
    } catch (e) {
      return 'desc';
    }
  }

  function loadSortOrderUnread() {
    try {
      const saved = localStorage.getItem(SORT_ORDER_UNREAD_KEY);
      return saved === 'asc' ? 'asc' : 'desc';
    } catch (e) {
      return 'desc';
    }
  }

  state.sortOrder = loadSortOrder();
  state.sortOrderUnread = loadSortOrderUnread();

  function loadSortFeedsAlpha() {
    try {
      return localStorage.getItem(SORT_FEEDS_ALPHA_KEY) === '1';
    } catch (e) {
      return false;
    }
  }

  state.sortFeedsAlphabetically = loadSortFeedsAlpha();

  function effectiveSortOrder() {
    return state.filter.type === 'unread' ? state.sortOrderUnread : state.sortOrder;
  }

  function updateSortOrderButton() {
    document.getElementById('sort-order-toggle-btn').textContent =
      effectiveSortOrder() === 'asc' ? 'Oldest first' : 'Newest first';
  }

  function toggleCollapsed(folderKey, chevron, feedsWrap) {
    let collapsed;
    if (state.collapsed.has(folderKey)) {
      state.collapsed.delete(folderKey);
      collapsed = false;
    } else {
      state.collapsed.add(folderKey);
      collapsed = true;
    }
    localStorage.setItem(COLLAPSED_KEY, JSON.stringify([...state.collapsed]));
    saveUiPref({ collapsed_folders: [...state.collapsed] });

    // Toggle the existing DOM in place (rather than a full renderSidebar) so the
    // grid-template-rows transition on .folder-feeds-wrap can actually animate.
    chevron.classList.toggle('collapsed', collapsed);
    chevron.setAttribute('aria-label', collapsed ? 'Expand' : 'Collapse');
    feedsWrap.classList.toggle('collapsed', collapsed);
  }

  function renderSidebar() {
    const unread = totalUnread();
    document.title = unread > 0 ? `(${unread}) ${BASE_TITLE}` : BASE_TITLE;

    const currentFeedIds = new Set(state.feeds.map((f) => f.id));
    for (const id of faviconElements.keys()) {
      if (!currentFeedIds.has(id)) faviconElements.delete(id);
    }

    const list = document.getElementById('folder-list');
    list.innerHTML = '';

    list.appendChild(sidebarRow('All Items', totalItems(), 'all', null, { badge: true, icon: ALL_ITEMS_ICON }));
    list.appendChild(sidebarRow('Unread', unread, 'unread', null, { badge: true, icon: UNREAD_ICON }));

    const starred = totalStarred();
    if (starred > 0) {
      list.appendChild(sidebarRow('Saved', starred, 'starred', null, { badge: true, icon: BOOKMARK_FILLED_ICON }));
    }

    if (state.savedSearches.length > 0) {
      list.appendChild(savedSearchesSectionNode());
    }

    if (state.tags.length > 0) {
      list.appendChild(tagsSectionNode());
    }

    state.folders.forEach((folder, i) => {
      const feedsInFolder = orderFeedsForSidebar(state.feeds.filter((f) => f.folder_id === folder.id));
      const folderUnread = feedsInFolder.reduce((s, f) => s + (f.unread_count || 0), 0);
      const isFirst = i === 0;
      const isLast = i === state.folders.length - 1;
      list.appendChild(folderNode(folder.name, folderUnread, folder.id, feedsInFolder, isFirst, isLast));
    });

    const ungrouped = orderFeedsForSidebar(state.feeds.filter((f) => !f.folder_id));
    if (ungrouped.length) {
      const ungroupedUnread = ungrouped.reduce((s, f) => s + (f.unread_count || 0), 0);
      list.appendChild(folderNode('Ungrouped', ungroupedUnread, null, ungrouped));
    }
  }

  function orderFeedsForSidebar(feeds) {
    if (!state.sortFeedsAlphabetically) return feeds;
    return [...feeds].sort((a, b) => a.title.localeCompare(b.title, undefined, { sensitivity: 'base' }));
  }

  function folderNode(name, count, folderId, feeds, isFirst, isLast) {
    const folderKey = folderId === null ? UNGROUPED_KEY : folderId;
    const collapsed = state.collapsed.has(folderKey);

    const li = document.createElement('li');
    li.className = 'folder-node';

    const row = sidebarRow(name, count, 'folder', folderId, { folderRow: true });

    const chevron = document.createElement('button');
    chevron.type = 'button';
    chevron.className = 'chevron' + (collapsed ? ' collapsed' : '');
    chevron.setAttribute('aria-label', collapsed ? 'Expand' : 'Collapse');
    chevron.innerHTML = CHEVRON_ICON;
    chevron.addEventListener('click', (e) => {
      e.stopPropagation();
      toggleCollapsed(folderKey, chevron, feedsWrap);
    });
    row.prepend(chevron);

    if (folderId !== null) {
      const buttons = document.createElement('div');
      buttons.className = 'row-buttons';
      row.appendChild(buttons);

      const upBtn = document.createElement('button');
      upBtn.type = 'button';
      upBtn.className = 'row-move';
      upBtn.innerHTML = UP_ICON;
      upBtn.title = 'Move folder up';
      upBtn.disabled = !!isFirst;
      upBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        moveFolder(folderId, 'up');
      });
      buttons.appendChild(upBtn);

      const downBtn = document.createElement('button');
      downBtn.type = 'button';
      downBtn.className = 'row-move';
      downBtn.innerHTML = DOWN_ICON;
      downBtn.title = 'Move folder down';
      downBtn.disabled = !!isLast;
      downBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        moveFolder(folderId, 'down');
      });
      buttons.appendChild(downBtn);

      const renameBtn = document.createElement('button');
      renameBtn.type = 'button';
      renameBtn.className = 'row-rename';
      renameBtn.innerHTML = PENCIL_ICON;
      renameBtn.title = 'Rename folder';
      renameBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        renameFolder(folderId, name);
      });
      buttons.appendChild(renameBtn);

      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'row-remove';
      removeBtn.textContent = '✕';
      removeBtn.title = 'Delete folder and its feeds';
      let confirmTimer = null;
      removeBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (removeBtn.classList.contains('confirming')) {
          clearTimeout(confirmTimer);
          removeFolder(folderId, name, feeds.length);
          return;
        }
        removeBtn.classList.add('confirming');
        removeBtn.textContent = feeds.length ? `Delete +${feeds.length}?` : 'Delete?';
        confirmTimer = setTimeout(() => {
          removeBtn.classList.remove('confirming');
          removeBtn.textContent = '✕';
        }, 3000);
      });
      buttons.appendChild(removeBtn);
    }

    li.appendChild(row);

    const feedsWrap = document.createElement('div');
    feedsWrap.className = 'folder-feeds-wrap' + (collapsed ? ' collapsed' : '');

    const feedList = document.createElement('ul');
    feedList.className = 'folder-feeds';
    for (const feed of feeds) {
      feedList.appendChild(feedRow(feed));
    }
    feedsWrap.appendChild(feedList);
    li.appendChild(feedsWrap);

    return li;
  }

  function savedSearchesSectionNode() {
    const collapsed = state.collapsed.has(SAVED_SEARCHES_KEY);

    const li = document.createElement('li');
    li.className = 'folder-node';

    const row = document.createElement('div');
    row.className = 'sidebar-row folder-row';
    row.innerHTML = '<span class="name">Saved Searches</span><span class="count"></span>';

    const chevron = document.createElement('button');
    chevron.type = 'button';
    chevron.className = 'chevron' + (collapsed ? ' collapsed' : '');
    chevron.setAttribute('aria-label', collapsed ? 'Expand' : 'Collapse');
    chevron.innerHTML = CHEVRON_ICON;
    chevron.addEventListener('click', (e) => {
      e.stopPropagation();
      toggleCollapsed(SAVED_SEARCHES_KEY, chevron, savedSearchesWrap);
    });
    row.prepend(chevron);

    li.appendChild(row);

    const savedSearchesWrap = document.createElement('div');
    savedSearchesWrap.className = 'folder-feeds-wrap' + (collapsed ? ' collapsed' : '');

    const savedSearchList = document.createElement('ul');
    savedSearchList.className = 'folder-feeds';
    for (const ss of state.savedSearches) {
      savedSearchList.appendChild(savedSearchRow(ss));
    }
    savedSearchesWrap.appendChild(savedSearchList);
    li.appendChild(savedSearchesWrap);

    return li;
  }

  function tagsSectionNode() {
    const collapsed = state.collapsed.has(TAGS_KEY);

    const li = document.createElement('li');
    li.className = 'folder-node';

    const row = document.createElement('div');
    row.className = 'sidebar-row folder-row';
    row.innerHTML = '<span class="name">Tags</span><span class="count"></span>';

    const chevron = document.createElement('button');
    chevron.type = 'button';
    chevron.className = 'chevron' + (collapsed ? ' collapsed' : '');
    chevron.setAttribute('aria-label', collapsed ? 'Expand' : 'Collapse');
    chevron.innerHTML = CHEVRON_ICON;
    chevron.addEventListener('click', (e) => {
      e.stopPropagation();
      toggleCollapsed(TAGS_KEY, chevron, tagsWrap);
    });
    row.prepend(chevron);

    li.appendChild(row);

    const tagsWrap = document.createElement('div');
    tagsWrap.className = 'folder-feeds-wrap' + (collapsed ? ' collapsed' : '');

    const tagList = document.createElement('ul');
    tagList.className = 'folder-feeds';
    for (const tag of state.tags) {
      tagList.appendChild(sidebarRow('#' + tag.name, tag.count, 'tag', tag.name, { badge: true }));
    }
    tagsWrap.appendChild(tagList);
    li.appendChild(tagsWrap);

    return li;
  }

  function sidebarRow(name, count, type, id, opts = {}) {
    const row = document.createElement('div');
    const isActive = state.filter.type === type && state.filter.id === id;
    row.className = 'sidebar-row' + (isActive ? ' active' : '') + (opts.folderRow ? ' folder-row' : ' feed-row');
    row.innerHTML = `${opts.icon ? `<span class="sidebar-row-icon">${opts.icon}</span>` : ''}<span class="name"></span><span class="count${opts.badge ? ' count-badge' : ''}"></span>`;
    row.querySelector('.name').textContent = name;
    const countEl = row.querySelector('.count');
    countEl.textContent = count ? count : '';
    if (opts.badge) {
      countEl.hidden = !count;
    }
    row.addEventListener('click', () => {
      clearSearchInput();
      setFilter({ type, id });
      setPaneTitle(name, null);
      renderSidebar();
      loadItems();
      closeSidebar();
    });
    return row;
  }

  function savedSearchRow(ss) {
    const isActive = state.filter.type === 'saved_search' && state.filter.id === ss.id;
    const row = document.createElement('div');
    row.className = 'sidebar-row feed-row' + (isActive ? ' active' : '');
    row.innerHTML = `<span class="saved-search-icon">${SEARCH_ICON}</span><span class="name"></span><span class="count count-total"></span>`;
    row.querySelector('.name').textContent = ss.name;
    row.querySelector('.count').textContent = ss.match_count ? ss.match_count : '';
    row.title = ss.query;
    row.addEventListener('click', () => {
      document.getElementById('search-input').value = ss.query;
      setFilter({ type: 'saved_search', id: ss.id, query: ss.query });
      setPaneTitle(ss.name, null);
      renderSidebar();
      loadItems();
      closeSidebar();
    });

    const buttons = document.createElement('div');
    buttons.className = 'row-buttons';
    row.appendChild(buttons);

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'row-remove';
    removeBtn.textContent = '✕';
    removeBtn.title = 'Delete saved search';
    let confirmTimer = null;
    removeBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      if (removeBtn.classList.contains('confirming')) {
        clearTimeout(confirmTimer);
        removeSavedSearch(ss.id, ss.name);
        return;
      }
      removeBtn.classList.add('confirming');
      removeBtn.textContent = 'Delete?';
      confirmTimer = setTimeout(() => {
        removeBtn.classList.remove('confirming');
        removeBtn.textContent = '✕';
      }, 3000);
    });
    buttons.appendChild(removeBtn);

    return row;
  }

  function faviconUrlFor(feed) {
    if (feed.favicon_url) return feed.favicon_url;
    const base = feed.site_url || feed.url;
    if (!base) return null;
    try {
      return new URL(base).origin + '/favicon.ico';
    } catch (e) {
      return null;
    }
  }

  const faviconElements = new Map(); // feed.id -> { img, src } — reused across
                                      // renderSidebar() calls so favicons don't
                                      // flicker on every periodic poll

  function feedRow(feed) {
    const isActive = state.filter.type === 'feed' && state.filter.id === feed.id;
    const isEnabled = feed.enabled !== false;
    const filters = feed.filters || [];

    const row = document.createElement('div');
    row.className = 'sidebar-row feed-row' + (isActive ? ' active' : '') + (isEnabled ? '' : ' feed-disabled');
    row.innerHTML = `<span class="name"></span><span class="feed-error-badge" hidden>⚠</span><span class="filter-badge" hidden></span><span class="count"></span>`;
    row.querySelector('.name').textContent = feed.title;
    row.querySelector('.count').textContent = feed.unread_count ? feed.unread_count : '';

    const faviconSrc = faviconUrlFor(feed);
    if (faviconSrc) {
      let entry = faviconElements.get(feed.id);
      if (!entry) {
        const img = document.createElement('img');
        img.className = 'feed-favicon';
        img.alt = '';
        img.loading = 'lazy';
        img.referrerPolicy = 'no-referrer';
        // src tracks the requested favicon URL so re-renders don't reset (and re-fail) a
        // fallback that's already showing feed.image_url in its place.
        entry = { img, src: null };
        img.addEventListener('error', () => {
          // favicon.ico guesses 404 often; fall back to the feed's own channel image before giving up
          if (feed.image_url && img.src !== feed.image_url) {
            img.src = feed.image_url;
          } else {
            img.remove();
            faviconElements.delete(feed.id);
          }
        });
        faviconElements.set(feed.id, entry);
      }
      if (entry.src !== faviconSrc) {
        entry.src = faviconSrc;
        entry.img.src = faviconSrc;
      }
      row.prepend(entry.img);
    } else {
      faviconElements.delete(feed.id);
    }

    const filterBadge = row.querySelector('.filter-badge');
    if (filters.length > 0) {
      filterBadge.hidden = false;
      filterBadge.innerHTML = EYE_SLASH_ICON + filters.length;
      filterBadge.title = 'Skipping items containing: ' + filters.join(', ');
    }

    const errorBadge = row.querySelector('.feed-error-badge');
    if (isEnabled && feed.last_status === 'error') {
      errorBadge.hidden = false;
      errorBadge.title = feed.last_error || 'Last refresh failed';
    }

    row.title = feed.url + (isEnabled ? '' : ' (refresh disabled)');
    row.addEventListener('click', () => {
      clearSearchInput();
      setFilter({ type: 'feed', id: feed.id });
      setPaneTitle(feed.title, feed.url, feed.item_count || 0, feed);
      renderSidebar();
      loadItems();
      closeSidebar();
    });
    row.addEventListener('contextmenu', (e) => {
      e.preventDefault();
      const point = {
        getBoundingClientRect: () => ({
          left: e.clientX, right: e.clientX,
          top: e.clientY, bottom: e.clientY,
          width: 0, height: 0,
        }),
      };
      openFeedRowMenu(feed, isEnabled, point);
    });

    const buttons = document.createElement('div');
    buttons.className = 'row-buttons';
    row.appendChild(buttons);

    const refreshBtn = document.createElement('button');
    refreshBtn.type = 'button';
    refreshBtn.className = 'row-refresh';
    refreshBtn.innerHTML = REFRESH_ICON;
    refreshBtn.title = 'Refresh this feed';
    refreshBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      refreshOneFeed(feed.id, feed.title, refreshBtn);
    });
    buttons.appendChild(refreshBtn);

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'row-remove';
    removeBtn.textContent = '✕';
    removeBtn.title = 'Remove feed';
    let confirmTimer = null;
    removeBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      if (removeBtn.classList.contains('confirming')) {
        clearTimeout(confirmTimer);
        removeFeed(feed.id, feed.title);
        return;
      }
      removeBtn.classList.add('confirming');
      removeBtn.textContent = 'Remove?';
      confirmTimer = setTimeout(() => {
        removeBtn.classList.remove('confirming');
        removeBtn.textContent = '✕';
      }, 3000);
    });
    buttons.appendChild(removeBtn);

    const moreBtn = document.createElement('button');
    moreBtn.type = 'button';
    moreBtn.className = 'row-more';
    moreBtn.innerHTML = MORE_ICON;
    moreBtn.title = 'More actions';
    moreBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      toggleFeedRowMenu(feed, isEnabled, moreBtn);
    });
    buttons.appendChild(moreBtn);

    return row;
  }

  async function removeFeed(feedId, name) {
    try {
      await del('feeds.php?id=' + encodeURIComponent(feedId));
      if (state.filter.type === 'feed' && state.filter.id === feedId) {
        setFilter({ type: 'all', id: null });
        setPaneTitle('All Items', null);
      }
      await loadFeeds();
      await loadItems();
      toast(`Removed "${name}"`);
    } catch (err) {
      toast('Failed to remove feed: ' + err.message);
    }
  }

  async function refreshOneFeed(feedId, name, btn) {
    if (btn.disabled) return;
    btn.disabled = true;
    btn.classList.add('spinning');
    try {
      const result = await post('refresh.php', { feed_id: feedId, force: true });
      const outcome = result.results[0];
      if (outcome.status === 'error') {
        toast(`Failed to refresh "${name}": ` + outcome.error);
      } else if (outcome.status === 'transient_error') {
        // The server absorbed this one rather than marking the feed broken (it
        // stays due, so the next refresh retries it) — but on a manual refresh
        // the user is watching, so say what happened instead of "up to date".
        toast(`"${name}" didn't respond (${outcome.error}) — will retry`);
      } else {
        toast(outcome.new_items ? `"${name}": ${outcome.new_items} new item${outcome.new_items === 1 ? '' : 's'}` : `"${name}" is up to date`);
      }
      await loadFeeds();
      if (state.filter.type === 'feed' && state.filter.id === feedId) {
        await loadItems();
      }
    } catch (err) {
      toast(`Failed to refresh "${name}": ` + err.message);
    } finally {
      btn.classList.remove('spinning');
      btn.disabled = false;
    }
  }

  let feedRowMenuFeedId = null;

  function toggleFeedRowMenu(feed, isEnabled, anchorBtn) {
    const menu = document.getElementById('feed-row-menu');
    if (!menu.hidden && feedRowMenuFeedId === feed.id) {
      closeFeedRowMenu();
      return;
    }
    openFeedRowMenu(feed, isEnabled, anchorBtn);
  }

  function openFeedRowMenu(feed, isEnabled, anchorBtn) {
    const menu = document.getElementById('feed-row-menu');
    menu.innerHTML = '';
    feedRowMenuFeedId = feed.id;

    const toggleItem = document.createElement('button');
    toggleItem.type = 'button';
    toggleItem.textContent = isEnabled ? 'Disable feed (stop refreshing)' : 'Enable feed';
    toggleItem.addEventListener('click', (e) => {
      e.stopPropagation();
      closeFeedRowMenu();
      toggleFeedEnabled(feed.id, isEnabled, feed.title);
    });
    menu.appendChild(toggleItem);

    const filterItem = document.createElement('button');
    filterItem.type = 'button';
    filterItem.textContent = 'Edit content filters…';
    filterItem.addEventListener('click', (e) => {
      e.stopPropagation();
      closeFeedRowMenu();
      editFeedFilters(feed);
    });
    menu.appendChild(filterItem);

    const renameItem = document.createElement('button');
    renameItem.type = 'button';
    renameItem.textContent = 'Rename feed…';
    renameItem.addEventListener('click', (e) => {
      e.stopPropagation();
      closeFeedRowMenu();
      renameFeed(feed);
    });
    menu.appendChild(renameItem);

    const moveItem = document.createElement('button');
    moveItem.type = 'button';
    moveItem.textContent = 'Move to folder…';
    moveItem.addEventListener('click', (e) => {
      e.stopPropagation();
      renderFeedRowMenuFolderList(feed, anchorBtn);
    });
    menu.appendChild(moveItem);

    positionFeedRowMenu(anchorBtn);
  }

  function renderFeedRowMenuFolderList(feed, anchorBtn) {
    const menu = document.getElementById('feed-row-menu');
    menu.innerHTML = '';

    const backItem = document.createElement('button');
    backItem.type = 'button';
    backItem.textContent = '← Back';
    backItem.addEventListener('click', (e) => {
      e.stopPropagation();
      openFeedRowMenu(feed, feed.enabled !== false, anchorBtn);
    });
    menu.appendChild(backItem);

    const currentFolderId = feed.folder_id || null;

    const noneItem = document.createElement('button');
    noneItem.type = 'button';
    noneItem.textContent = (currentFolderId === null ? '✓ ' : '') + 'No folder';
    noneItem.addEventListener('click', (e) => {
      e.stopPropagation();
      moveFeedToFolder(feed, null);
    });
    menu.appendChild(noneItem);

    for (const folder of state.folders) {
      const opt = document.createElement('button');
      opt.type = 'button';
      opt.textContent = (currentFolderId === folder.id ? '✓ ' : '') + folder.name;
      opt.addEventListener('click', (e) => {
        e.stopPropagation();
        moveFeedToFolder(feed, folder.id);
      });
      menu.appendChild(opt);
    }

    positionFeedRowMenu(anchorBtn);
  }

  async function moveFeedToFolder(feed, folderId) {
    closeFeedRowMenu();
    if ((feed.folder_id || null) === folderId) return;
    try {
      await patch('feeds.php?id=' + encodeURIComponent(feed.id), { folder_id: folderId });
      await loadFeeds();
      const folderName = folderId ? (state.folders.find((f) => f.id === folderId) || {}).name : null;
      toast(folderName ? `Moved "${feed.title}" to "${folderName}"` : `Moved "${feed.title}" to No folder`);
    } catch (err) {
      toast('Failed to move feed: ' + err.message);
    }
  }

  function positionFeedRowMenu(anchorBtn) {
    const menu = document.getElementById('feed-row-menu');
    menu.hidden = false;
    const anchorRect = anchorBtn.getBoundingClientRect();
    const menuRect = menu.getBoundingClientRect();
    const left = Math.min(anchorRect.left, window.innerWidth - menuRect.width - 8);
    const top = Math.min(anchorRect.bottom + 4, window.innerHeight - menuRect.height - 8);
    menu.style.left = Math.max(8, left) + 'px';
    menu.style.top = Math.max(8, top) + 'px';
  }

  function closeFeedRowMenu() {
    feedRowMenuFeedId = null;
    document.getElementById('feed-row-menu').hidden = true;
  }

  async function toggleFeedEnabled(feedId, currentlyEnabled, name) {
    try {
      await patch('feeds.php?id=' + encodeURIComponent(feedId), { enabled: !currentlyEnabled });
      await loadFeeds();
      toast(currentlyEnabled ? `Disabled "${name}"` : `Enabled "${name}"`);
    } catch (err) {
      toast('Failed to update feed: ' + err.message);
    }
  }

  async function editFeedFilters(feed) {
    const current = (feed.filters || []).join(', ');
    const input = window.prompt(
      `Skip items in "${feed.title}" whose title or summary contains any of these (comma-separated, leave blank for none):`,
      current
    );
    if (input === null) return;
    const filters = input.split(',').map((s) => s.trim()).filter(Boolean);
    try {
      await patch('feeds.php?id=' + encodeURIComponent(feed.id), { filters });
      await loadFeeds();
      toast(filters.length ? `Filtering "${feed.title}" on ${filters.length} term${filters.length === 1 ? '' : 's'}` : `Cleared filters for "${feed.title}"`);
    } catch (err) {
      toast('Failed to update filters: ' + err.message);
    }
  }

  async function renameFeed(feed) {
    const input = window.prompt('Rename feed:', feed.title);
    if (input === null) return;
    const title = input.trim();
    if (title === '' || title === feed.title) return;
    try {
      await patch('feeds.php?id=' + encodeURIComponent(feed.id), { title });
      if (state.filter.type === 'feed' && state.filter.id === feed.id) {
        setPaneTitle(title, feed.url, feed.item_count || 0, feed);
      }
      await loadFeeds();
      toast(`Renamed to "${title}"`);
    } catch (err) {
      toast('Failed to rename feed: ' + err.message);
    }
  }

  async function renameFolder(folderId, name) {
    const input = window.prompt('Rename folder:', name);
    if (input === null) return;
    const newName = input.trim();
    if (newName === '' || newName === name) return;
    try {
      await patch('folders.php?id=' + encodeURIComponent(folderId), { name: newName });
      if (state.filter.type === 'folder' && state.filter.id === folderId) {
        setPaneTitle(newName, null);
      }
      await loadFeeds();
      toast(`Renamed to "${newName}"`);
    } catch (err) {
      toast('Failed to rename folder: ' + err.message);
    }
  }

  async function moveFolder(folderId, direction) {
    try {
      await patch('folders.php?id=' + encodeURIComponent(folderId), { direction });
      await loadFeeds();
    } catch (err) {
      toast('Failed to reorder folder: ' + err.message);
    }
  }

  async function removeFolder(folderId, name, feedCount) {
    try {
      await del('folders.php?id=' + encodeURIComponent(folderId) + '&cascade=1');

      const viewingDeletedFolder = state.filter.type === 'folder' && state.filter.id === folderId;
      const viewingDeletedFeed = state.filter.type === 'feed' && !state.feeds.some((f) => f.id === state.filter.id && f.folder_id !== folderId);
      if (viewingDeletedFolder || viewingDeletedFeed) {
        setFilter({ type: 'all', id: null });
        setPaneTitle('All Items', null);
      }

      await loadFeeds();
      await loadItems();
      toast(`Deleted "${name}"` + (feedCount ? ` and ${feedCount} feed${feedCount === 1 ? '' : 's'}` : ''));
    } catch (err) {
      toast('Failed to delete folder: ' + err.message);
    }
  }

  async function saveCurrentSearch() {
    if (state.filter.type !== 'search' || !state.filter.query) return;
    const name = window.prompt('Save this search as:', state.filter.query);
    if (name === null) return;
    const trimmed = name.trim();
    if (trimmed === '') return;
    try {
      await post('saved_searches.php', { name: trimmed, query: state.filter.query });
      await loadFeeds();
      toast(`Saved search "${trimmed}"`);
    } catch (err) {
      toast('Failed to save search: ' + err.message);
    }
  }

  async function removeSavedSearch(id, name) {
    try {
      await del('saved_searches.php?id=' + encodeURIComponent(id));
      if (state.filter.type === 'saved_search' && state.filter.id === id) {
        clearSearchInput();
        setFilter({ type: 'all', id: null });
        setPaneTitle('All Items', null);
      }
      await loadFeeds();
      if (state.filter.type === 'all') await loadItems();
      toast(`Deleted "${name}"`);
    } catch (err) {
      toast('Failed to delete search: ' + err.message);
    }
  }

  // ---------- Rendering: items ----------

  function renderItems() {
    const list = document.getElementById('item-list');
    list.innerHTML = '';
    hoveredItem = null;

    document.getElementById('item-list-empty').hidden = state.items.length !== 0;

    state.items.forEach((item, idx) => {
      // Two items can legitimately share an id (e.g. the same article surfaced
      // by two different feed subscriptions) — idx is what disambiguates which
      // row is actually the keyboard-selected one, since ids alone can't.
      const isSelected = idx === state.selectedIndex && item.id === state.selectedItemId;
      const li = document.createElement('li');
      li.className = 'item-row' + (item.read ? ' read' : '') + (item.starred ? ' starred' : '') + (isSelected ? ' selected' : '') + (state.bulkSelectedIds.has(item.id) ? ' bulk-selected' : '');
      li.dataset.itemId = item.id;

      const dot = document.createElement('span');
      dot.className = 'dot';

      const content = document.createElement('div');
      content.className = 'content';

      const title = document.createElement('p');
      title.className = 'title';
      title.textContent = item.title || '(untitled)';

      const meta = document.createElement('div');
      meta.className = 'meta';
      meta.textContent = (item.feed_title || '') + (item.published ? ' · ' + timeAgo(item.published) : '');

      content.appendChild(title);
      content.appendChild(meta);

      const actionsRow = document.createElement('div');
      actionsRow.className = 'item-actions-row';

      if (item.link) {
        const openBtn = document.createElement('button');
        openBtn.type = 'button';
        openBtn.className = 'show-page-btn';
        openBtn.textContent = 'Show page';
        openBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          openItem(item, li);
        });

        actionsRow.appendChild(openBtn);
      }

      const readToggleBtn = document.createElement('button');
      readToggleBtn.type = 'button';
      readToggleBtn.className = 'item-read-toggle';
      readToggleBtn.textContent = item.read ? '○' : '✓';
      readToggleBtn.title = item.read ? 'Mark unread' : 'Mark read';
      readToggleBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        toggleItemRead(item, li);
      });

      const starToggleBtn = document.createElement('button');
      starToggleBtn.type = 'button';
      starToggleBtn.className = 'item-star-toggle';
      starToggleBtn.innerHTML = item.starred ? BOOKMARK_FILLED_ICON : BOOKMARK_ICON;
      starToggleBtn.title = item.starred ? 'Remove from Saved' : 'Save';
      starToggleBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        toggleItemStar(item, li);
      });

      if (state.bulkMode) {
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'bulk-checkbox';
        checkbox.checked = state.bulkSelectedIds.has(item.id);
        checkbox.setAttribute('aria-label', 'Select item');
        checkbox.addEventListener('click', (e) => {
          e.stopPropagation();
          toggleBulkSelected(item.id, li);
        });
        li.appendChild(checkbox);
      }

      // Swipe (touch only) slides `fg` aside to reveal this hint underneath —
      // stands in for the hover-revealed .item-actions buttons, which a
      // touch device can never hover to see. See attachItemRowSwipe().
      const swipeHint = document.createElement('div');
      swipeHint.className = 'item-row-swipe-hint';
      li.appendChild(swipeHint);

      const fg = document.createElement('div');
      fg.className = 'item-row-fg';

      fg.appendChild(dot);
      const feed = state.feeds.find((f) => f.id === item.feed_id);
      const thumbCandidates = [item.image, feed?.image_url, faviconUrlFor(feed || {})].filter(Boolean);
      if (thumbCandidates.length) {
        const thumb = document.createElement('img');
        thumb.className = 'item-thumb';
        thumb.src = thumbCandidates[0];
        thumb.alt = '';
        thumb.loading = 'lazy';
        thumb.referrerPolicy = 'no-referrer';
        let candidateIdx = 0;
        thumb.addEventListener('error', () => {
          candidateIdx += 1;
          if (candidateIdx < thumbCandidates.length) {
            thumb.src = thumbCandidates[candidateIdx];
          } else {
            thumb.remove();
          }
        });
        fg.appendChild(thumb);
      }
      const itemActions = document.createElement('div');
      itemActions.className = 'item-actions';
      itemActions.appendChild(starToggleBtn);
      itemActions.appendChild(readToggleBtn);
      actionsRow.appendChild(itemActions);
      content.appendChild(actionsRow);

      fg.appendChild(content);
      li.appendChild(fg);

      // Shared with attachItemRowSwipe below: a swipe that crossed the
      // activation threshold shouldn't also fire the tap-to-open handler
      // once the finger lifts, even though preventDefault() during the drag
      // already suppresses the browser's own synthetic click in most cases.
      const swipeGuard = { justSwiped: false };

      li.addEventListener('click', () => {
        if (swipeGuard.justSwiped) {
          swipeGuard.justSwiped = false;
          return;
        }
        if (state.bulkMode) {
          toggleBulkSelected(item.id, li);
          return;
        }
        selectItem(item.id, idx);
        markItemRead(item, li);
        if (isMobileSidebarLayout()) {
          openMobileReadingPane();
        }
      });
      li.addEventListener('mouseenter', () => {
        hoveredItem = { item, rowEl: li };
      });
      li.addEventListener('mouseleave', () => {
        if (hoveredItem && hoveredItem.rowEl === li) {
          hoveredItem = null;
        }
      });
      if (!state.bulkMode) {
        attachItemRowSwipe(li, fg, swipeHint, item, swipeGuard);
      }

      list.appendChild(li);
    });

    // The reading pane mirrors whatever's selected, but a reload (folder switch,
    // search, refresh) can leave state.selectedIndex pointing at a stale position
    // or state.selectedItemId at an item no longer in this list — re-resolve
    // against the fresh list rather than trusting the old index blindly.
    if (state.selectedIndex >= 0 && state.items[state.selectedIndex]?.id === state.selectedItemId) {
      renderReadingPane(state.items[state.selectedIndex]);
    } else {
      const foundIndex = state.items.findIndex((it) => it.id === state.selectedItemId);
      if (foundIndex !== -1) {
        renderReadingPane(state.items[foundIndex]);
      } else if (state.items.length > 0 && !state.bulkMode) {
        // The previously-selected item isn't in this list (feed/folder switch,
        // or it dropped out of an unread/starred/search view) — jump to the
        // top item instead of leaving the reading pane blank.
        selectItem(state.items[0].id, 0);
      } else {
        renderReadingPane(null);
      }
    }
  }

  const SWIPE_ACTIVATE_PX = 10;
  const SWIPE_ACTION_PX = 70;
  const SWIPE_MAX_PX = 110;

  /**
   * Touch-only left/right swipe on an item row: dragging right reveals a
   * "mark read/unread" hint (mirrors Mail-style leading actions), dragging
   * left reveals "Save"/"Remove Saved". Crossing SWIPE_ACTION_PX on release
   * commits the action; anything short of that snaps back with no effect.
   * Exists because .item-actions only becomes visible on :hover, which touch
   * devices can never trigger.
   */
  function attachItemRowSwipe(li, fg, hint, item, swipeGuard) {
    let pointerId = null;
    let startX = 0;
    let startY = 0;
    let dx = 0;
    let active = false;
    let aborted = false;

    function endGesture(commit) {
      if (active) {
        swipeGuard.justSwiped = true;
        li.classList.remove('swiping');
        fg.style.transition = 'transform .18s ease';
        fg.style.transform = '';
        if (commit && Math.abs(dx) >= SWIPE_ACTION_PX) {
          if (dx > 0) {
            toggleItemRead(item, li);
          } else {
            toggleItemStar(item, li);
          }
        }
        // Idempotent, and also invoked directly as a timeout fallback below:
        // if the net drag distance happens to land back at 0 (fast in-and-out
        // flick), setting transform to its already-current value fires no
        // transitionend, and the hint would otherwise stay stuck visible.
        const cleanup = () => {
          fg.style.transition = '';
          hint.style.display = 'none';
          fg.removeEventListener('transitionend', cleanup);
        };
        fg.addEventListener('transitionend', cleanup);
        setTimeout(cleanup, 200);
        // Safety net: the browser normally never fires the synthetic click
        // this guards against once preventDefault() ran during the drag, but
        // clear it either way so a missed edge case can't wedge the row.
        setTimeout(() => { swipeGuard.justSwiped = false; }, 400);
      }
      if (pointerId !== null) {
        try {
          if (li.hasPointerCapture(pointerId)) li.releasePointerCapture(pointerId);
        } catch { /* pointer already gone (cancel/disconnect) — nothing to release */ }
      }
      pointerId = null;
      active = false;
      aborted = false;
      dx = 0;
    }

    li.addEventListener('pointerdown', (e) => {
      if (e.pointerType !== 'touch' || pointerId !== null) return;
      pointerId = e.pointerId;
      startX = e.clientX;
      startY = e.clientY;
    });

    li.addEventListener('pointermove', (e) => {
      if (e.pointerId !== pointerId || aborted) return;
      const curDx = e.clientX - startX;
      const curDy = e.clientY - startY;
      if (!active) {
        if (Math.abs(curDx) > SWIPE_ACTIVATE_PX && Math.abs(curDx) > Math.abs(curDy) * 1.2) {
          active = true;
          li.classList.add('swiping');
          fg.style.transition = 'none';
          try { li.setPointerCapture(pointerId); } catch { /* best-effort */ }
        } else if (Math.abs(curDy) > SWIPE_ACTIVATE_PX) {
          // Vertical drag — this is a list scroll, not a swipe. Let it through untouched.
          aborted = true;
          return;
        } else {
          return;
        }
      }
      e.preventDefault();
      dx = curDx;
      const overshoot = Math.max(0, Math.abs(dx) - SWIPE_MAX_PX);
      const clamped = Math.sign(dx) * (Math.min(Math.abs(dx), SWIPE_MAX_PX) + overshoot * 0.25);
      fg.style.transform = `translateX(${clamped}px)`;
      hint.className = 'item-row-swipe-hint ' + (dx > 0 ? 'left' : 'right');
      hint.textContent = dx > 0
        ? (item.read ? 'Mark unread' : 'Mark read')
        : (item.starred ? 'Remove from Saved' : 'Save');
      hint.style.display = 'flex';
    });

    li.addEventListener('pointerup', (e) => {
      if (e.pointerId !== pointerId) return;
      endGesture(true);
    });
    li.addEventListener('pointercancel', (e) => {
      if (e.pointerId !== pointerId) return;
      endGesture(false);
    });
  }

  function selectItem(itemId, index) {
    const rows = document.querySelectorAll('#item-list li');
    if (state.selectedIndex >= 0 && rows[state.selectedIndex]) {
      rows[state.selectedIndex].classList.remove('selected');
    }
    state.selectedItemId = itemId;
    state.selectedIndex = index;
    const row = rows[index];
    if (row) {
      row.classList.add('selected');
      scrollItemIntoView(row);
    }
    renderReadingPane(state.items[index] || null);
    persistSelectedItem(itemId);
  }

  // Debounced so holding down j/k (or arrow keys) doesn't fire a localStorage
  // write + server PATCH on every single row it passes through — only the
  // position the user actually settles on gets persisted.
  let persistSelectedItemTimer = null;
  function persistSelectedItem(itemId) {
    clearTimeout(persistSelectedItemTimer);
    persistSelectedItemTimer = setTimeout(() => {
      try {
        localStorage.setItem(SELECTED_ITEM_KEY, itemId);
      } catch (e) {
        // Ignore storage errors (e.g. private browsing quota).
      }
      saveUiPref({ selected_item_id: itemId });
    }, 500);
  }

  // Re-selects whatever item was open before the last reload, once the item list for the
  // restored filter has loaded — a no-op if that item isn't in the current list (deleted,
  // or the filter no longer includes it).
  function restoreSelectedItem() {
    let itemId = state.serverSelectedItemId;
    if (!itemId) {
      try {
        itemId = localStorage.getItem(SELECTED_ITEM_KEY);
      } catch (e) {
        return;
      }
    }
    if (!itemId) return;
    const index = state.items.findIndex((it) => it.id === itemId);
    if (index === -1) return;
    selectItem(itemId, index);
  }

  // ---------- Rendering: reading pane ----------

  let currentReadingPaneItem = null;

  function renderReadingPane(item) {
    const empty = document.getElementById('reading-pane-empty');
    const article = document.getElementById('reading-pane-article');
    // Navigating to a new item should present it fresh from the top, not
    // wherever the previous item happened to be scrolled to — otherwise
    // arrowing/j-k-ing through items while reading can land you mid-article
    // (or past its end) with no visual sign why.
    document.getElementById('reading-pane').scrollTop = 0;
    currentReadingPaneItem = item || null;
    if (!item) {
      empty.hidden = false;
      article.hidden = true;
      return;
    }
    empty.hidden = true;
    article.hidden = false;

    const link = document.getElementById('reading-pane-link');
    link.textContent = item.title || '(untitled)';
    link.href = item.link || '#';

    document.getElementById('reading-pane-meta').textContent =
      (item.feed_title || '') + (item.published ? ' · ' + timeAgo(item.published) : '');

    renderReadingPaneTags(item);
    renderReadingPaneComment(item);

    const summaryEl = document.getElementById('reading-pane-summary');
    summaryEl.innerHTML = '';
    let summaryHasImage = false;
    if (item.summary) {
      const rendered = sanitizeHtml(item.summary);
      highlightMatches(rendered, currentSearchQueryWords());
      summaryHasImage = !!rendered.querySelector('img');
      summaryEl.appendChild(rendered);
    } else {
      summaryEl.textContent = 'No summary available.';
    }

    // Skip the header image when the summary body already has one — avoids
    // showing the same picture twice at the top of the reading pane.
    const feed = state.feeds.find((f) => f.id === item.feed_id);
    const imgCandidates = summaryHasImage ? [] : [item.image, feed?.image_url].filter(Boolean);
    const img = document.getElementById('reading-pane-image');
    let imgIdx = 0;
    img.onerror = () => {
      imgIdx += 1;
      if (imgIdx < imgCandidates.length) {
        img.src = imgCandidates[imgIdx];
      } else {
        img.hidden = true;
        img.removeAttribute('src');
      }
    };
    if (imgCandidates.length) {
      img.src = imgCandidates[0];
      img.hidden = false;
    } else {
      img.hidden = true;
      img.removeAttribute('src');
    }
  }

  // Free-text tags attached to an item — shown in the reading pane, with autocomplete
  // against tags already in use elsewhere.
  function renderReadingPaneTags(item) {
    const list = document.getElementById('reading-pane-tags-list');
    list.innerHTML = '';
    const tags = (item && item.tags) || [];
    for (const tag of tags) {
      const chip = document.createElement('span');
      chip.className = 'tag-chip';
      const label = document.createElement('span');
      label.textContent = tag;
      chip.appendChild(label);
      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.textContent = '×';
      removeBtn.setAttribute('aria-label', `Remove tag "${tag}"`);
      removeBtn.addEventListener('click', () => removeReadingPaneTag(tag));
      chip.appendChild(removeBtn);
      list.appendChild(chip);
    }
  }

  function closeTagSuggestMenu() {
    document.getElementById('reading-pane-tag-suggest').hidden = true;
  }

  function positionTagSuggestMenu() {
    const input = document.getElementById('reading-pane-tag-input');
    const menu = document.getElementById('reading-pane-tag-suggest');
    const rect = input.getBoundingClientRect();
    menu.style.left = rect.left + 'px';
    menu.style.top = (rect.bottom + 4) + 'px';
  }

  function renderTagSuggestMenu(query) {
    const menu = document.getElementById('reading-pane-tag-suggest');
    const item = currentReadingPaneItem;
    const existing = new Set((item && item.tags) || []);
    const q = query.trim().toLowerCase();
    const matches = q === ''
      ? []
      : state.tags.filter((t) => !existing.has(t.name) && t.name.includes(q)).slice(0, 8);

    if (matches.length === 0) {
      closeTagSuggestMenu();
      return;
    }

    menu.innerHTML = '';
    for (const tag of matches) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = tag.name;
      btn.addEventListener('click', () => addReadingPaneTag(tag.name));
      menu.appendChild(btn);
    }
    positionTagSuggestMenu();
    menu.hidden = false;
  }

  async function addReadingPaneTag(rawTag) {
    const item = currentReadingPaneItem;
    const tag = rawTag.trim().toLowerCase();
    if (!item || tag === '') return;
    const current = item.tags || [];
    if (current.includes(tag)) return;
    const updated = [...current, tag];
    item.tags = updated;
    renderReadingPaneTags(item);
    document.getElementById('reading-pane-tag-input').value = '';
    closeTagSuggestMenu();
    try {
      await post('items.php', { action: 'set_tags', feed_id: item.feed_id, item_id: item.id, tags: updated });
      await loadTags();
      renderSidebar();
    } catch (e) {
      item.tags = current;
      renderReadingPaneTags(item);
      toast('Failed to add tag: ' + e.message);
    }
  }

  async function removeReadingPaneTag(tag) {
    const item = currentReadingPaneItem;
    if (!item) return;
    const current = item.tags || [];
    const updated = current.filter((t) => t !== tag);
    item.tags = updated;
    renderReadingPaneTags(item);
    try {
      await post('items.php', { action: 'set_tags', feed_id: item.feed_id, item_id: item.id, tags: updated });
      await loadTags();
      renderSidebar();
    } catch (e) {
      item.tags = current;
      renderReadingPaneTags(item);
      toast('Failed to remove tag: ' + e.message);
    }
  }

  const readingPaneTagInput = document.getElementById('reading-pane-tag-input');
  readingPaneTagInput.addEventListener('input', () => {
    renderTagSuggestMenu(readingPaneTagInput.value);
  });
  readingPaneTagInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      addReadingPaneTag(readingPaneTagInput.value);
    } else if (e.key === 'Escape') {
      closeTagSuggestMenu();
    }
  });

  // Personal note attached to an item — shown above the article body when set.
  function renderReadingPaneComment(item) {
    const wrap = document.getElementById('reading-pane-comment');
    const view = document.getElementById('reading-pane-comment-view');
    const text = document.getElementById('reading-pane-comment-text');
    const addBtn = document.getElementById('reading-pane-comment-add-btn');
    document.getElementById('reading-pane-comment-edit').hidden = true;

    if (item && item.comment) {
      text.textContent = item.comment;
      view.hidden = false;
      addBtn.hidden = true;
      wrap.classList.add('boxed');
    } else {
      view.hidden = true;
      addBtn.hidden = false;
      wrap.classList.remove('boxed');
    }
  }

  function openReadingPaneCommentEditor() {
    const item = currentReadingPaneItem;
    if (!item) return;
    document.getElementById('reading-pane-comment-view').hidden = true;
    document.getElementById('reading-pane-comment-add-btn').hidden = true;
    document.getElementById('reading-pane-comment').classList.add('boxed');
    const editForm = document.getElementById('reading-pane-comment-edit');
    editForm.hidden = false;
    const input = document.getElementById('reading-pane-comment-input');
    input.value = item.comment || '';
    input.focus();
  }

  async function saveReadingPaneComment() {
    const item = currentReadingPaneItem;
    if (!item) return;
    const comment = document.getElementById('reading-pane-comment-input').value.trim();
    try {
      await post('items.php', { action: 'set_comment', feed_id: item.feed_id, item_id: item.id, comment });
      item.comment = comment || null;
      renderReadingPaneComment(item);
    } catch (e) {
      toast('Failed to save note: ' + e.message);
    }
  }

  async function deleteReadingPaneComment() {
    const item = currentReadingPaneItem;
    if (!item || !item.comment) return;
    const previous = item.comment;
    item.comment = null;
    renderReadingPaneComment(item);
    try {
      await post('items.php', { action: 'set_comment', feed_id: item.feed_id, item_id: item.id, comment: '' });
    } catch (e) {
      item.comment = previous;
      renderReadingPaneComment(item);
      toast('Failed to delete note: ' + e.message);
    }
  }

  document.getElementById('reading-pane-comment-add-btn').addEventListener('click', openReadingPaneCommentEditor);
  document.getElementById('reading-pane-comment-edit-btn').addEventListener('click', openReadingPaneCommentEditor);
  document.getElementById('reading-pane-comment-delete-btn').addEventListener('click', deleteReadingPaneComment);
  document.getElementById('reading-pane-comment-save-btn').addEventListener('click', saveReadingPaneComment);
  document.getElementById('reading-pane-comment-cancel-btn').addEventListener('click', () => {
    renderReadingPaneComment(currentReadingPaneItem);
  });

  // On phone-width screens the item list and reading pane share one column
  // (see the max-width:600px rules), so tapping an item switches to a
  // full-width summary view instead of the desktop's side-by-side panes.
  function openMobileReadingPane() {
    document.getElementById('content-columns').classList.add('mobile-reading-open');
  }

  function closeMobileReadingPane() {
    document.getElementById('content-columns').classList.remove('mobile-reading-open');
  }

  document.getElementById('reading-pane-back-btn').addEventListener('click', closeMobileReadingPane);

  // Keeps the selected row visible, but when keyboard nav pushes it past the
  // edge of #item-pane, jumps ahead by half a page instead of scrolling just
  // enough to reveal that one row — so repeated ArrowDown/j presses don't hug
  // the bottom edge one line at a time.
  function scrollItemIntoView(row) {
    const pane = document.getElementById('item-pane');
    if (!pane) {
      row.scrollIntoView({ block: 'nearest' });
      return;
    }
    const paneRect = pane.getBoundingClientRect();
    const scrollPaddingTop = parseFloat(getComputedStyle(pane).scrollPaddingTop) || 0;
    const viewTop = paneRect.top + scrollPaddingTop;
    const viewBottom = paneRect.bottom;
    const rowRect = row.getBoundingClientRect();

    if (rowRect.bottom > viewBottom) {
      pane.scrollTop += pane.clientHeight / 2;
    } else if (rowRect.top < viewTop) {
      pane.scrollTop -= pane.clientHeight / 2;
    } else {
      return;
    }
    row.scrollIntoView({ block: 'nearest' });
  }

  // Resolves the item + row the keyboard shortcuts (space/s/e/Enter) should act
  // on. Prefers the tracked index (unambiguous even when two items share an id);
  // falls back to an id-based lookup if the index is stale (e.g. the list was
  // just reloaded and the previous selection no longer exists in it).
  function selectedItemAndRow() {
    if (state.selectedIndex >= 0 && state.selectedIndex < state.items.length) {
      const item = state.items[state.selectedIndex];
      if (item && item.id === state.selectedItemId) {
        const rowEl = document.querySelectorAll('#item-list li')[state.selectedIndex];
        if (rowEl) return { item, rowEl };
      }
    }
    if (!state.selectedItemId) return null;
    const item = state.items.find((it) => it.id === state.selectedItemId);
    const rowEl = document.querySelector(`#item-list li[data-item-id="${state.selectedItemId}"]`);
    return item && rowEl ? { item, rowEl } : null;
  }

  function setPane(pane) {
    state.pane = pane;
    document.getElementById('sidebar').classList.toggle('pane-focused', pane === 'sidebar');
    document.getElementById('item-pane').classList.toggle('pane-focused', pane === 'items');
    document.getElementById('reading-pane').classList.toggle('pane-focused', pane === 'reading');
  }

  function toggleBulkSelected(itemId, li) {
    const nowSelected = !state.bulkSelectedIds.has(itemId);
    if (nowSelected) {
      state.bulkSelectedIds.add(itemId);
    } else {
      state.bulkSelectedIds.delete(itemId);
    }
    if (li) {
      li.classList.toggle('bulk-selected', nowSelected);
      const checkbox = li.querySelector('.bulk-checkbox');
      if (checkbox) checkbox.checked = nowSelected;
    }
    updateBulkActionsUI();
  }

  function updateBulkActionsUI() {
    const toggleBtn = document.getElementById('bulk-select-toggle-btn');
    toggleBtn.textContent = state.bulkMode ? 'Cancel' : 'Select';
    toggleBtn.classList.toggle('active', state.bulkMode);

    const n = state.bulkSelectedIds.size;
    const showActions = state.bulkMode && n > 0;
    document.getElementById('bulk-mark-read-btn').hidden = !showActions;
    document.getElementById('bulk-mark-unread-btn').hidden = !showActions;
    document.getElementById('bulk-mark-read-btn').textContent = `Mark ${n} read`;
    document.getElementById('bulk-mark-unread-btn').textContent = `Mark ${n} unread`;
    document.getElementById('mark-all-read-btn').hidden = state.bulkMode;
  }

  async function bulkMarkAction(action) {
    const itemIds = [...state.bulkSelectedIds];
    if (itemIds.length === 0) return;
    try {
      await post('items.php', { action, item_ids: itemIds });
      state.bulkMode = false;
      state.bulkSelectedIds.clear();
      updateBulkActionsUI();
      await loadFeeds();
      await loadItems();
    } catch (err) {
      toast('Failed: ' + err.message);
    }
  }

  function timeAgo(iso) {
    const diffMs = Date.now() - new Date(iso).getTime();
    const mins = Math.floor(diffMs / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return mins + 'm ago';
    const hours = Math.floor(mins / 60);
    if (hours < 24) return hours + 'h ago';
    const days = Math.floor(hours / 24);
    return days + 'd ago';
  }

  async function markItemRead(item, rowEl) {
    if (item.read) return;
    item.read = true;
    rowEl.classList.add('read');
    updateReadToggleButton(rowEl, true);
    const feed = state.feeds.find((f) => f.id === item.feed_id);
    if (feed && feed.unread_count > 0) feed.unread_count--;
    renderSidebar();
    try {
      await post('items.php', { action: 'mark_read', item_ids: [item.id] });
    } catch (e) {
      item.read = false;
      rowEl.classList.remove('read');
      updateReadToggleButton(rowEl, false);
      if (feed) feed.unread_count++;
      renderSidebar();
      toast('Failed to mark read: ' + e.message);
    }
  }

  function updateReadToggleButton(rowEl, read) {
    const btn = rowEl.querySelector('.item-read-toggle');
    if (!btn) return;
    btn.textContent = read ? '○' : '✓';
    btn.title = read ? 'Mark unread' : 'Mark read';
  }

  async function toggleItemRead(item, rowEl) {
    const wasRead = item.read;
    const nowRead = !wasRead;
    item.read = nowRead;
    rowEl.classList.toggle('read', nowRead);
    updateReadToggleButton(rowEl, nowRead);
    const feed = state.feeds.find((f) => f.id === item.feed_id);
    if (feed) {
      feed.unread_count = Math.max(0, (feed.unread_count || 0) + (nowRead ? -1 : 1));
    }
    renderSidebar();
    try {
      await post('items.php', { action: nowRead ? 'mark_read' : 'mark_unread', item_ids: [item.id] });
    } catch (e) {
      item.read = wasRead;
      rowEl.classList.toggle('read', wasRead);
      updateReadToggleButton(rowEl, wasRead);
      if (feed) {
        feed.unread_count = Math.max(0, (feed.unread_count || 0) + (nowRead ? 1 : -1));
      }
      renderSidebar();
      toast('Failed to update read status: ' + e.message);
    }
  }

  function updateStarToggleButton(rowEl, starred) {
    const btn = rowEl.querySelector('.item-star-toggle');
    if (!btn) return;
    btn.innerHTML = starred ? BOOKMARK_FILLED_ICON : BOOKMARK_ICON;
    btn.title = starred ? 'Remove from Saved' : 'Save';
  }

  async function toggleItemStar(item, rowEl) {
    const wasStarred = item.starred;
    const nowStarred = !wasStarred;
    item.starred = nowStarred;
    rowEl.classList.toggle('starred', nowStarred);
    updateStarToggleButton(rowEl, nowStarred);
    const feed = state.feeds.find((f) => f.id === item.feed_id);
    if (feed) {
      feed.starred_count = Math.max(0, (feed.starred_count || 0) + (nowStarred ? 1 : -1));
    }
    renderSidebar();
    try {
      await post('items.php', { action: nowStarred ? 'star' : 'unstar', item_ids: [item.id] });
    } catch (e) {
      item.starred = wasStarred;
      rowEl.classList.toggle('starred', wasStarred);
      updateStarToggleButton(rowEl, wasStarred);
      if (feed) {
        feed.starred_count = Math.max(0, (feed.starred_count || 0) + (nowStarred ? -1 : 1));
      }
      renderSidebar();
      toast('Failed to update starred status: ' + e.message);
    }
  }

  async function openItem(item, rowEl) {
    if (item.link) {
      window.open(item.link, '_blank', 'noopener,noreferrer');
    }
    await markItemRead(item, rowEl);
  }

  function currentSearchQueryWords() {
    const type = state.filter.type;
    if ((type !== 'search' && type !== 'saved_search') || !state.filter.query) {
      return [];
    }
    return foldAccentsJs(state.filter.query).split(/\s+/).filter(Boolean);
  }

  // ---------- Actions ----------

  async function submitAddFeed(url, folderId) {
    try {
      const result = await post('feeds.php', { url, folder_id: folderId });
      if (result.candidates) {
        showFeedCandidates(result.candidates, folderId);
        return;
      }
      hideFeedCandidates();
      document.getElementById('add-feed-url').value = '';
      await loadFeeds();
      if (result.feed && result.feed.last_status === 'error') {
        toast('Feed added, but the last refresh failed: ' + (result.feed.last_error || 'unknown error'));
      } else {
        toast('Feed added');
      }
    } catch (err) {
      toast('Failed to add feed: ' + err.message);
    }
  }

  function showFeedCandidates(candidates, folderId) {
    const row = document.getElementById('add-feed-candidates-row');
    const select = document.getElementById('add-feed-candidates');
    select.innerHTML = '';
    for (const c of candidates) {
      const opt = document.createElement('option');
      opt.value = c.url;
      opt.textContent = c.title;
      select.appendChild(opt);
    }
    row.hidden = false;
    row.dataset.folderId = folderId || '';
  }

  function hideFeedCandidates() {
    document.getElementById('add-feed-candidates-row').hidden = true;
  }

  document.getElementById('add-feed-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const urlInput = document.getElementById('add-feed-url');
    const folderSelect = document.getElementById('add-feed-folder');
    let url = urlInput.value.trim();
    if (!url) return;
    if (!/^https?:\/\//i.test(url)) {
      url = 'https://' + url;
    }
    const btn = e.target.querySelector('button[type="submit"]');
    btn.disabled = true;
    const originalLabel = btn.textContent;
    btn.innerHTML = SPIN_ICON + ' Adding…';
    try {
      await submitAddFeed(url, folderSelect.value || null);
    } finally {
      btn.disabled = false;
      btn.textContent = originalLabel;
    }
  });

  document.getElementById('add-feed-candidates-confirm-btn').addEventListener('click', async (e) => {
    const row = document.getElementById('add-feed-candidates-row');
    const select = document.getElementById('add-feed-candidates');
    const btn = e.currentTarget;
    btn.disabled = true;
    const originalLabel = btn.textContent;
    btn.innerHTML = SPIN_ICON + ' Adding…';
    try {
      await submitAddFeed(select.value, row.dataset.folderId || null);
    } finally {
      btn.disabled = false;
      btn.textContent = originalLabel;
    }
  });

  document.getElementById('settings-opml-input').addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;

    const confirmed = window.confirm(
      'Importing this OPML file will delete ALL your current feeds and folders (and their stored items) and replace them with what\'s in the file. This cannot be undone. Continue?'
    );
    if (!confirmed) {
      e.target.value = '';
      return;
    }

    const formData = new FormData();
    formData.append('opml_file', file);
    try {
      const result = await apiFetch('import.php', { method: 'POST', body: formData });
      toast(`Replaced feeds: removed ${result.feeds_removed}, imported ${result.feeds_created} feeds / ${result.folders_created} folders (${result.feeds_skipped} skipped)`);
      await loadFeeds();
      await post('refresh.php', {});
      await loadFeeds();
      await loadItems();
    } catch (err) {
      toast('OPML import failed: ' + err.message);
    } finally {
      e.target.value = '';
    }
  });

  document.getElementById('settings-backup-input').addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;

    const confirmed = window.confirm(
      'Restoring this backup will delete ALL your current feeds, folders, filters, saved searches, and item read/starred state, and replace them with what\'s in the file. This cannot be undone. Continue?'
    );
    if (!confirmed) {
      e.target.value = '';
      return;
    }

    try {
      // Sent as the raw file bytes (not decoded as text) since a downloaded
      // backup is gzip-compressed binary — the server auto-detects gzip vs.
      // plain JSON by content, so the exact declared Content-Type doesn't matter.
      const result = await apiFetch('backup-restore.php', { method: 'POST', body: file });
      toast(`Restored ${result.feeds_restored} feeds / ${result.items_restored} items — reloading…`);
      setTimeout(() => window.location.reload(), 1200);
    } catch (err) {
      toast('Backup restore failed: ' + err.message);
      e.target.value = '';
    }
  });

  document.getElementById('refresh-all-btn').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    btn.disabled = true;
    const originalLabel = btn.textContent;
    btn.textContent = 'Refreshing…';
    document.getElementById('busy-overlay').hidden = false;
    try {
      await post('refresh.php', { force: true });
      await loadFeeds();
      await loadItems();
      toast('Refreshed');
    } catch (err) {
      toast('Refresh failed: ' + err.message);
    } finally {
      document.getElementById('busy-overlay').hidden = true;
      btn.disabled = false;
      btn.textContent = originalLabel;
    }
  });

  document.getElementById('mark-all-read-btn').addEventListener('click', async () => {
    const body = { action: 'mark_all_read' };
    if (state.filter.type === 'feed') body.feed_id = state.filter.id;
    if (state.filter.type === 'folder') body.folder_id = state.filter.id;
    try {
      await post('items.php', body);
      await loadFeeds();
      await loadItems();
    } catch (err) {
      toast('Failed: ' + err.message);
    }
  });

  document.getElementById('bulk-select-toggle-btn').addEventListener('click', () => {
    state.bulkMode = !state.bulkMode;
    if (!state.bulkMode) state.bulkSelectedIds.clear();
    updateBulkActionsUI();
    renderItems();
  });
  document.getElementById('bulk-mark-read-btn').addEventListener('click', () => bulkMarkAction('mark_read'));
  document.getElementById('bulk-mark-unread-btn').addEventListener('click', () => bulkMarkAction('mark_unread'));

  document.getElementById('sort-order-toggle-btn').addEventListener('click', () => {
    const key = state.filter.type === 'unread' ? 'sortOrderUnread' : 'sortOrder';
    const storageKey = state.filter.type === 'unread' ? SORT_ORDER_UNREAD_KEY : SORT_ORDER_KEY;
    state[key] = state[key] === 'desc' ? 'asc' : 'desc';
    try {
      localStorage.setItem(storageKey, state[key]);
    } catch (e) {
      // Ignore storage errors (e.g. private browsing quota).
    }
    saveUiPref({ [state.filter.type === 'unread' ? 'sort_order_unread' : 'sort_order']: state[key] });
    updateSortOrderButton();
    loadItems();
  });

  document.getElementById('refresh-interval-btn').addEventListener('click', (e) => {
    e.stopPropagation();
    const popover = document.getElementById('refresh-interval-popover');
    if (popover.hidden) {
      openRefreshIntervalPopover();
    } else {
      closeRefreshIntervalPopover();
    }
  });

  document.addEventListener('click', (e) => {
    const wrap = document.getElementById('item-pane-refresh-interval');
    if (!wrap.contains(e.target)) closeRefreshIntervalPopover();
    const menu = document.getElementById('feed-row-menu');
    if (!menu.contains(e.target)) closeFeedRowMenu();
    const tagInputWrap = document.getElementById('reading-pane-tag-input-wrap');
    if (!tagInputWrap.contains(e.target)) closeTagSuggestMenu();
  });

  document.getElementById('sidebar').addEventListener('scroll', () => closeFeedRowMenu());

  document.getElementById('folder-list').addEventListener('click', () => setPane('sidebar'));
  document.getElementById('item-pane').addEventListener('click', () => setPane('items'));
  document.getElementById('reading-pane').addEventListener('click', () => setPane('reading'));

  (function () {
    let debounceTimer = null;
    const input = document.getElementById('search-input');
    input.addEventListener('input', (e) => {
      clearTimeout(debounceTimer);
      const value = e.target.value;
      debounceTimer = setTimeout(() => runSearch(value), 300);
    });
    input.addEventListener('search', (e) => {
      clearTimeout(debounceTimer);
      runSearch(e.target.value);
    });
    input.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape') return;
      e.preventDefault();
      e.stopPropagation();
      clearTimeout(debounceTimer);
      clearSearchInput();
      input.blur();
      runSearch('');
    });
  })();

  document.getElementById('save-search-btn').addEventListener('click', saveCurrentSearch);

  document.getElementById('settings-folder-create-btn').addEventListener('click', async () => {
    const input = document.getElementById('settings-folder-input');
    const name = input.value.trim();
    if (name === '') return;
    try {
      await post('folders.php', { name });
      await loadFeeds();
      input.value = '';
      toast(`Created folder "${name}"`);
    } catch (err) {
      toast('Failed to create folder: ' + err.message);
    }
  });

  document.getElementById('settings-opml-export-btn').addEventListener('click', () => {
    window.location.href = API + 'export.php';
  });

  document.getElementById('settings-backup-export-btn').addEventListener('click', () => {
    // Submitted as a POST navigation (a real <form>, not fetch — a normal page
    // navigation is what makes the browser treat the response as a download).
    // GET didn't survive contact with a host whose shared/edge cache turned
    // out to key on URL path alone, ignoring the query string entirely — no
    // GET-based cache-busting trick can defeat that. No standard cache
    // implementation caches POST responses by default, so this sidesteps the
    // whole class of problem regardless of that cache's own configuration.
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = API + 'backup.php';
    form.hidden = true;
    document.body.appendChild(form);
    form.submit();
    form.remove();
  });

  function openSettings() {
    closeSidebar();
    document.getElementById('settings-sort-feeds-alpha').checked = state.sortFeedsAlphabetically;
    document.getElementById('settings-overlay').hidden = false;
  }

  function closeSettings() {
    document.getElementById('settings-overlay').hidden = true;
    hideFeedCandidates();
  }

  function openSidebar() {
    closeSettings();
    document.getElementById('sidebar').classList.add('open');
    document.getElementById('sidebar-toggle-btn').setAttribute('aria-expanded', 'true');
    document.getElementById('sidebar-backdrop').hidden = false;
  }

  function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebar-toggle-btn').setAttribute('aria-expanded', 'false');
    document.getElementById('sidebar-backdrop').hidden = true;
  }

  function toggleSidebar() {
    if (isMobileSidebarLayout()) {
      const isOpen = document.getElementById('sidebar').classList.contains('open');
      if (isOpen) {
        closeSidebar();
      } else {
        openSidebar();
      }
    } else {
      state.sidebarCollapsed = !state.sidebarCollapsed;
      applySidebarCollapsed(state.sidebarCollapsed);
      persistSidebarCollapsed(state.sidebarCollapsed);
    }
  }

  document.getElementById('settings-btn').addEventListener('click', openSettings);
  document.getElementById('settings-close-btn').addEventListener('click', closeSettings);
  document.getElementById('settings-overlay').addEventListener('click', (e) => {
    if (e.target.id === 'settings-overlay') closeSettings();
  });

  document.getElementById('settings-sort-feeds-alpha').addEventListener('change', (e) => {
    state.sortFeedsAlphabetically = e.target.checked;
    try {
      localStorage.setItem(SORT_FEEDS_ALPHA_KEY, state.sortFeedsAlphabetically ? '1' : '0');
    } catch (err) {
      // Ignore storage errors (e.g. private browsing quota).
    }
    saveUiPref({ sort_feeds_alphabetically: state.sortFeedsAlphabetically });
    renderSidebar();
  });

  function openShortcuts() {
    closeSidebar();
    document.getElementById('shortcuts-overlay').hidden = false;
  }

  function closeShortcuts() {
    document.getElementById('shortcuts-overlay').hidden = true;
  }

  document.getElementById('shortcuts-btn').addEventListener('click', openShortcuts);
  document.getElementById('shortcuts-close-btn').addEventListener('click', closeShortcuts);
  document.getElementById('shortcuts-overlay').addEventListener('click', (e) => {
    if (e.target.id === 'shortcuts-overlay') closeShortcuts();
  });
  document.getElementById('sidebar-toggle-btn').addEventListener('click', toggleSidebar);
  document.getElementById('sidebar-backdrop').addEventListener('click', closeSidebar);

  // ---------- Sidebar resize ----------

  const sidebarResizer = document.getElementById('sidebar-resizer');
  const sidebarEl = document.getElementById('sidebar');
  // Deliberately narrower than a typical tablet portrait width (iPad is 744px+) so
  // iPads/tablets get the same side-by-side layout and click/tap behavior as desktop —
  // only phones get the stacked overlay sidebar and full-screen reading view.
  const isMobileSidebarLayout = () => window.matchMedia('(max-width: 600px)').matches;

  let resizeStartX = 0;
  let resizeStartWidth = 0;

  function persistSidebarWidth(px) {
    try {
      localStorage.setItem(SIDEBAR_WIDTH_KEY, String(px));
    } catch (e) {
      // Tolerate storage errors (private browsing quota etc.), same as other prefs.
    }
    saveUiPref({ sidebar_width: px });
  }

  sidebarResizer.addEventListener('pointerdown', (e) => {
    if (isMobileSidebarLayout() || e.button !== 0) return;
    resizeStartX = e.clientX;
    resizeStartWidth = sidebarEl.getBoundingClientRect().width;
    sidebarResizer.setPointerCapture(e.pointerId);
    sidebarResizer.classList.add('resizing');
    document.body.classList.add('resizing-sidebar');
    e.preventDefault();
  });

  sidebarResizer.addEventListener('pointermove', (e) => {
    if (!sidebarResizer.hasPointerCapture(e.pointerId)) return;
    state.sidebarWidth = applySidebarWidth(resizeStartWidth + (e.clientX - resizeStartX));
  });

  function endSidebarResize(e) {
    if (!sidebarResizer.hasPointerCapture(e.pointerId)) return;
    sidebarResizer.releasePointerCapture(e.pointerId);
    sidebarResizer.classList.remove('resizing');
    document.body.classList.remove('resizing-sidebar');
    persistSidebarWidth(state.sidebarWidth);
  }
  sidebarResizer.addEventListener('pointerup', endSidebarResize);
  sidebarResizer.addEventListener('pointercancel', endSidebarResize);

  // ---------- Reading pane resize ----------

  const readingPaneResizer = document.getElementById('reading-pane-resizer');
  const itemPaneEl = document.getElementById('item-pane');

  let readingResizeStartX = 0;
  let readingResizeStartWidth = 0;

  function persistItemPaneWidth(px) {
    try {
      localStorage.setItem(ITEM_PANE_WIDTH_KEY, String(px));
    } catch (e) {
      // Tolerate storage errors (private browsing quota etc.), same as other prefs.
    }
    saveUiPref({ item_pane_width: px });
  }

  readingPaneResizer.addEventListener('pointerdown', (e) => {
    if (isMobileSidebarLayout() || e.button !== 0) return;
    readingResizeStartX = e.clientX;
    readingResizeStartWidth = itemPaneEl.getBoundingClientRect().width;
    readingPaneResizer.setPointerCapture(e.pointerId);
    readingPaneResizer.classList.add('resizing');
    document.body.classList.add('resizing-reading-pane');
    e.preventDefault();
  });

  readingPaneResizer.addEventListener('pointermove', (e) => {
    if (!readingPaneResizer.hasPointerCapture(e.pointerId)) return;
    state.itemPaneWidth = applyItemPaneWidth(readingResizeStartWidth + (e.clientX - readingResizeStartX));
  });

  function endReadingPaneResize(e) {
    if (!readingPaneResizer.hasPointerCapture(e.pointerId)) return;
    readingPaneResizer.releasePointerCapture(e.pointerId);
    readingPaneResizer.classList.remove('resizing');
    document.body.classList.remove('resizing-reading-pane');
    persistItemPaneWidth(state.itemPaneWidth);
  }
  readingPaneResizer.addEventListener('pointerup', endReadingPaneResize);
  readingPaneResizer.addEventListener('pointercancel', endReadingPaneResize);

  // Keyboard-shortcuts button visibility (best-effort: hide when no
  // hardware keyboard is likely present). `any-hover`/`any-pointer` (not
  // the primary `hover`/`pointer`) is what flips on iPadOS when a
  // trackpad-equipped keyboard is attached — touch stays "primary" there
  // even with a trackpad connected, so the primary-only query never sees it.
  const shortcutsBtn = document.getElementById('shortcuts-btn');
  const pointerCapableQuery = window.matchMedia('(any-hover: hover), (any-pointer: fine)');
  let hardwareKeyboardDetected = pointerCapableQuery.matches;
  shortcutsBtn.hidden = !hardwareKeyboardDetected;
  pointerCapableQuery.addEventListener('change', (e) => {
    // A trackpad/mouse got attached (e.g. an iPad Magic Keyboard) — reveal
    // and never re-hide, to avoid flicker if it's briefly disconnected.
    if (e.matches && !hardwareKeyboardDetected) {
      hardwareKeyboardDetected = true;
      shortcutsBtn.hidden = false;
    }
  });

  // Fallback for a keyboard with no trackpad (e.g. a plain iPad folio
  // case), which the query above can't see. iOS/iPadOS suppresses the
  // on-screen keyboard entirely when a hardware keyboard is attached, so
  // if focusing a text field doesn't shrink the visual viewport, no
  // software keyboard appeared — a hardware keyboard must have handled it.
  document.addEventListener('focusin', (e) => {
    if (hardwareKeyboardDetected || !window.visualViewport) return;
    const t = e.target;
    const isTextField = t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable;
    if (!isTextField) return;
    const heightBefore = window.visualViewport.height;
    setTimeout(() => {
      if (hardwareKeyboardDetected) return;
      if (window.visualViewport.height >= heightBefore - 100) {
        hardwareKeyboardDetected = true;
        shortcutsBtn.hidden = false;
      }
    }, 400);
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      if (!document.getElementById('shortcuts-overlay').hidden) {
        closeShortcuts();
      } else if (!document.getElementById('settings-overlay').hidden) {
        closeSettings();
      } else if (!document.getElementById('refresh-interval-popover').hidden) {
        closeRefreshIntervalPopover();
      } else if (!document.getElementById('feed-row-menu').hidden) {
        closeFeedRowMenu();
      } else if (document.getElementById('sidebar').classList.contains('open')) {
        closeSidebar();
      }
      return;
    }
    const tag = e.target.tagName;
    const isTyping = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || e.target.isContentEditable;

    // Fallback for a hardware keyboard on an otherwise touch-primary device
    // (e.g. an iPad keyboard folio with no trackpad, so the matchMedia
    // listener above never fires). On-screen keyboards only dispatch
    // keydown while a text field is focused, so !isTyping here rules out
    // a virtual-keyboard tap; the other checks filter out IME composition.
    if (!hardwareKeyboardDetected && !isTyping && !e.isComposing && e.keyCode !== 229 && e.key !== 'Unidentified') {
      hardwareKeyboardDetected = true;
      shortcutsBtn.hidden = false;
    }

    if (e.key === '?') {
      if (isTyping) return;
      e.preventDefault();
      openShortcuts();
      return;
    }

    if (e.key === ' ') {
      if (isTyping) return;
      if (state.pane === 'sidebar') return;
      if (state.pane === 'reading') {
        e.preventDefault();
        const pane = document.getElementById('reading-pane');
        const amount = pane.clientHeight * 0.9;
        pane.scrollBy({ top: e.shiftKey ? -amount : amount });
        return;
      }
      const target = selectedItemAndRow() || hoveredItem;
      if (!target) return;
      e.preventDefault();
      toggleItemRead(target.item, target.rowEl);
      return;
    }

    if (e.key === 's') {
      if (isTyping) return;
      if (state.pane === 'sidebar') return;
      const target = selectedItemAndRow() || hoveredItem;
      if (!target) return;
      e.preventDefault();
      toggleItemStar(target.item, target.rowEl);
      return;
    }

    if (e.key === '/') {
      if (isTyping) return;
      e.preventDefault();
      document.getElementById('search-input').focus();
      return;
    }

    if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
      if (isTyping) return;
      e.preventDefault();
      const currentPaneIndex = PANE_ORDER.indexOf(state.pane);
      const nextPaneIndex = Math.max(0, Math.min(PANE_ORDER.length - 1, currentPaneIndex + (e.key === 'ArrowLeft' ? -1 : 1)));
      setPane(PANE_ORDER[nextPaneIndex]);
      return;
    }

    if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'j' || e.key === 'k' || e.key === 'PageDown' || e.key === 'PageUp') {
      if (isTyping) return;
      e.preventDefault();
      const goingDown = e.key === 'ArrowDown' || e.key === 'j' || e.key === 'PageDown';
      const isPageKey = e.key === 'PageDown' || e.key === 'PageUp';
      const step = isPageKey ? 10 : 1;

      if (state.pane === 'reading') {
        const pane = document.getElementById('reading-pane');
        const amount = isPageKey ? pane.clientHeight * 0.9 : 80;
        pane.scrollBy({ top: goingDown ? amount : -amount });
        return;
      }

      if (state.pane === 'sidebar') {
        const rows = Array.from(document.querySelectorAll('#folder-list .sidebar-row')).filter((r) => r.offsetParent !== null);
        if (rows.length === 0) return;
        const currentIndex = rows.findIndex((r) => r.classList.contains('active'));
        let nextIndex;
        if (currentIndex === -1) {
          nextIndex = goingDown ? 0 : rows.length - 1;
        } else {
          nextIndex = currentIndex + (goingDown ? step : -step);
          nextIndex = Math.max(0, Math.min(rows.length - 1, nextIndex));
        }
        rows[nextIndex].click();
        document.querySelector('#folder-list .sidebar-row.active')?.scrollIntoView({ block: 'nearest' });
        return;
      }

      if (state.items.length === 0) return;
      // Trust the tracked index when it still points at the selected item;
      // otherwise (list just reloaded, nothing selected yet) fall back to an
      // id lookup. Using the index directly — rather than re-deriving it from
      // the id via findIndex — is what lets navigation move past an item that
      // shares its id with another one in the list (e.g. the same article
      // pulled in by two feed subscriptions), instead of getting stuck on it.
      const currentIndex = (state.selectedIndex >= 0 && state.items[state.selectedIndex]?.id === state.selectedItemId)
        ? state.selectedIndex
        : state.items.findIndex((it) => it.id === state.selectedItemId);
      let nextIndex;
      if (currentIndex === -1) {
        nextIndex = goingDown ? 0 : state.items.length - 1;
      } else {
        nextIndex = currentIndex + (goingDown ? step : -step);
        nextIndex = Math.max(0, Math.min(state.items.length - 1, nextIndex));
      }
      selectItem(state.items[nextIndex].id, nextIndex);
      return;
    }

    if (e.key === 'Home' || e.key === 'End') {
      if (isTyping) return;
      e.preventDefault();

      if (state.pane === 'reading') {
        const pane = document.getElementById('reading-pane');
        pane.scrollTop = e.key === 'Home' ? 0 : pane.scrollHeight;
        return;
      }

      if (state.pane === 'sidebar') {
        const rows = Array.from(document.querySelectorAll('#folder-list .sidebar-row')).filter((r) => r.offsetParent !== null);
        if (rows.length === 0) return;
        rows[e.key === 'Home' ? 0 : rows.length - 1].click();
        document.querySelector('#folder-list .sidebar-row.active')?.scrollIntoView({ block: 'nearest' });
        return;
      }

      if (state.items.length === 0) return;
      const index = e.key === 'Home' ? 0 : state.items.length - 1;
      selectItem(state.items[index].id, index);
      return;
    }

    if (e.key === 'Enter') {
      if (isTyping) return;
      if (state.pane === 'sidebar') return;
      const target = selectedItemAndRow();
      if (!target) return;
      e.preventDefault();
      openItem(target.item, target.rowEl);
    }
  });

  // ---------- Auth ----------

  let authConfigured = true;

  function showLoginScreen(configured) {
    authConfigured = configured;
    document.getElementById('login-overlay').hidden = false;
    document.getElementById('app-header').hidden = true;
    document.getElementById('app-body').hidden = true;
    document.getElementById('app-footer').hidden = true;
    document.getElementById('login-title-text').textContent = configured ? 'Log in' : 'Create your login';
    document.getElementById('login-submit-btn').textContent = configured ? 'Log in' : 'Create login';
    document.getElementById('login-confirm-row').hidden = configured;
    document.getElementById('login-password-confirm').required = !configured;
    document.getElementById('login-hint').textContent = configured
      ? ''
      : 'Set a username and password to protect this app.';
  }

  function hideLoginScreen() {
    document.getElementById('login-overlay').hidden = true;
    document.getElementById('app-header').hidden = false;
    document.getElementById('app-body').hidden = false;
    document.getElementById('app-footer').hidden = false;
  }

  document.getElementById('login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const username = document.getElementById('login-username').value.trim();
    const password = document.getElementById('login-password').value;
    if (!authConfigured) {
      const confirmPassword = document.getElementById('login-password-confirm').value;
      if (password !== confirmPassword) {
        toast('Passwords do not match');
        return;
      }
    }
    try {
      await post('auth.php', { action: authConfigured ? 'login' : 'setup', username, password });
      hideLoginScreen();
      await init();
    } catch (err) {
      toast('Login failed: ' + err.message);
    }
  });

  document.getElementById('settings-logout-btn').addEventListener('click', async () => {
    try {
      await post('auth.php', { action: 'logout' });
    } catch (err) {
      // ignore — reload regardless so the login screen shows either way
    }
    window.location.reload();
  });

  // ---------- Init ----------

  async function init() {
    await loadSettings();
    await loadFeeds();
    await loadTags();
    restorePersistedFilter();
    renderSidebar();
    updateSortOrderButton();
    setPane(state.pane);
    await loadItems();
    restoreSelectedItem();
    setInterval(renderLastUpdated, 30000);
    setInterval(pollForUpdates, 60000);
  }

  (async function boot() {
    try {
      const status = await get('auth.php');
      if (!status.authenticated) {
        showLoginScreen(status.configured);
        return;
      }
      hideLoginScreen();
      await init();
    } catch (err) {
      toast('Failed to load: ' + err.message);
    }
  })();
})();
