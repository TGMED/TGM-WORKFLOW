<?php

namespace App\Http\Requests;

use App\Enums\AssetCondition;
use App\Enums\AssetStatus;
use App\Enums\Permission;
use App\Models\Asset;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::ManageAssets) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Asset|null $asset */
        $asset = $this->route('asset');

        $rules = [
            'tag' => ['required', 'string', 'max:40', Rule::unique('assets', 'tag')->ignore($asset?->id)],
            'name' => ['required', 'string', 'max:120'],
            'asset_category_id' => ['required', 'integer', Rule::exists('asset_categories', 'id')->whereNull('deleted_at')],
            'serial_number' => ['nullable', 'string', 'max:80'],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')],
            'spot' => ['nullable', 'string', 'max:120'],
            // Assigned is reached by handing it over, never picked.
            'status' => ['required', Rule::enum(AssetStatus::class)->except([AssetStatus::Assigned])],
            'condition' => ['required', Rule::enum(AssetCondition::class)],
            'purchased_on' => ['nullable', 'date', 'before_or_equal:today'],
            'purchase_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        // A new asset can go straight to somebody. After that, handing it
        // over is its own step, so the history records who did it and when.
        if ($asset === null) {
            $rules['assigned_user_id'] = ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)->whereNull('deleted_at')];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tag.unique' => 'Another asset already carries that tag.',
            'status.enum' => 'Hand the asset to somebody to mark it assigned.',
            'asset_category_id.required' => 'Pick a category. Add one under Categories if the list is empty.',
            'assigned_user_id.exists' => 'Pick somebody still with the company.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('assigned_user_id') && $this->input('status') !== AssetStatus::Available->value) {
                $validator->errors()->add('assigned_user_id', 'Only an available asset can be handed to somebody.');
            }
        });
    }
}
