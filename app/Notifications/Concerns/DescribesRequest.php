<?php

namespace App\Notifications\Concerns;

use App\Contracts\Approvable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;

/**
 * Lays a request out in a mail message: the facts of it, then where it has
 * got to and what follows.
 *
 * What any of that says is the request's own business; this only decides how
 * it is set on the page. The reader is passed through so the request can
 * address them where a turn is theirs.
 */
trait DescribesRequest
{
    /**
     * @param  Approvable&Model  $request
     */
    protected function describe(MailMessage $mail, Approvable $request, object $notifiable): MailMessage
    {
        $viewer = $notifiable instanceof User ? $notifiable : null;

        $mail->line($this->detailLines($request, $viewer))
            ->line($request->standing($viewer));

        $next = $request->nextStep($viewer);

        return $next === null ? $mail : $mail->line($next);
    }

    /**
     * The details as one block, a field to a line. The default mail template
     * puts each `line` in its own paragraph, which is too airy for a list of
     * short facts, so they are kept together and broken by hand.
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
