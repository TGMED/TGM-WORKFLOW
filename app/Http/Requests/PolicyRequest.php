<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Enums\PolicyCategory;
use App\Models\Policy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::ManagePolicies) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::enum(PolicyCategory::class)],
            'version' => ['nullable', 'string', 'max:40'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'effective_from' => ['required', 'date'],
            // The version this one replaces, where it replaces one. Only a
            // policy still in force can be superseded: standing down something
            // already retired would say nothing.
            'supersedes_id' => [
                'nullable',
                'integer',
                Rule::exists('policies', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'document' => [
                $this->isUpdate() ? 'nullable' : 'required',
                'file',
                // PDF only: it is what a browser opens in a tab to be read, so
                // nobody has to download the handbook to look something up.
                'mimes:pdf',
                'mimetypes:application/pdf',
                'max:20480',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document.required' => 'Attach the policy document.',
            'document.mimes' => 'Attach the policy as a PDF.',
            'document.mimetypes' => 'Attach the policy as a PDF.',
            'document.max' => 'Keep the document under 20 MB.',
            'supersedes_id.exists' => 'Pick a policy that is still in force.',
        ];
    }

    /**
     * The fields written straight onto the row, as against the file and the
     * supersession, which the library handles. Not named `attributes()`:
     * that one belongs to the validator, for naming fields in messages.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'title' => $this->string('title')->toString(),
            'category' => $this->string('category')->toString(),
            'version' => $this->input('version'),
            'summary' => $this->input('summary'),
            'effective_from' => $this->string('effective_from')->toString(),
        ];
    }

    public function supersedes(): ?Policy
    {
        $id = $this->input('supersedes_id');

        return $id === null ? null : Policy::query()->whereKey((int) $id)->first();
    }

    protected function isUpdate(): bool
    {
        return $this->route('policy') !== null;
    }
}
