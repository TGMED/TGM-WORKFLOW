<?php

namespace App\Notifications\Messages;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;

/**
 * A mail message that can set one block of facts apart from the prose.
 *
 * Most of our mail is a sentence or two of explanation wrapped around the
 * details of a request. Run together as paragraphs they all read alike, so
 * the details go in a panel: same words, but boxed off and easy to find on a
 * phone. The panel is rendered by the mail template between the last line and
 * the button, so there is only one place it can be and nothing to position.
 */
class PanelMailMessage extends MailMessage
{
    /**
     * Set the panel's contents. Each block becomes its own paragraph inside
     * the box; nulls are dropped, so a caller can pass a line that is not
     * always there without guarding it.
     */
    public function panel(Htmlable|string|null ...$blocks): static
    {
        $blocks = array_filter(
            array_map(static fn ($block) => trim((string) $block), $blocks),
            static fn (string $block) => $block !== '',
        );

        if ($blocks === []) {
            return $this;
        }

        $this->viewData['panel'] = new HtmlString(implode("\n\n", $blocks));

        return $this;
    }
}
