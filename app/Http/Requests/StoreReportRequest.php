<?php

namespace App\Http\Requests;

use App\Enums\ReportCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(ReportCategory::class)],
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'min:20', 'max:5000'],

            // Who it is about, given either way or not at all. A colleague
            // picked from the list is not checked against `is_active`: people
            // report leavers, and a report about somebody who has since gone
            // is still a report the company has to answer for.
            'subject_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id'),
                Rule::notIn([$this->user()->id]),
            ],
            'subject_name' => ['nullable', 'string', 'max:120'],

            'occurred_on' => ['nullable', 'date', 'before_or_equal:today', 'after_or_equal:'.Carbon::now()->subYears(5)->toDateString()],
            'place' => ['nullable', 'string', 'max:150'],

            // Optional throughout. A report with nothing attached is a report,
            // and asking for proof before listening is how these go unfiled.
            'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp,mp4,m4a,mp3', 'max:20480'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.min' => 'Give enough detail for somebody to act on it.',
            'subject_user_id.not_in' => 'You cannot file a report against yourself.',
            'subject_user_id.exists' => 'Pick a colleague from the list, or type a name instead.',
            'occurred_on.before_or_equal' => 'The date cannot be in the future.',
            'evidence.mimes' => 'Attach a PDF, an image, or an audio or video recording.',
            'evidence.max' => 'Keep the attachment under 20 MB.',
        ];
    }

    /**
     * The row as filed. The reporter is stamped by the controller, not taken
     * from input: nobody gets to file under somebody else's name.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $named = $this->integer('subject_user_id') ?: null;

        return [
            'category' => $this->string('category')->toString(),
            'subject' => $this->string('subject')->trim()->toString(),
            'body' => $this->string('body')->trim()->toString(),
            'subject_user_id' => $named,
            // A typed name is only kept where no colleague was picked, so the
            // two never disagree about who the report is against.
            'subject_name' => $named === null
                ? ($this->string('subject_name')->trim()->toString() ?: null)
                : null,
            'occurred_on' => $this->date('occurred_on'),
            'place' => $this->string('place')->trim()->toString() ?: null,
        ];
    }
}
