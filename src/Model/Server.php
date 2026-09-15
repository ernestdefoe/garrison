<?php

namespace ErnestDefoe\Garrison\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;

/**
 * The forum's cached view of one game server.
 */
class Server extends AbstractModel
{
    protected $table = 'garrison_servers';

    protected $casts = [
        'is_public' => 'bool',
        'running_since' => 'datetime',
        'last_status_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function agent()
    {
        return $this->belongsTo(GarrisonAgent::class, 'agent_id');
    }

    /**
     * 🚨 Whether $actor may see the address, password and join code.
     *
     * This one method is the whole gate, and it lives on the model precisely
     * so all four widget hosts and the status page ask the SAME question. A
     * gate re-implemented per surface is a gate that is open on one of them.
     */
    public function joinDetailsVisibleTo(?User $actor): bool
    {
        if ($actor === null) {
            return false;
        }

        if ($actor->hasPermission('garrison.manage')) {
            return true;
        }

        // No group set means staff only — the safe default. An operator who
        // wants it public has to say so, rather than discovering they did.
        if ($this->join_group_id === null) {
            return false;
        }

        return $actor->groups->contains('id', $this->join_group_id);
    }

    /**
     * Stale means the agent has not reported recently, so what is on screen is
     * a memory rather than a fact. Shown as such: a panel confidently
     * displaying a twenty-minute-old "running" is how an outage goes unnoticed
     * for twenty hours.
     */
    public function isStale(): bool
    {
        if ($this->last_status_at === null) {
            return true;
        }

        return $this->last_status_at->lt(now()->subSeconds(90));
    }
}
