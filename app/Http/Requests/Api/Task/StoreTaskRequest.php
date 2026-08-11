<?php

namespace App\Http\Requests\Api\Task;

use App\Enums\StormTicketStatus;
use App\Enums\StormTicketWorkStatus;
use App\Http\Requests\Api\Concerns\ReadsStormUploads;
use App\Http\Requests\Api\Concerns\ValidatesTaskInput;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    use ReadsStormUploads, ValidatesTaskInput;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Accept any casing or spacing for the two statuses ("on going",
     * "Closed (resolved)") by folding them to canonical values before the
     * rules run.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('storm_ticket_status') && ($status = StormTicketStatus::fromLoose($this->input('storm_ticket_status')))) {
            $this->merge(['storm_ticket_status' => $status->value]);
        }

        if ($this->has('storm_ticket_work_status') && ($work = StormTicketWorkStatus::fromLoose($this->input('storm_ticket_work_status')))) {
            $this->merge(['storm_ticket_work_status' => $work->value]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            // Omit it and the task is stored unfiled — no project, no group —
            // until an update moves it into one.
            'task_group_id' => ['nullable', 'integer', $this->taskGroupInProjectRule()],
            // STORM stamps the ticket it is creating the task for, so PMIS does
            // not file that same ticket back (see FileStormTicket).
            'storm_ticket_id' => ['nullable', 'integer'],
            // STORM sends both on create too. The status is accepted but says
            // nothing here — task_group_id alone decides where the task lands;
            // the work status lets a ticket arrive already closed.
            'storm_ticket_status' => ['sometimes', 'string', Rule::enum(StormTicketStatus::class)],
            'storm_ticket_work_status' => ['sometimes', 'string', Rule::enum(StormTicketWorkStatus::class)],
            'uploads' => ['nullable', 'array', 'max:20'],
            'uploads.*' => ['file', 'max:25600'],
            // Members are stated by employee number, not PMIS user id — the
            // identifier both systems share (see ValidatesTaskInput).
            'subscribers' => ['nullable', 'array'],
            'subscribers.*' => $this->memberItemRules(),
            'assignees' => ['nullable', 'array'],
            'assignees.*' => $this->memberItemRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateMembersHaveProjectAccess($validator));
    }

    public function messages(): array
    {
        return [
            'task_group_id.exists' => 'The selected task group does not exist, or is archived.',
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => 'title',
            'body' => 'body',
            'uploads.*' => 'upload',
            'assignees.*' => 'assignee',
            'subscribers.*' => 'subscriber',
        ];
    }
}
