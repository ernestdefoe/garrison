import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';

import ServerList from './components/ServerList';
import registerWidgetHosts from './hosts';

/**
 * 🚨 Every host is optional. Garrison must render with none of the four widget
 * frameworks installed, and must not double up when several are.
 */
app.initializers.add('ernestdefoe-garrison', () => {
  registerWidgetHosts(app, () => <ServerList />);

  /*
   * The stock Flarum sidebar — the floor, and the only one that needs nothing
   * else installed.
   *
   * 🚨 IndexSidebar, NOT IndexPage. The nav moved in Flarum 2 and extending
   * IndexPage.navItems silently adds nothing at all — no error, no warning,
   * just an absent link. That one cost a long "I can't find it" chase on
   * another extension.
   */
  extend('flarum/forum/components/IndexSidebar', 'items', function (items) {
    // Only the stock sidebar is skipped when a widget framework is managing
    // placement, or the same list appears twice on the same page.
    if (window.__garrisonHostedElsewhere) return;

    items.add(
      'garrison',
      <div className="GarrisonSidebar">
        <h4 className="GarrisonSidebar-title">
          {app.translator.trans('ernestdefoe-garrison.forum.title')}
        </h4>
        <ServerList />
      </div>,
      -10
    );
  });
});
