<?php

namespace App\Notifications\Concerns;

use App\Contracts\Approvable;
use App\Models\User;
use App\Notifications\Messages\PanelMailMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Lays a request out in a mail message: the facts of it, then where it has
 * got to and what follows.
 *
 * All of it goes in the panel, so the prose above stays to the one sentence
 * that says why the mail arrived. What any of it says is the request's own
 * business; this only decides how it is set on the page. The reader is passed
 * through so the request can address them where a turn is theirs.
 */
trait DescribesRequest
{
    /**
     * @param  Approvable&Model  $request
     */
    protected function describe(PanelMailMessage $mail, Approvable $request, object $notifiable): PanelMailMessage
    {
        $viewer = $notifiable instanceof User ? $notifiable : null;

        return $mail->panel(
            $this->detailLines($request, $viewer),
            $request->standing($viewer),
            $request->nextStep($viewer),
        );
    }

    /**
     * The details as one block, a field to a line. A paragraph apiece would be
     * too airy for a list of short facts, so they are kept together and broken
     * by hand.
     *
     * Each break is carried twice over. The HTML part goes on the `<br>`, and
     * the plain text alternative is built by stripping the tags out, which
     * would run the fields together were the newline not already there.
     *
     * @param  Approvable&Model  $request
     */
    protected function detailLines(Approvable $request, ?User $viewer): HtmlString
    {
        $rows = [];

        foreach ($request->details($viewer) as $label => $value) {
            $rows[] = '<strong>'.e($label).':</strong> '.e($value);
        }

        return new HtmlString(implode("<br>\n", $rows));
    }
}
