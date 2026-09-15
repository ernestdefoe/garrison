import app from 'flarum/forum/app';

/**
 * The single source every Garrison surface reads.
 *
 * 🚨 ONE poll for the whole page, no matter how many widgets are on it.
 *
 * A page can carry the status page, a sidebar widget, a Bespoke widget and a
 * Page Builder block at once. If each fetched its own data that is four
 * requests per render, and on a busy forum it is four per visitor — which is
 * the failure that once exhausted a database connection cap and 500'd a whole
 * forum. So: one module-level store, one in-flight request shared by every
 * caller, and components subscribe rather than fetch.
 */

let servers = [];
let loaded = false;
let error = null;
let inflight = null;
let timer = null;
const listeners = new Set();

function notify() {
  listeners.forEach((fn) => fn());
  m.redraw();
}

/**
 * Fetch once. Concurrent callers share the same promise rather than each
 * firing their own request — which is the entire point of this module.
 */
export function refresh() {
  if (inflight) return inflight;

  inflight = app
    .request({ method: 'GET', url: app.forum.attribute('apiUrl') + '/garrison/servers' })
    .then((res) => {
      servers = res && Array.isArray(res.data) ? res.data : [];
      loaded = true;
      error = null;
    })
    .catch((e) => {
      // 🚨 A failed poll must not blank the page. The last known state, marked
      // stale, is more useful than an empty panel — and an empty panel reads
      // as "no servers" rather than "could not reach the forum".
      error = e;
      loaded = true;
    })
    .then(() => {
      inflight = null;
      notify();
    });

  return inflight;
}

export function subscribe(fn) {
  listeners.add(fn);

  if (!loaded) refresh();

  // Poll while anybody is watching, and stop the moment nobody is. A forum
  // tab left open overnight must not keep asking.
  if (!timer) {
    timer = setInterval(() => {
      if (document.hidden) return; // a background tab is not watching
      refresh();
    }, 15000);
  }

  return () => {
    listeners.delete(fn);
    if (listeners.size === 0 && timer) {
      clearInterval(timer);
      timer = null;
    }
  };
}

export function all() {
  return servers;
}

export function isLoaded() {
  return loaded;
}

export function lastError() {
  return error;
}

/**
 * Queue a command. Returns the request promise so a caller can show its own
 * pending state; the store refreshes shortly after, because the agent has up
 * to a poll window to act and an immediate refetch would show the old state
 * and look like the button did nothing.
 */
export function command(serverId, verb, params = {}) {
  return app
    .request({
      method: 'POST',
      url: app.forum.attribute('apiUrl') + `/garrison/servers/${serverId}/command`,
      body: { verb, params },
    })
    .then((res) => {
      setTimeout(refresh, 2000);
      return res;
    });
}
