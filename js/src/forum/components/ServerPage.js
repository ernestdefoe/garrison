import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import LinkButton from 'flarum/common/components/LinkButton';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';

import Backups from './Backups';
import Console from './Console';
import Leaderboard from './Leaderboard';
import LinkIdentity from './LinkIdentity';
import ServerControls from './ServerControls';
import Settings from './Settings';
import { bytes } from '../format';
import { serverMark } from '../marks';
import { byId, isLoaded, lastError, subscribe } from '../store';

/**
 * One server, in full, at a URL of its own.
 *
 * 🚨 A URL of its own is the point, not a nicer layout.
 *
 * Everything on this page could be squeezed onto a card, and some of it is.
 * What a card cannot do is be linked to. An alert that says "Shattered Pact
 * stopped accepting players" and links to a list of eleven servers has handed
 * its reader a search task at the moment they are least able to do one; the
 * email that arrives at 3am has to open the thing it is about. The same goes
 * for the link somebody pastes into a staff channel.
 *
 * It also has room for the two things a card genuinely cannot carry: the
 * backups panel — which until now had no reader at all, despite a fully
 * tested engine behind it — and a console with enough height to read.
 */
export default class ServerPage extends Page {
  oninit(vnode) {
    super.oninit(vnode);

    this.unsubscribe = null;
    this.id = m.route.param('id');
  }

  oncreate(vnode) {
    super.oncreate(vnode);
    this.unsubscribe = subscribe(() => {});
  }

  onremove(vnode) {
    super.onremove(vnode);
    if (this.unsubscribe) this.unsubscribe();
  }

  view() {
    return (
      <div className="GarrisonPage GarrisonServerPage IndexPage">
        <div className="container">
          <div className="sideNavContainer">
            <div className="IndexPage-results sideNavOffset">{this.body()}</div>
          </div>
        </div>
      </div>
    );
  }

  body() {
    if (!isLoaded()) return <LoadingIndicator />;

    const s = byId(this.id);

    /*
     * 🚨 "Not here" rather than "does not exist", and the same message whether
     * the server was deleted, belongs to an unpaired host, or is simply not
     * visible to this person.
     *
     * Distinguishing them would turn this page into a way to enumerate
     * private servers: a member could try ids and read the difference between
     * "no such server" and "not for you". The back-link matters more than the
     * wording — somebody who followed a stale link needs a way onwards.
     */
    if (!s) {
      return (
        <div className="GarrisonServerPage-missing">
          <p>
            {app.translator.trans(
              lastError()
                ? 'ernestdefoe-garrison.forum.unreachable'
                : 'ernestdefoe-garrison.forum.server_not_here'
            )}
          </p>
          {LinkButton.component(
            { href: app.route('garrison'), className: 'Button' },
            app.translator.trans('ernestdefoe-garrison.forum.back_to_all')
          )}
        </div>
      );
    }

    app.setTitle(s.name);

    const stale = s.stale || s.agentLate;
    const running = s.state === 'running';

    return [
      <nav className="GarrisonServerPage-back" key="back">
        {LinkButton.component(
          { href: app.route('garrison'), className: 'Button Button--link', icon: 'fas fa-chevron-left' },
          app.translator.trans('ernestdefoe-garrison.forum.back_to_all')
        )}
      </nav>,

      <header className={'GarrisonServerPage-head' + (stale ? ' GarrisonServerPage-head--stale' : '')} key="head">
        <span className="GarrisonServerPage-mark">{serverMark(s, 48)}</span>

        <div className="GarrisonServerPage-identity">
          <h1 className="GarrisonServerPage-name">{s.name}</h1>
          <div className="GarrisonServerPage-state">
            <span className={`GarrisonServer-state GarrisonServer-state--${s.state}`} aria-hidden="true" />
            {app.translator.trans(`ernestdefoe-garrison.forum.state.${s.state}`)}
            {stale ? (
              <span className="GarrisonServerPage-staleNote">
                {app.translator.trans('ernestdefoe-garrison.forum.stale', {
                  when: s.lastStatusAt ? humanTime(s.lastStatusAt) : '—',
                })}
              </span>
            ) : null}
          </div>
        </div>

        <ServerControls server={s} />
      </header>,

      this.health(s, running),
      this.players(s, running),
      this.join(s),
      this.facts(s, running),

      /*
       * 🚨 Above the staff panels, because this is the only thing on this page
       * an ordinary member can do. Burying it under the console and the backups
       * — neither of which most visitors can even see — would put the one
       * control aimed at them at the bottom of a page of controls that are not.
       */
      /*
       * 🚨 The leaderboard sits with the players, above the staff controls.
       * It is the part of this page a community reads; the console and the
       * backups are the part an operator works from, and most visitors cannot
       * see them at all.
       */
      <Leaderboard server={s} key="board" />,

      <LinkIdentity server={s} key="link" />,

      s.canConsole ? <Console server={s} tall={true} key="console" /> : null,

      /*
       * 🚨 Backups sit BELOW the console, not above it.
       *
       * The order is the order somebody works in. Arriving here after an
       * alert, the questions are "what is it doing", then "what does the log
       * say", and only then "do I need to put yesterday's world back". Putting
       * a Restore button above the evidence invites somebody to use it before
       * they have read anything — and it is the one control here that cannot
       * be taken back.
       */
      /*
       * 🚨 Shown when the API SENT a backup list, not when a flag says it
       * should be. ListServersController omits the field entirely for anybody
       * below staff, so its presence is the permission answer, already made
       * server-side. A second client-side rule here would be a second place
       * for the two to disagree — and the one that loses is always the one
       * that hides things.
       */
      s.backups !== undefined ? <Backups server={s} key="backups" /> : null,

      /*
       * 🚨 Settings sit at the BOTTOM, below the backups.
       *
       * The order is the order somebody works in, and it is also a safety
       * ordering: by the time an operator reaches the settings they have seen
       * the state, read the console and been shown that a backup exists. A
       * config editor placed above all that invites changing a port number
       * before anybody has looked at why the server is unhappy.
       */
      s.canConfig ? <Settings server={s} key="settings" /> : null,

      /*
       * 🚨 `.filter(Boolean)`, AND IT IS LOAD-BEARING.
       *
       * Mithril refuses a fragment whose children are part keyed and part not,
       * and — this is the subtle half — a `null` COUNTS AS UNKEYED. Its check
       * is literally `(child != null && child.key != null) !== isKeyed`, so one
       * null beside keyed siblings throws
       *
       *     In fragments, vnodes must either all have keys or none have keys.
       *
       * The throw happens inside the renderer, so the whole page body renders
       * as nothing while the wrapper div is still there — an empty page with an
       * error that names Mithril and no file of ours.
       *
       * Every conditional above yields null: a healthy server has no health
       * block, a member cannot see the console, a server with no join details
       * has no join block. So this page rendered perfectly against an unhealthy
       * server with every panel present and went blank the moment it was
       * pointed at a healthy one — which is the first thing a customer would
       * have done.
       */
    ].filter(Boolean);
  }

  /**
   * The same three verdicts the card shows, and for the same reasons — see
   * ServersPage.health. Repeated rather than shared because the two differ in
   * emphasis: here there is room to show every failing probe without burying
   * anything, and no need to choose between the summary and the detail.
   */
  health(s, running) {
    if (s.needsAttention) {
      return (
        <div className="GarrisonHealth GarrisonHealth--attention" key="health">
          <strong>{app.translator.trans('ernestdefoe-garrison.forum.health.attention')}</strong>
          <span>{app.translator.trans('ernestdefoe-garrison.forum.health.attention_detail')}</span>
          {this.checks(s)}
        </div>
      );
    }

    if (running && s.health === 'unready') {
      return (
        <div className="GarrisonHealth GarrisonHealth--unready" key="health">
          <strong>{app.translator.trans('ernestdefoe-garrison.forum.health.unready')}</strong>
          {s.healthSummary ? <span>{s.healthSummary}</span> : null}
          {this.checks(s)}
        </div>
      );
    }

    if (running && (s.health === 'unknown' || !s.health)) {
      return (
        <p className="GarrisonHealth GarrisonHealth--unknown" key="health">
          {app.translator.trans('ernestdefoe-garrison.forum.health.unchecked')}
        </p>
      );
    }

    return null;
  }

  checks(s) {
    if (!s.healthChecks || !s.healthChecks.length) return null;

    return (
      <ul className="GarrisonHealth-checks">
        {s.healthChecks.map((c, i) => (
          <li key={i}>
            <span className="GarrisonHealth-checkName">{c.name}</span>
            {c.detail ? <span className="GarrisonHealth-checkDetail">{c.detail}</span> : null}
          </li>
        ))}
      </ul>
    );
  }

  /**
   * Who is in the game right now, by name.
   *
   * 🚨 The thing a forum can do that a standalone panel cannot. The people in
   * this list are often the people reading the page, and "alice, bob and two
   * others are on right now" is a reason to go and join them — which is the
   * whole argument for a game panel living inside a community rather than
   * beside one.
   *
   * 🚨 Rendered only when the server actually reports names. A server whose
   * operator has not configured that gets nothing here, NOT an empty list: "no
   * players online" for a busy server is worse than saying nothing at all.
   */
  players(s, running) {
    if (!running || !s.playersNames) return null;

    if (s.playersNames.length === 0) {
      return (
        <p className="GarrisonPlayers GarrisonPlayers--empty" key="players">
          {app.translator.trans('ernestdefoe-garrison.forum.playing.nobody')}
        </p>
      );
    }

    return (
      <div className="GarrisonPlayers" key="players">
        <h3>
          {app.translator.trans('ernestdefoe-garrison.forum.playing.title', {
            count: s.playersNames.length,
          })}
        </h3>
        <ul className="GarrisonPlayers-list">
          {s.playersNames.map((name) => (
            <li className="GarrisonPlayers-player" key={name}>
              {name}
            </li>
          ))}
        </ul>
      </div>
    );
  }

  join(s) {
    if (!s.joinAddress && !s.joinCode && !s.joinPassword) return null;

    return (
      <div className="GarrisonServerPage-join" key="join">
        <h3>{app.translator.trans('ernestdefoe-garrison.forum.join')}</h3>
        <dl>
          {s.joinAddress ? this.pair('join_address', <code>{s.joinAddress}</code>) : null}
          {s.joinPassword ? this.pair('join_password', <code>{s.joinPassword}</code>) : null}
          {s.joinCode ? this.pair('join_code_label', <code>{s.joinCode}</code>) : null}
        </dl>
      </div>
    );
  }

  /**
   * 🚨 Every fact omitted entirely when unknown, and CPU/memory omitted while
   * stopped — the card's rule, for the card's reason. The last sample taken
   * from a server that is now down is a memory, and rendering "0% / 3.4 MiB"
   * states something untrue with the same confidence as everything beside it.
   */
  facts(s, running) {
    return (
      <dl className="GarrisonServerPage-facts" key="facts">
        {this.fact('players', running && s.playersOnline != null ? (s.playersMax ? `${s.playersOnline}/${s.playersMax}` : String(s.playersOnline)) : null)}
        {this.fact('cpu', running && s.cpuPercent != null ? `${s.cpuPercent}%` : null)}
        {this.fact('memory', running && s.memoryBytes != null ? bytes(s.memoryBytes) + (s.statsApproximate ? ' ≈' : '') : null)}
        {this.fact('uptime', running && s.runningSince ? humanTime(s.runningSince) : null)}
        {this.fact('driver', s.driver)}
        {this.fact('game', s.game)}
      </dl>
    );
  }

  fact(key, value) {
    if (value === null || value === undefined || value === '') return null;

    return [
      <dt key={key + '-t'}>{app.translator.trans(`ernestdefoe-garrison.forum.fact.${key}`)}</dt>,
      <dd key={key + '-d'}>{value}</dd>,
    ];
  }

  pair(key, value) {
    return [
      <dt key={key + '-t'}>{app.translator.trans(`ernestdefoe-garrison.forum.${key}`)}</dt>,
      <dd key={key + '-d'}>{value}</dd>,
    ];
  }
}
