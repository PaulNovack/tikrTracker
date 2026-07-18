<?php

namespace App\Http\Requests;

use App\Models\ContactSubmission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ContactFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Please provide your name.',
            'email.required' => 'Please provide your email address.',
            'email.email' => 'Please provide a valid email address.',
            'subject.required' => 'Please provide a subject for your message.',
            'message.required' => 'Please provide a message.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $ipAddress = $this->ip();

            // Count submissions from this IP in the last 24 hours
            $submissionCount = ContactSubmission::where('ip_address', $ipAddress)
                ->where('created_at', '>=', now()->subDay())
                ->count();

            if ($submissionCount >= 3) {
                $validator->errors()->add(
                    'message',
                    'You have reached the maximum of 3 submissions per day. Please try again tomorrow or contact us via alternative methods if you need urgent assistance.'
                );
            }
        });
    }
}
