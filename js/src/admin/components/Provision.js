import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';

import { extract } from '../text';

/**
 * Installing a new game server from the forum.
 *
 * 🚨 THE FORUM PICKS A TEMPLATE AND NAMES THE SERVER. THAT IS ALL IT SENDS.
 *
 * The install directory, the start command, the driver and the Steam app id all
 * come from a template in the agent's own config file on the game host. There
 * is deliberately no field on this form for a path or a command — this is the
 * one action that makes an agent write its own allowlist, and the only thing
 * keeping that safe is that nothing typed here can become either.
 *
 * A host with no templates shows nothing at all, which is the default: the
 * ability to add servers follows from an operator having written a config, not
 * from Garrison being installed.
 */
export default class Provision extends Component {
  oninit(vnode) {
    super.oninit(vnode);

    this.templates = null;
    this.loading = false;
    this.busy = false;
    this.error = null;
    this.started = null;

    this.template = '';
    this.id = '';
    this.name = '';
  }

  view() {
    const agent = this.attrs.agent;

    return (
      <div className="GarrisonProvision">
        {this.templates === null ? (
          Button.component(
            {
              className: 'Button Button--link',
              icon: 'fas fa-download',
              loading: this.loading,
              onclick: () => this.load(agent),
            },
            this.t('load')
          )
        ) : this.templates.length === 0 ? (
          <p className="GarrisonAdmin-meta">{this.t('none')}</p>
        ) : (
          this.form()
        )}

        {this.error ? <p className="GarrisonAdmin-error">{this.error}</p> : null}

        {/*
          🚨 Says it has STARTED, not that it is done. A Steam download can be
          twenty gigabytes; a panel that said "installed" the moment the request
          was accepted would have somebody trying to start a server that is two
          per cent downloaded.
        */}
        {this.started ? <p className="GarrisonAdmin-meta">{this.t('started', { id: this.started })}</p> : null}
      </div>
    );
  }

  form() {
    return (
      <div className="GarrisonProvision-form">
        <select
          className="FormControl"
          value={this.template}
          onchange={(e) => {
            this.template = e.target.value;
          }}
        >
          <option value="">{this.t('choose')}</option>
          {this.templates.map((t) => (
            <option value={t.id} key={t.id}>
              {t.label}
              {t.fromSteam ? ' — ' + extract(this.t('from_steam')) : ''}
            </option>
          ))}
        </select>

        <input
          className="FormControl"
          placeholder={this.t('id_placeholder')}
          value={this.id}
          /*
            🚨 Narrowed as they type, to the same shape the agent enforces.
            The agent refuses anything else outright — this only stops somebody
            typing a name for thirty seconds and then being told no.
          */
          oninput={(e) => {
            this.id = e.target.value.toLowerCase().replace(/[^a-z0-9_-]/g, '-');
          }}
        />

        <input
          className="FormControl"
          placeholder={this.t('name_placeholder')}
          value={this.name}
          oninput={(e) => {
            this.name = e.target.value;
          }}
        />

        {Button.component(
          {
            className: 'Button Button--primary',
            loading: this.busy,
            disabled: !this.template || !this.id,
            onclick: () => this.install(),
          },
          this.t('install')
        )}
      </div>
    );
  }

  t(key, params) {
    return app.translator.trans('ernestdefoe-garrison.admin.provision.' + key, params);
  }

  load(agent) {
    this.loading = true;
    this.error = null;

    this.command(agent, 'provision.templates', {})
      .then((c) => {
        this.loading = false;
        this.templates = (c.result && c.result.templates) || [];
        m.redraw();
      })
      .catch((e) => this.fail(e));
  }

  install() {
    this.busy = true;
    this.error = null;
    this.started = null;

    this.command(this.attrs.agent, 'provision.install', {
      template: this.template,
      id: this.id,
      name: this.name,
    })
      .then((c) => {
        this.busy = false;
        this.started = (c.result && c.result.server) || this.id;
        this.id = '';
        this.name = '';
        m.redraw();
      })
      .catch((e) => this.fail(e));
  }

  /**
   * 🚨 Queued against ANY server this agent already has.
   *
   * Provisioning is an agent-level action, but every command in this product
   * travels by server — which is right, because it is also how permission and
   * audit work. An agent with no servers at all cannot be provisioned to yet;
   * that is the one case this does not cover, and it is the case where an
   * operator is writing their first config by hand anyway.
   */
  command(agent, verb, params) {
    const server = (this.attrs.servers || []).find((s) => s.agentId === agent.id);

    if (!server) {
      return Promise.reject(new Error(extract(this.t('needs_a_server'))));
    }

    return app
      .request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + `/garrison/servers/${server.id}/command`,
        body: { verb, params },
      })
      .then((res) => this.await(res.data.id));
  }

  await(id, attempts = 30) {
    return new Promise((resolve, reject) => {
      const tick = () => {
        app
          .request({ method: 'GET', url: app.forum.attribute('apiUrl') + '/garrison/commands/' + id })
          .then((res) => {
            const c = (res && res.data) || {};

            if (c.status === 'done') return resolve(c);
            if (c.status === 'failed' || c.status === 'expired') {
              return reject(new Error(c.errorMessage || extract(this.t('failed'))));
            }

            if (--attempts <= 0) return reject(new Error(extract(this.t('no_answer'))));

            setTimeout(tick, 2000);
          })
          .catch(reject);
      };

      setTimeout(tick, 1500);
    });
  }

  fail(e) {
    this.loading = false;
    this.busy = false;
    this.error = e && e.message ? e.message : extract(this.t('failed'));
    m.redraw();
  }
}

