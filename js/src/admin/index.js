import app from 'flarum/admin/app';

import GarrisonPage from './GarrisonPage';

app.initializers.add('ernestdefoe-garrison', () => {
  app.extensionData
    .for('ernestdefoe-garrison')
    .registerPage(GarrisonPage)

    /*
     * 🚨 Permission labels say what each one ACTUALLY does. "View game
     * servers" would be a lie: a server marked public is visible to everyone
     * with no permission at all, and an admin who grants this expecting it to
     * control that will be confused for a long time.
     */
    .registerPermission(
      { icon: 'fas fa-eye', label: app.translator.trans('ernestdefoe-garrison.admin.permissions.view'), permission: 'garrison.view' },
      'view'
    )
    .registerPermission(
      { icon: 'fas fa-power-off', label: app.translator.trans('ernestdefoe-garrison.admin.permissions.control'), permission: 'garrison.control' },
      'moderate'
    )
    .registerPermission(
      { icon: 'fas fa-terminal', label: app.translator.trans('ernestdefoe-garrison.admin.permissions.console'), permission: 'garrison.console' },
      'moderate'
    )
    .registerPermission(
      { icon: 'fas fa-tower-observation', label: app.translator.trans('ernestdefoe-garrison.admin.permissions.manage'), permission: 'garrison.manage' },
      'moderate'
    );
});
