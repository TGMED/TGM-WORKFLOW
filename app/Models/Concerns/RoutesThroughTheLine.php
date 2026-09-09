<?php

namespace App\Models\Concerns;

use App\Models\Department;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request goes to the people responsible for the requester before it goes to
 * anybody else: their team lead first, then their head of department.
 *
 * The two are stamped on the row when the request is filed, not read live off
 * the requester. A reorganisation halfway through a request would otherwise
 * move it out from under whoever is already looking at it, and a decision
 * already taken would end up attributed to a chain nobody was ever in.
 *
 * A null pointer means that stage does not apply: somebody in no team has no
 * team lead to wait for, and a request filed before any of this existed has
 * neither. Both simply skip.
 */
trait RoutesThroughTheLine
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function teamLead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'team_lead_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_id');
    }

    /**
     * Stamp the chain from where the requester sits right now.
     *
     * Nobody is ever put in their own chain: a team lead's own request starts
     * at their head, and a head's starts at the wider approver pool. Leaving
     * them in it would mean a request that only they could unblock, by ruling
     * on themselves.
     */
    public function stampReportingLine(): void
    {
        $requester = $this->user ?? User::query()->find($this->user_id);

        if ($requester === null) {
            return;
        }

        $lead = $requester->team_id === null
            ? null
            : Team::query()->whereKey($requester->team_id)->value('lead_user_id');

        $head = $requester->department_id === null
            ? null
            : Department::query()->whereKey($requester->department_id)->value('head_user_id');

        $this->team_lead_id = $lead === $requester->id ? null : $lead;
        $this->head_id = $head === $requester->id ? null : $head;
    }

    /**
     * Whether the team lead has had their say. True when there is none, so the
     * stage falls away rather than blocking.
     */
    public function teamLeadDecided(): bool
    {
        return $this->team_lead_id === null
            || $this->currentDecisions()->contains('approver_id', $this->team_lead_id);
    }

    public function headDecided(): bool
    {
        return $this->head_id === null
            || $this->currentDecisions()->contains('approver_id', $this->head_id);
    }

    /**
     * Whether the reporting line is finished with this request, and it may go
     * on to whoever else the module asks for.
     */
    public function lineFinished(): bool
    {
        return $this->teamLeadDecided() && $this->headDecided();
    }

    /**
     * Whose turn it is within the line, or null when the line is done with it.
     */
    public function lineAwaiting(): ?int
    {
        if (! $this->teamLeadDecided()) {
            return $this->team_lead_id;
        }

        if (! $this->headDecided()) {
            return $this->head_id;
        }

        return null;
    }

    /**
     * The person the line is waiting on, for the copy on the request pages.
     */
    public function lineAwaitingUser(): ?User
    {
        $id = $this->lineAwaiting();

        if ($id === null) {
            return null;
        }

        return $id === $this->team_lead_id ? $this->teamLead : $this->head;
    }

    /**
     * How the current line stage reads to the requester.
     */
    public function lineStageLabel(): ?string
    {
        $waiting = $this->lineAwaitingUser();

        if ($waiting === null) {
            return null;
        }

        return $this->lineAwaiting() === $this->team_lead_id
            ? "With {$waiting->name}, your team lead"
            : "With {$waiting->name}, your head of department";
    }
}
