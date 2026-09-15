import app from 'flarum/forum/app';
/*
 * 🚨 `flarum/common/Component`, NOT `flarum/common/components/Component`.
 *
 * Webpack maps every `flarum/*` import to a RUNTIME lookup rather than
 * resolving it, so a wrong path compiles perfectly and fails in the browser:
 * the module comes back undefined, `class Backups extends undefined` throws at
 * module-evaluation time, and Flarum's per-extension try/catch swallows it as
 * one line — "ernestdefoe-garrison failed to initialize".
 *
 * What that cost here: the sidebar widget, both routes, the notification
 * component and the store model all vanished from the forum at once, because
 * they are registered by the initializer that never finished. Nothing pointed
 * at this file; the only clue was a `No module found for` WARNING, several
 * screens up a console full of font preload noise.
 */
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import humanTime from 'flarum/common/helpers/humanTime';

import { bytes } from '../format';
import { awaitCommand, command } from '../store';

/**
 * The backups panel: what exists, and what can be done with it.
 *
 * 🚨 The agent has been able to create, list, restore, prune and delete
 * backups since the day it was written, with tests covering zip-slip,
 * traversal, safety copies and retention — and until this file there was no
 * way to see a single one of them from the forum. A capability with no reader
 * is the same shipping failure as a button with no handler; it just fails in
 * the other direction, and nobody files a bug about it because nothing looks
 * broken.
 */
export default class Backups extends Component {
  oninit(vnode) {
    super.oninit(vnode);

    this.busy = null;
    this.outcome = null;

    // Which half of a stop-then-restore is running, so the button can say so
    // rather than spinning silently through two round trips.
    this.step = null;

    // Which row is asking "are you sure?". Null means none.
    this.confirming = null;
  }

  view() {
    const s = this.attrs.server;
    const list = s.backups || [];

    return (
      <section className="GarrisonBackups">
        <div className="GarrisonBackups-head">
          <h3>{app.translator.trans('ernestdefoe-garrison.forum.backups.title')}</h3>
          {s.canManage
            ? Button.component(
                {
                  className: 'Button Button--primary',
                  icon: 'fas fa-box-archive',
                  loading: this.busy === 'create',
                  disabled: !!this.busy,
                  onclick: () => this.run('create', 'backup.create'),
                },
                app.translator.trans('ernestdefoe-garrison.forum.backups.create')
              )
            : null}
        </div>

        {/*
          🚨 Says WHICH half is running, because a stop-then-restore is two
          round trips through a long poll and can take most of a minute. A
          single spinner for that long reads as a hung page — and this is the
          one place in the product where somebody watching a spinner is
          wondering whether their world still exists.
        */}
        {this.step ? (
          <p className="GarrisonBackups-step">
            {app.translator.trans('ernestdefoe-garrison.forum.backups.step.' + this.step)}
          </p>
        ) : null}

        {this.outcome ? this.report() : null}

        {list.length === 0 ? (
          <p className="GarrisonBackups-empty">
            {app.translator.trans('ernestdefoe-garrison.forum.backups.none')}
          </p>
        ) : (
          <ul className="GarrisonBackups-list">{list.map((b) => this.row(s, b))}</ul>
        )}
      </section>
    );
  }

  row(s, b) {
    const confirming = this.confirming === b.id;

    return (
      <li className={'GarrisonBackups-item' + (b.safety ? ' GarrisonBackups-item--safety' : '')} key={b.id}>
        <div className="GarrisonBackups-when">
          {b.at ? humanTime(b.at) : b.id}

          {/*
            🚨 A safety copy is LABELLED, because it is the one an operator is
            most likely to want and least likely to recognise. It was taken
            automatically, seconds before somebody overwrote a world, and its
            name differs from an ordinary backup's by one word in the middle of
            a filename nobody reads. Restoring the wrong one here is the single
            most expensive mistake this product makes possible.
          */}
          {b.safety ? (
            <span className="GarrisonBackups-tag">
              {app.translator.trans('ernestdefoe-garrison.forum.backups.safety')}
            </span>
          ) : null}
        </div>

        <div className="GarrisonBackups-size">{bytes(b.size)}</div>

        {s.canManage ? (
          <div className="GarrisonBackups-actions">
            {confirming ? this.confirm(b) : this.offer(b)}
          </div>
        ) : null}
      </li>
    );
  }

  offer(b) {
    return [
      Button.component(
        {
          className: 'Button Button--link',
          disabled: !!this.busy,
          onclick: () => {
            this.confirming = b.id;
            this.pending = 'restore';
          },
        },
        app.translator.trans('ernestdefoe-garrison.forum.backups.restore')
      ),
      Button.component(
        {
          className: 'Button Button--link GarrisonBackups-delete',
          disabled: !!this.busy,
          onclick: () => {
            this.confirming = b.id;
            this.pending = 'delete';
          },
        },
        app.translator.trans('ernestdefoe-garrison.forum.backups.delete')
      ),
    ];
  }

  /**
   * 🚨 An inline confirmation, not window.confirm, and it says what will
   * happen in the same words the operator would use.
   *
   * A restore overwrites a live world with an old one. `confirm()` on a native
   * dialog is muscle memory — people dismiss it without reading — and it gives
   * no room to say the two things that actually matter: that the current state
   * is about to be replaced, and that a safety copy is taken first so this is
   * recoverable. The second sentence is the reason somebody can press the
   * button at all.
   */
  confirm(b) {
    const restoring = this.pending === 'restore';

    /*
     * 🚨 Restoring into a RUNNING server is refused by the agent, and the
     * panel has to say so BEFORE the click, not after.
     *
     * The agent's refusal is correct and not negotiable: writing a world file
     * under a live process corrupts it, and the corruption surfaces hours
     * later looking like a game bug. But a panel that lets somebody click
     * Restore, wait, and then reports "stop the server before restoring" has
     * told them the truth and left them stranded — they now have to work out
     * for themselves that the fix is to scroll up, stop it, wait, come back,
     * and start again. On the most consequential control in the product, at
     * the moment they are least patient.
     *
     * So when it is running, the confirmation says what has to happen and the
     * button does it: stop, wait for stopped, then restore.
     */
    const running = restoring && this.attrs.server.state === 'running';

    return (
      <div className="GarrisonBackups-confirm">
        <p>
          {app.translator.trans(
            restoring
              ? 'ernestdefoe-garrison.forum.backups.confirm_restore'
              : 'ernestdefoe-garrison.forum.backups.confirm_delete'
          )}

          {running ? (
            <span className="GarrisonBackups-stopNote">
              {app.translator.trans('ernestdefoe-garrison.forum.backups.confirm_restore_stops')}
            </span>
          ) : null}
        </p>
        {Button.component(
          {
            className: 'Button Button--primary',
            loading: this.busy === b.id,
            onclick: () =>
              restoring
                ? this.restore(b, running)
                : this.run(b.id, 'backup.delete', { id: b.id }),
          },
          app.translator.trans(
            restoring
              ? running
                ? 'ernestdefoe-garrison.forum.backups.confirm_restore_do_stopping'
                : 'ernestdefoe-garrison.forum.backups.confirm_restore_do'
              : 'ernestdefoe-garrison.forum.backups.confirm_delete_do'
          )
        )}
        {Button.component(
          {
            className: 'Button Button--link',
            onclick: () => {
              this.confirming = null;
            },
          },
          app.translator.trans('ernestdefoe-garrison.forum.backups.cancel')
        )}
      </div>
    );
  }

  /**
   * 🚨 Reports the AGENT's answer, not the fact that a request was accepted.
   *
   * See store.awaitCommand: queueing returns 202 and nothing else, so a panel
   * that celebrated there would announce a restored world at the moment
   * nothing had happened yet. Restoring is the one thing here that cannot be
   * undone, so it is the last place to be optimistic.
   */
  report() {
    const o = this.outcome;

    const tone = { done: 'ok', failed: 'bad' }[o.status] || 'muted';

    return (
      <div className={'GarrisonBackups-outcome GarrisonBackups-outcome--' + tone}>
        <strong>
          {app.translator.trans('ernestdefoe-garrison.forum.backups.outcome.' + o.status + '_' + o.what)}
        </strong>

        {/*
          The host's own words underneath — "server is running", "no such
          backup". A translated headline tells somebody what happened; only the
          agent can tell them why.
        */}
        {o.message ? <span className="GarrisonBackups-outcomeDetail">{o.message}</span> : null}
      </div>
    );
  }

  /**
   * Stop first if it is running, then restore.
   *
   * 🚨 And it does NOT start the server again afterwards.
   *
   * Every panel that does is guessing at what somebody wants next, and the
   * guess is wrong exactly when it matters: after a restore an operator often
   * wants to look at the files, check a config, or confirm the right world
   * came back before letting players in. Starting it for them takes that
   * moment away and puts a possibly-wrong world live. The Start control is
   * six inches up the page, and pressing it is a decision rather than a
   * side effect.
   */
  restore(b, stopFirst) {
    if (!stopFirst) {
      return this.run(b.id, 'backup.restore', { id: b.id });
    }

    this.busy = b.id;
    this.confirming = null;
    this.outcome = null;
    this.step = 'stopping';

    command(this.attrs.server.id, 'server.stop', {})
      .then((res) => awaitCommand(res.data.id))
      .then((c) => {
        /*
         * 🚨 A failed stop must NOT be followed by a restore attempt. The
         * agent would refuse it anyway — this is the second half of the same
         * guard — but queueing a doomed command would put a confusing second
         * failure in the audit log and report the wrong reason to whoever
         * reads it.
         */
        if (c.status !== 'done') {
          this.busy = null;
          this.step = null;
          this.outcome = { status: 'failed', what: 'stop', message: c.errorMessage || null };
          return m.redraw();
        }

        this.step = 'restoring';
        m.redraw();

        return this.run(b.id, 'backup.restore', { id: b.id });
      })
      .catch(() => {
        this.busy = null;
        this.step = null;
        this.outcome = { status: 'failed', what: 'stop', message: null };
        m.redraw();
      });
  }

  run(key, verb, params = {}) {
    this.busy = key;
    this.confirming = null;
    this.outcome = null;

    const what = verb.split('.')[1];

    command(this.attrs.server.id, verb, params)
      .then((res) => awaitCommand(res.data.id))
      .then((c) => {
        this.busy = null;
        this.step = null;
        this.outcome = {
          status: ['done', 'failed'].includes(c.status) ? c.status : 'waiting',
          what,
          message: c.errorMessage || null,
        };
        m.redraw();
      })
      .catch(() => {
        this.busy = null;
        this.step = null;
        this.outcome = { status: 'failed', what, message: null };
        m.redraw();
      });
  }
}

