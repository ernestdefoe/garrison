<?php

namespace ErnestDefoe\Garrison;

use ErnestDefoe\Garrison\Agent\Dispatcher;
use ErnestDefoe\Garrison\Agent\Gateway;
use ErnestDefoe\Garrison\Agent\TokenGuard;
use ErnestDefoe\Garrison\Health\Ladder;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * 🚨 Every collaborator is REQUIRED, never optional.
 *
 * A constructor taking `?Foo $foo = null` is indistinguishable, at runtime,
 * between "nobody supplies it" and "it is not needed" — and a safety net that
 * is silently absent is worse than one that was never built, because the UI
 * still shows it. Millwright's automatic rollback was disconnected on every
 * install in the world for exactly this reason while its unit test passed.
 * Required dependencies turn the same slip into a TypeError at boot, where
 * somebody sees it.
 */
class GarrisonServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(TokenGuard::class, function () {
            return new TokenGuard();
        });

        $this->container->singleton(Gateway::class, function ($container) {
            return new Gateway($container->make(ConnectionInterface::class));
        });

        $this->container->singleton(Dispatcher::class, function ($container) {
            return new Dispatcher($container->make(TranslatorInterface::class));
        });

        $this->container->singleton(Ladder::class, function ($container) {
            return new Ladder($container->make(Dispatcher::class));
        });
    }
}
