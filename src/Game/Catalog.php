<?php

namespace ErnestDefoe\Garrison\Game;

/**
 * Known games, and where their real artwork lives.
 *
 * 🚨 Garrison ships NO logo files, and this class is why it does not need to.
 *
 * Bundling Minecraft's or Valheim's logo inside a commercial extension is
 * redistributing somebody else's trade mark — the risk lands on the listing
 * and on every forum that installs it. But a forum fetching the game's own
 * artwork for its own page is the ordinary thing every game panel does, and it
 * is the operator's site making that call.
 *
 * So: one click fetches the real logo from the game's own store page and
 * stores it LOCALLY. Not a hotlink — a hotlinked image is somebody else's
 * server deciding when your forum breaks, and it rots quietly months later.
 */
class Catalog
{
    /**
     * game key => [steam app id, human name].
     *
     * Steam is the source because a dedicated-server game almost always has a
     * store page, and the CDN path is stable and public. A game that is not on
     * Steam simply has no app id here and falls back to the upload button.
     */
    public const GAMES = [
        'valheim' => [892970, 'Valheim'],
        'minecraft' => [null, 'Minecraft'],
        'minecraft-bedrock' => [null, 'Minecraft: Bedrock Edition'],
        'palworld' => [1623730, 'Palworld'],
        'rust' => [252490, 'Rust'],
        'ark' => [2399830, 'ARK: Survival Ascended'],
        'cs2' => [730, 'Counter-Strike 2'],
        'satisfactory' => [526870, 'Satisfactory'],
        'factorio' => [427520, 'Factorio'],
        '7dtd' => [251570, '7 Days to Die'],
        'projectzomboid' => [108600, 'Project Zomboid'],
        'terraria' => [105600, 'Terraria'],
        'enshrouded' => [1203620, 'Enshrouded'],
        'vrising' => [1604030, 'V Rising'],
        'dayz' => [221100, 'DayZ'],
        'gmod' => [4000, "Garry's Mod"],
    ];

    public static function name(?string $game): ?string
    {
        if ($game === null) {
            return null;
        }

        return self::GAMES[strtolower($game)][1] ?? null;
    }

    public static function steamAppId(?string $game): ?int
    {
        if ($game === null) {
            return null;
        }

        return self::GAMES[strtolower($game)][0] ?? null;
    }

    /**
     * Candidate artwork URLs for a game, best first.
     *
     * 🚨 Several candidates because Steam does not guarantee every asset for
     * every app. `logo.png` is a transparent wordmark and much the nicest in a
     * list; `header.jpg` always exists but is a wide banner; `capsule` sits in
     * between. Trying in order means a game with no logo still gets something
     * rather than an error the operator has to interpret.
     *
     * @return array<int, string>
     */
    public static function artworkCandidates(?string $game): array
    {
        $appId = self::steamAppId($game);

        if ($appId === null) {
            return [];
        }

        $base = 'https://cdn.cloudflare.steamstatic.com/steam/apps/' . $appId . '/';

        return [
            $base . 'logo.png',
            $base . 'capsule_231x87.jpg',
            $base . 'header.jpg',
        ];
    }
}
