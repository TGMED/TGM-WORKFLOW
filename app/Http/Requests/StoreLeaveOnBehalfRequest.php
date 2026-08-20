<?php

namespace App\Http\Requests;

/**
 * Leave filed by an approver for a member of staff who cannot file it
 * themselves — someone signed off sick, or without a device to hand.
 */
class StoreLeaveOnBehalfRequest extends StoreLeaveRequest
{
    use RaisesOnBehalf;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'staff_id' => $this->staffRule(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'staff_id.exists' => 'Pick a member of staff who is still with the company.',
            'staff_id.not_in' => 'Raise your own leave from the leave page.',
            'supervisor_id.not_in' => 'Name someone else to approve it. Filing it and approving it cannot be the same person.',
            'relief_officer_id.not_in' => 'Someone other than them has to cover the desk.',
        ];
    }

    /**
     * The person who files a request does not then get to approve it, so they
     * are out along with the member of staff it is for.
     *
     * @return array<int, int>
     */
    protected function ineligibleApprovers(): array
    {
        return array_values(array_filter([$this->staff()->id, $this->user()?->id]));
    }
}
