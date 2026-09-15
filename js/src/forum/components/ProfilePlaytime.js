import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import LinkButton from 'flarum/common/components/LinkButton';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

import { duration } from '../format';

/**
 * Somebody's characters and what they have played, on their profile.
 *
 * 🚨 Fetched when the profile opens, NOT carried on the user resource.
 *
 * Hanging playtime off Flarum's user serializer is the obvious move and the
 * expensive one: every user in every payload would carry it, and a discussion
 * page serializes dozens — a query per rendered item, which is the shape that
 * once exhausted a database connection cap and took a whole forum down. A
 * profile is opened one at a time.
 */
export default class ProfilePlaytime extends Component {
  oninit(vnode) {
    super.oninit(vnode);

    this.rows = null;
    this.failed = false;

    app
      .request({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + `/garrison/users/${this.attrs.user.id()}/playtime`,
      })
      .then((res) => {
        this.rows = (res && res.data) || [];
        m.redraw();
      })
      .catch(() => {
        // 🚨 Silent. This is one optional block on somebody else's profile —
        // a red error where a game character would be tells the reader nothing
        // they can act on and makes the whole page look broken.
        this.failed = true;
        m.redraw();
      });
  }

  view() {
    if (this.failed) return null;

    if (this.rows === null) {
      return (
        <div className="GarrisonProfile">
          <LoadingIndicator display="inline" size="small" />
        </div>
      );
    }

    // Nothing linked is the common case for most members, and it gets nothing
    // rather than an empty heading.
    if (this.rows.length === 0) return null;

    return (
      <div className="GarrisonProfile">
        <h3 className="GarrisonProfile-title">
          {app.translator.trans('ernestdefoe-garrison.forum.profile.title')}
        </h3>

        <ul className="GarrisonProfile-list">
          {this.rows.map((r) => (
            <li className="GarrisonProfile-row" key={r.serverId}>
              <span className="GarrisonProfile-player">{r.player}</span>

              <span className="GarrisonProfile-server">
                {LinkButton.component(
                  { href: app.route('garrison.server', { id: r.serverId }), className: 'Button Button--link' },
                  r.serverName
                )}
              </span>

              {/*
                🚨 Omitted entirely below a minute rather than shown as "0
                minutes". Somebody who linked their account today has played no
                measured time yet, and a zero next to their name reads as a
                judgement rather than an absence of data.
              */}
              {r.seconds >= 60 ? (
                <span className="GarrisonProfile-time">{duration(r.seconds)}</span>
              ) : null}
            </li>
          ))}
        </ul>
      </div>
    );
  }
}
