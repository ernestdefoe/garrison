import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';

import { refresh } from '../store';

/**
 * "That player in the game is me."
 *
 * 🚨 PROVED IN THE GAME, NOT ASSERTED ON THE FORUM.
 *
 * A form that asks for an in-game name and believes the answer lets anybody
 * claim the community's best-known player and inherit their playtime, their
 * rank and whatever an operator built on top of that. So Garrison whispers a
 * code to that player INSIDE the game; only somebody holding that account can
 * read it, and they type it back here.
 *
 * The forum never composes the console line — it sends a name and six
 * characters, and the agent renders the operator's own template, only for a
 * player it can currently see in the game. That is what makes this safe to put
 * in front of ordinary members rather than staff.
 */
export default class LinkIdentity extends Component {
  oninit(vnode) {
    super.oninit(vnode);

    this.player = '';
    this.code = '';
    this.busy = false;
    this.error = null;
    this.sent = false;
  }

  view() {
    const s = this.attrs.server;

    // Guests have no account to link, and a server that cannot whisper cannot
    // complete the flow — offering a button that always fails is worse than
    // offering nothing.
    if (app.session.user === null || !s.canLink) return null;

    const identity = s.identity;

    return (
      <section className="GarrisonLink">
        <h3>{app.translator.trans('ernestdefoe-garrison.forum.link.title')}</h3>

        {this.error ? <p className="GarrisonLink-error">{this.error}</p> : null}

        {identity && identity.verified ? this.linked(identity) : this.flow(identity)}
      </section>
    );
  }

  linked(identity) {
    return (
      <div className="GarrisonLink-linked">
        <p>
          {app.translator.trans('ernestdefoe-garrison.forum.link.linked', {
            player: <strong>{identity.player}</strong>,
          })}
        </p>
        {Button.component(
          {
            className: 'Button Button--link',
            loading: this.busy,
            onclick: () => this.unlink(),
          },
          app.translator.trans('ernestdefoe-garrison.forum.link.unlink')
        )}
      </div>
    );
  }

  flow(identity) {
    const waiting = this.sent || (identity && identity.awaitingCode);

    return [
      <p className="GarrisonLink-help" key="help">
        {app.translator.trans(
          waiting
            ? 'ernestdefoe-garrison.forum.link.check_the_game'
            : 'ernestdefoe-garrison.forum.link.explain'
        )}
      </p>,

      waiting ? this.codeForm(identity) : this.nameForm(),
    ];
  }

  nameForm() {
    return (
      <form
        className="GarrisonLink-form"
        key="name"
        onsubmit={(e) => {
          e.preventDefault();
          this.claim();
        }}
      >
        <input
          className="FormControl"
          placeholder={app.translator.trans('ernestdefoe-garrison.forum.link.name_placeholder')}
          value={this.player}
          disabled={this.busy}
          oninput={(e) => {
            this.player = e.target.value;
          }}
        />
        {Button.component(
          {
            className: 'Button Button--primary',
            type: 'submit',
            loading: this.busy,
            disabled: !this.player.trim(),
          },
          app.translator.trans('ernestdefoe-garrison.forum.link.send_code')
        )}
      </form>
    );
  }

  codeForm(identity) {
    return (
      <form
        className="GarrisonLink-form"
        key="code"
        onsubmit={(e) => {
          e.preventDefault();
          this.confirm();
        }}
      >
        <input
          className="FormControl GarrisonLink-code"
          placeholder={app.translator.trans('ernestdefoe-garrison.forum.link.code_placeholder')}
          value={this.code}
          disabled={this.busy}
          /*
            🚨 Uppercased as they type, because the alphabet is uppercase and
            the code is being copied off a game screen. Rejecting `ab12cd` for
            case would be a support message about nothing.
          */
          oninput={(e) => {
            this.code = e.target.value.toUpperCase();
          }}
        />
        {Button.component(
          {
            className: 'Button Button--primary',
            type: 'submit',
            loading: this.busy,
            disabled: !this.code.trim(),
          },
          app.translator.trans('ernestdefoe-garrison.forum.link.confirm')
        )}
        {Button.component(
          {
            className: 'Button Button--link',
            onclick: () => {
              this.sent = false;
              this.code = '';
              this.error = null;
            },
          },
          app.translator.trans('ernestdefoe-garrison.forum.link.start_over')
        )}
      </form>
    );
  }

  claim() {
    this.busy = true;
    this.error = null;

    this.request('POST', '', { player: this.player.trim() })
      .then(() => {
        this.busy = false;
        this.sent = true;
        m.redraw();
      })
      .catch((e) => this.fail(e));
  }

  confirm() {
    this.busy = true;
    this.error = null;

    this.request('POST', '/confirm', { code: this.code.trim() })
      .then(() => {
        this.busy = false;
        this.sent = false;
        this.code = '';
        // The status payload carries the identity, so one refresh updates
        // every surface that shows it rather than this component alone.
        return refresh();
      })
      .catch((e) => this.fail(e));
  }

  unlink() {
    this.busy = true;
    this.error = null;

    this.request('DELETE', '')
      .then(() => {
        this.busy = false;
        this.player = '';
        this.sent = false;
        return refresh();
      })
      .catch((e) => this.fail(e));
  }

  request(method, suffix, body) {
    return app.request({
      method,
      url: app.forum.attribute('apiUrl') + `/garrison/servers/${this.attrs.server.id}/identity${suffix}`,
      body,
    });
  }

  /**
   * 🚨 Shows the SERVER's reason, not a generic failure.
   *
   * Every refusal in this flow is one somebody can act on — "you are not in
   * the game right now", "somebody has already proved that name", "that code
   * has expired" — and each points at a different next step. "Something went
   * wrong" would make all three look like a broken panel.
   */
  fail(e) {
    this.busy = false;

    const detail =
      e &&
      e.response &&
      Array.isArray(e.response.errors) &&
      e.response.errors[0] &&
      e.response.errors[0].detail;

    this.error = detail || app.translator.trans('ernestdefoe-garrison.forum.action_failed');
    m.redraw();
  }
}
