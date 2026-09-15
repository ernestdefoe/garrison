import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import LinkButton from 'flarum/common/components/LinkButton';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

import { duration } from '../format';

/**
 * Who plays this server most.
 *
 * 🚨 The names are people you can reply to, and that is the entire point. A
 * leaderboard of in-game names is something any game panel can show; one where
 * the names link to forum profiles — because those players proved who they are
 * — is the thing only a panel living inside a community can do.
 */
export default class Leaderboard extends Component {
  oninit(vnode) {
    super.oninit(vnode);

    this.rows = null;
    this.failed = false;
    this.load();
  }

  load() {
    app
      .request({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + `/garrison/servers/${this.attrs.server.id}/leaderboard`,
      })
      .then((res) => {
        this.rows = (res && res.data) || [];
        m.redraw();
      })
      .catch(() => {
        this.failed = true;
        m.redraw();
      });
  }

  view() {
    if (this.failed) return null;

    if (this.rows === null) {
      return (
        <section className="GarrisonBoard">
          <LoadingIndicator display="inline" size="small" />
        </section>
      );
    }

    // A server nobody has played yet gets nothing, not an empty table.
    if (this.rows.length === 0) return null;

    return (
      <section className="GarrisonBoard">
        <h3>{app.translator.trans('ernestdefoe-garrison.forum.board.title')}</h3>

        <ol className="GarrisonBoard-list">
          {this.rows.map((r) => (
            <li className={'GarrisonBoard-row' + (r.online ? ' is-online' : '')} key={r.player}>
              {/*
                🚨 A dot for somebody who is playing RIGHT NOW. It is the one
                piece of information on this list that changes anything: a name
                you could go and join, rather than a name that was here on
                Tuesday.
              */}
              {r.online ? (
                <span
                  className="GarrisonBoard-dot"
                  title={app.translator.trans('ernestdefoe-garrison.forum.board.online')}
                />
              ) : null}

              <span className="GarrisonBoard-who">
                {r.username
                  ? LinkButton.component(
                      { href: app.route('user', { username: r.username }), className: 'Button Button--link' },
                      r.displayName || r.username
                    )
                  : r.player}
              </span>

              {/*
                Where a forum account is linked, the in-game name goes beside
                it rather than being replaced — they are often different, and
                somebody scanning for the name they know in the game should
                find it.
              */}
              {r.username && r.displayName !== r.player ? (
                <span className="GarrisonBoard-alias">{r.player}</span>
              ) : null}

              <span className="GarrisonBoard-time">{duration(r.seconds)}</span>
            </li>
          ))}
        </ol>
      </section>
    );
  }
}
