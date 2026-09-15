import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';

import { extract } from '../text';

/**
 * Scheduled work for one server: restarts, backups, and lines sent to the game
 * console, each with an optional advance warning to players.
 *
 * 🚨 DAY TOGGLES AND A CLOCK, NOT A CRON FIELD.
 *
 * A cron expression is strictly more powerful and it is the wrong control for
 * this audience. The operator most likely to want a nightly restart is the one
 * least likely to write `0 5 * * *` correctly — and a cron field that is
 * subtly wrong does not error, it just never fires, while sitting in the panel
 * looking configured. Seven toggles and a time cannot be misread.
 */
export default class Schedules extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.busy = false;
  }

  view() {
    const server = this.attrs.server;
    const rows = this.attrs.schedules.filter((s) => s.serverId === server.id);

    return (
      <div className="GarrisonSchedules">
        <div className="GarrisonSchedules-head">
          <h4>{this.t('title')}</h4>
          {Button.component(
            {
              className: 'Button Button--link',
              icon: 'fas fa-plus',
              loading: this.busy,
              onclick: () => this.add(server),
            },
            this.t('add')
          )}
        </div>

        {rows.length === 0 ? (
          <p className="GarrisonAdmin-meta">{this.t('none')}</p>
        ) : (
          rows.map((s) => this.row(s))
        )}
      </div>
    );
  }

  row(s) {
    return (
      <div className={'GarrisonSchedule' + (s.enabled ? '' : ' GarrisonSchedule--off')} key={s.id}>
        <div className="GarrisonSchedule-top">
          <select
            className="FormControl GarrisonSchedule-kind"
            value={s.kind}
            onchange={(e) => this.save(s, { kind: e.target.value })}
          >
            {['restart', 'backup', 'console'].map((k) => (
              <option value={k} key={k}>
                {this.t('kind.' + k)}
              </option>
            ))}
          </select>

          {/*
            🚨 A real <input type="time">, not two number boxes. It is the
            control every operating system already taught this person to use,
            it handles 12/24-hour display in their locale for free, and it
            cannot produce 25:61.
          */}
          <input
            className="FormControl GarrisonSchedule-time"
            type="time"
            value={toClock(s.atMinute)}
            onchange={(e) => this.save(s, { atMinute: fromClock(e.target.value) })}
          />

          <select
            className="FormControl GarrisonSchedule-tz"
            value={s.timezone}
            onchange={(e) => this.save(s, { timezone: e.target.value })}
          >
            {zones().map((z) => (
              <option value={z} key={z}>
                {z}
              </option>
            ))}
          </select>

          <div className="GarrisonSchedule-spacer" />

          {Switch.component({
            state: s.enabled,
            onchange: (v) => this.save(s, { enabled: v }),
          })}

          {Button.component({
            className: 'Button Button--link GarrisonSchedule-remove',
            icon: 'fas fa-trash',
            title: this.t('remove'),
            onclick: () => this.remove(s),
          })}
        </div>

        <div className="GarrisonSchedule-days">
          {DAYS.map((label, i) => (
            <button
              type="button"
              key={i}
              className={'GarrisonSchedule-day' + (s.days[i] === '1' ? ' is-on' : '')}
              onclick={() => this.save(s, { days: toggleDay(s.days, i) })}
            >
              {app.translator.trans('ernestdefoe-garrison.admin.schedules.day.' + label)}
            </button>
          ))}
        </div>

        {s.kind === 'console' ? (
          <div className="GarrisonAdmin-field">
            <label for={`garrison-sched-${s.id}-line`}>{this.t('line')}</label>
            <input
              id={`garrison-sched-${s.id}-line`}
              className="FormControl"
              placeholder={this.t('line_placeholder')}
              value={s.payload || ''}
              oninput={(e) => { s.payload = e.target.value; }}
              onblur={() => this.save(s, { payload: s.payload })}
            />
          </div>
        ) : null}

        {/*
          🚨 The warning is what separates a restart from an outage. Players
          who are told finish what they are doing and log out; players who are
          not lose whatever they were in the middle of, and blame the server.
          Offered on every kind, because a scheduled backup can stutter a
          server too.
        */}
        <div className="GarrisonSchedule-warn">
          <div className="GarrisonAdmin-field">
            <label for={`garrison-sched-${s.id}-warn`}>{this.t('warn')}</label>
            <input
              id={`garrison-sched-${s.id}-warn`}
              className="FormControl GarrisonSchedule-warnMinutes"
              type="number"
              min="0"
              max="240"
              value={s.warnMinutes}
              oninput={(e) => { s.warnMinutes = e.target.value; }}
              onblur={() => this.save(s, { warnMinutes: s.warnMinutes })}
            />
          </div>

          {Number(s.warnMinutes) > 0 ? (
            <div className="GarrisonAdmin-field">
              <label for={`garrison-sched-${s.id}-warnline`}>{this.t('warn_line')}</label>
              <input
                id={`garrison-sched-${s.id}-warnline`}
                className="FormControl"
                placeholder={this.t('warn_line_placeholder')}
                value={s.warnPayload || ''}
                oninput={(e) => { s.warnPayload = e.target.value; }}
                onblur={() => this.save(s, { warnPayload: s.warnPayload })}
              />
            </div>
          ) : null}
        </div>

        {/*
          🚨 Says in words what the row will do, under the controls that set
          it. Seven toggles, a clock and a timezone are individually obvious
          and collectively easy to misread — "Every day at 05:00 (Europe/London)"
          is the sentence an operator can check against what they meant.
        */}
        <p className="GarrisonSchedule-summary">{this.summary(s)}</p>
      </div>
    );
  }

  summary(s) {
    const every = s.days === '1111111';
    const weekdays = s.days === '1111100';

    const when = every
      ? this.t('when.every_day')
      : weekdays
        ? this.t('when.weekdays')
        : DAYS.filter((_, i) => s.days[i] === '1')
            .map((d) => app.translator.trans('ernestdefoe-garrison.admin.schedules.day.' + d))
            .join(', ');

    return app.translator.trans('ernestdefoe-garrison.admin.schedules.summary', {
      what: this.t('kind.' + s.kind),
      when,
      time: toClock(s.atMinute),
      zone: s.timezone,
    });
  }

  t(key, params) {
    return app.translator.trans('ernestdefoe-garrison.admin.schedules.' + key, params);
  }

  add(server) {
    this.busy = true;

    app
      .request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + `/garrison/admin/servers/${server.id}/schedules`,
      })
      .then(() => {
        this.busy = false;
        return this.attrs.onchange();
      })
      .catch(() => {
        this.busy = false;
        m.redraw();
      });
  }

  save(s, changes) {
    // Optimistic, so a day toggle feels instant. The reload that follows is
    // what makes it true — and if the server refused a value, the reload is
    // what puts the real one back on screen rather than leaving a lie there.
    Object.assign(s, changes);
    m.redraw();

    return app
      .request({
        method: 'PATCH',
        url: app.forum.attribute('apiUrl') + `/garrison/admin/schedules/${s.id}`,
        body: changes,
      })
      .then(() => this.attrs.onchange())
      .catch(() => this.attrs.onchange());
  }

  remove(s) {
    if (!confirm(extract(this.t('remove_confirm')))) return;

    app
      .request({
        method: 'DELETE',
        url: app.forum.attribute('apiUrl') + `/garrison/admin/schedules/${s.id}`,
      })
      .then(() => this.attrs.onchange());
  }
}

const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

function toggleDay(mask, i) {
  const chars = mask.split('');
  chars[i] = chars[i] === '1' ? '0' : '1';

  const next = chars.join('');

  // 🚨 Never all-off. A mask of zeroes is a schedule that cannot fire, which
  // is what the enable switch is for — and the server refuses it anyway, so
  // allowing the click here would make the UI snap back with no explanation.
  return next.includes('1') ? next : mask;
}

function toClock(minute) {
  const h = String(Math.floor(minute / 60)).padStart(2, '0');
  const m = String(minute % 60).padStart(2, '0');

  return `${h}:${m}`;
}

function fromClock(value) {
  const [h, m] = String(value || '00:00').split(':').map(Number);

  return (h || 0) * 60 + (m || 0);
}

/**
 * 🚨 The browser's own zone first, then a short list of common ones.
 *
 * A full IANA list is six hundred entries and a scroll nobody finishes; a
 * hardcoded short list without the operator's own zone is worse, because the
 * one they want is the one that is missing. `Intl` knows where this admin is
 * sitting, and that is almost always the answer.
 */
let zoneCache = null;

function zones() {
  if (zoneCache) return zoneCache;

  const local = Intl.DateTimeFormat().resolvedOptions().timeZone;

  const common = [
    'UTC',
    'Europe/London',
    'Europe/Berlin',
    'Europe/Moscow',
    'America/New_York',
    'America/Chicago',
    'America/Denver',
    'America/Los_Angeles',
    'America/Sao_Paulo',
    'Asia/Dubai',
    'Asia/Kolkata',
    'Asia/Singapore',
    'Asia/Tokyo',
    'Australia/Sydney',
  ];

  zoneCache = [local, ...common.filter((z) => z !== local)].filter(Boolean);

  return zoneCache;
}

