import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';

import LinkButton from 'flarum/common/components/LinkButton';

import ServerList from './components/ServerList';
import ServersPage from './components/ServersPage';
import registerWidgetHosts from './hosts';

/**
 * 🚨 Every host is optional. Garrison must render with none of the four widget
 * frameworks installed, and must not double up when several are.
 */
app.initializers.add('ernestdefoe-garrison', () => {
  app.routes.garrison = { path: '/garrison', component: ServersPage };

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

          {/*
            The heading links to the full page, matching how Calendar's own
            sidebar item behaves on this forum. A widget that shows three
            servers and offers no way to the rest is a dead end.
          */}
          <a className="GarrisonSidebar-more" href={app.route('garrison')} config={m.route.link}>
            {app.translator.trans('ernestdefoe-garrison.forum.see_all')}
          </a>
        </h4>
        <ServerList />
      </div>,
      -10
    );
  });
});
