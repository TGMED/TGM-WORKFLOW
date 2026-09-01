<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Used for both posting and editing a notice.
 */
class AnnouncementRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:4000'],
            'is_pinned' => ['boolean'],

            // Blank means a draft. A date on its own is enough; the time of
            // day is not something the people team should have to think about.
            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:published_at'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'published_at' => 'publish date',
            'expires_at' => 'expiry date',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'expires_at.after' => 'A notice cannot come down before it goes up.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'title' => $this->validated('title'),
            'body' => $this->validated('body'),
            // Read one by one rather than spread, so clearing a date on an
            // edit writes the null instead of leaving the old value behind.
            'published_at' => $this->validated('published_at'),
            'expires_at' => $this->validated('expires_at'),
            'is_pinned' => $this->boolean('is_pinned'),
        ];
    }
}
