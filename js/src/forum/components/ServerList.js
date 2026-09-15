import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';

import { all, isLoaded, lastError, subscribe } from '../store';

/**
 * The list of servers, rendered identically wherever it is mounted.
 *
 * 🚨 This component is the "one widget core" of the four-host design. The
 * stock sidebar, fof/forum-widgets-core, Bespoke and Page Builder each mount
 * THIS, and an adapter contributes registration and placement chrome only.
 * Put host-specific behaviour in here and it has to be written four times and
 * will drift three ways.
 */
export default class ServerList extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.unsubscribe = null;
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    this.unsubscribe = subscribe(() => {});
  }

  onremove(vnode) {
    super.onremove(vnode);
    // Leaving the page must stop the polling, or a forum tab open overnight
    // keeps asking for ever.
    if (this.unsubscribe) this.unsubscribe();
  }

  view() {
    if (!isLoaded()) {
      return (
        <div className="GarrisonWidget">
          <LoadingIndicator display="inline" size="small" />
        </div>
      );
    }

    const servers = all();

    if (!servers.length) {
      return (
        <div className="GarrisonWidget GarrisonWidget--empty">
          {app.translator.trans(
            lastError()
              ? 'ernestdefoe-garrison.forum.unreachable'
              : 'ernestdefoe-garrison.forum.no_servers'
          )}
        </div>
      );
    }

    return (
      <div className="GarrisonWidget">
        <ul className="GarrisonWidget-list">
          {servers.map((s) => this.row(s))}
        </ul>
      </div>
    );
  }

  row(s) {
    // 🚨 Stale is its own signal, not a state. "Running, last heard from 40
    // minutes ago" is a completely different fact from "running", and the
    // whole product exists because the first one looked like the second for
    // twenty hours.
    const stale = s.stale || s.agentLate;

    return (
      <li className={'GarrisonServer' + (stale ? ' GarrisonServer--stale' : '')} key={s.id}>
        <span
          className={`GarrisonServer-state GarrisonServer-state--${s.state}`}
          aria-hidden="true"
        />
        <span className="GarrisonServer-name">{s.name}</span>
        <span className="GarrisonServer-players">{this.detail(s, stale)}</span>
      </li>
    );
  }

  detail(s, stale) {
    if (stale && s.lastStatusAt) {
      return app.translator.trans('ernestdefoe-garrison.forum.stale', {
        when: humanTime(s.lastStatusAt),
      });
    }

    if (s.state === 'running' && s.playersOnline !== null && s.playersOnline !== undefined) {
      if (s.playersMax) return `${s.playersOnline}/${s.playersMax}`;

      return app.translator.trans('ernestdefoe-garrison.forum.players', { count: s.playersOnline });
    }

    return app.translator.trans(`ernestdefoe-garrison.forum.state.${s.state}`);
  }
}
