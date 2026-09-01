<?php

namespace App\Services\Push;

/**
 * What a notification wants to put on someone's screen. Kept separate from
 * the transport so a notification never has to know FCM's payload shape.
 */
class PushMessage
{
    /**
     * @param  array<string, string>  $data
     */
    public function __construct(
        public string $title,
        public string $body,
        public ?string $url = null,
        public array $data = [],
    ) {}

    /**
     * @param  array<string, string>  $data
     */
    public static function make(string $title, string $body, ?string $url = null, array $data = []): self
    {
        return new self($title, $body, $url, $data);
    }
}
