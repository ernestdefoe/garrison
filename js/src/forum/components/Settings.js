import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

import { awaitCommand, command } from '../store';

/**
 * The server's own settings, as the operator chose to expose them.
 *
 * 🚨 THERE IS NO FILE BROWSER HERE, AND THAT IS THE FEATURE.
 *
 * The obvious version of this panel lists the server's folder and lets somebody
 * edit anything in it. On a game host that is arbitrary write access, and
 * arbitrary write access on a machine that runs a start script is arbitrary
 * code execution — reached from a PHP forum on the public internet running
 * third-party extension code. One stolen admin session would own the box.
 *
 * So the operator declares which files exist, in the agent's own config, and
 * optionally which keys within them. This panel can only ask for what is on
 * that list. A community can let a moderator change the message of the day
 * without that moderator being three lines away from the RCON password.
 *
 * 🚨 Loaded on demand, not with the status poll. A config file is read from
 * disk on the host and is of no interest until somebody opens it — shipping it
 * with every poll would read every declared file on every server every fifteen
 * seconds, for a panel nobody has opened.
 */
export default class Settings extends Component {
  oninit(vnode) {
    super.oninit(vnode);

    this.files = null;
    this.open = null;
    this.set = null;
    this.loading = false;
    this.saving = null;
    this.error = null;

    // Edited values, keyed by section and key, so a field keeps what somebody
    // typed across the redraws the status poll causes every fifteen seconds.
    this.drafts = {};
  }

  view() {
    return (
      <section className="GarrisonSettings">
        <h3>{app.translator.trans('ernestdefoe-garrison.forum.config.title')}</h3>

        {this.files === null ? this.opener() : this.chooser()}

        {this.error ? <p className="GarrisonSettings-error">{this.error}</p> : null}

        {this.loading ? <LoadingIndicator /> : this.set ? this.editor() : null}
      </section>
    );
  }

  /*
   * 🚨 One button rather than loading on mount. Opening a server's page should
   * not read files off a game host — most visits are to see whether it is up.
   */
  opener() {
    return Button.component(
      {
        className: 'Button',
        icon: 'fas fa-sliders',
        loading: this.loading,
        onclick: () => this.list(),
      },
      app.translator.trans('ernestdefoe-garrison.forum.config.load')
    );
  }

  chooser() {
    if (this.files.length === 0) {
      return (
        <p className="GarrisonSettings-empty">
          {app.translator.trans('ernestdefoe-garrison.forum.config.none')}
        </p>
      );
    }

    return (
      <div className="GarrisonSettings-files">
        {this.files.map((f) =>
          Button.component(
            {
              className: 'Button Button--link' + (this.open === f.id ? ' is-open' : ''),
              key: f.id,
              onclick: () => this.load(f.id),
            },
            f.label
          )
        )}
      </div>
    );
  }

  editor() {
    const entries = this.set.entries || [];

    if (entries.length === 0) {
      return (
        <p className="GarrisonSettings-empty">
          {app.translator.trans('ernestdefoe-garrison.forum.config.file_empty')}
        </p>
      );
    }

    return (
      <div className="GarrisonSettings-entries">
        {this.set.readOnly ? (
          <p className="GarrisonSettings-help">
            {app.translator.trans('ernestdefoe-garrison.forum.config.read_only')}
          </p>
        ) : null}

        {entries.map((e) => this.entry(e))}
      </div>
    );
  }

  entry(e) {
    const id = draftKey(e);
    const value = id in this.drafts ? this.drafts[id] : e.value;
    const dirty = id in this.drafts && this.drafts[id] !== e.value;

    return (
      <div className={'GarrisonSettings-entry' + (e.editable ? '' : ' is-locked')} key={id}>
        <label for={'garrison-cfg-' + id}>
          {e.section ? <span className="GarrisonSettings-section">{e.section}</span> : null}
          {e.key}
        </label>

        {/*
          🚨 The game's own comment, under the field it explains. It is the
          most useful thing on the screen — nobody remembers what
          `view-distance` costs — and it is already in the file, so not showing
          it would be throwing away documentation written for exactly this
          moment.
        */}
        {e.comment ? <span className="GarrisonSettings-help">{e.comment}</span> : null}

        <div className="GarrisonSettings-control">
          <input
            id={'garrison-cfg-' + id}
            className="FormControl"
            value={value}
            disabled={!e.editable || this.saving === id}
            oninput={(ev) => {
              this.drafts[id] = ev.target.value;
            }}
          />

          {/*
            🚨 An explicit Save per row, not save-on-blur.

            These values restart-or-break a game server. Blurring a field is
            something people do by accident — tabbing, clicking elsewhere,
            switching windows — and it must not be the gesture that writes a
            port number to a live host. The button also gives the save
            somewhere to report from.
          */}
          {e.editable
            ? Button.component(
                {
                  className: 'Button',
                  disabled: !dirty || this.saving !== null,
                  loading: this.saving === id,
                  onclick: () => this.save(e, value),
                },
                app.translator.trans('ernestdefoe-garrison.forum.config.save')
              )
            : null}
        </div>

        {!e.editable && !this.set.readOnly ? (
          <span className="GarrisonSettings-help">
            {app.translator.trans('ernestdefoe-garrison.forum.config.locked')}
          </span>
        ) : null}
      </div>
    );
  }

  list() {
    this.loading = true;
    this.error = null;

    this.run('config.list', {})
      .then((c) => {
        this.loading = false;
        this.files = (c.result && c.result.files) || [];
        m.redraw();
      })
      .catch((e) => this.fail(e));
  }

  load(id) {
    this.loading = true;
    this.open = id;
    this.set = null;
    this.drafts = {};
    this.error = null;

    this.run('config.get', { file: id })
      .then((c) => {
        this.loading = false;
        this.set = c.result || null;
        m.redraw();
      })
      .catch((e) => this.fail(e));
  }

  save(entry, value) {
    const id = draftKey(entry);

    this.saving = id;
    this.error = null;

    this.run('config.set', {
      file: this.open,
      section: entry.section || '',
      key: entry.key,
      value,
    })
      .then((c) => {
        this.saving = null;

        // 🚨 The file as the HOST now has it, not the value just sent. A panel
        // that echoed its own input would show a successful save of something
        // the file may not contain.
        this.set = c.result || this.set;
        delete this.drafts[id];
        m.redraw();
      })
      .catch((e) => {
        this.saving = null;
        this.fail(e);
      });
  }

  /**
   * Queue a command and wait for the agent's answer.
   *
   * 🚨 Rejects on a failed command, so every caller's catch is the one place a
   * refusal is reported — and the AGENT's own message is what gets shown:
   * "motd is not one of the settings that may be changed", "a setting cannot
   * contain a line break". Only the host knows which rule was hit.
   */
  run(verb, params) {
    return command(this.attrs.server.id, verb, params)
      .then((res) => awaitCommand(res.data.id))
      .then((c) => {
        if (c.status !== 'done') {
          throw new Error(
            c.errorMessage || app.translator.trans('ernestdefoe-garrison.forum.action_failed')
          );
        }

        return c;
      });
  }

  fail(e) {
    this.loading = false;
    this.error =
      e && e.message ? e.message : app.translator.trans('ernestdefoe-garrison.forum.action_failed');
    m.redraw();
  }
}

/**
 * A stable id for one setting.
 *
 * 🚨 The SECTION is part of it. An INI file can carry the same key in two
 * sections — `[Server] Name` and `[Admin] Name` — and keying drafts on the key
 * alone would make typing in one field change the other on screen, and then
 * save the wrong one.
 */
function draftKey(entry) {
  return JSON.stringify([entry.section || '', entry.key]);
}
