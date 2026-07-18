<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStockTransactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:buy,sell'],
            'stock_buy_id' => ['nullable', 'required_if:type,sell', 'exists:stock_transactions,id'],
            'exit_reason' => ['nullable', 'in:manual,stop_loss,break_even,trailing_stop,take_profit'],
            'symbol' => ['required', 'string', 'max:10', 'uppercase'],
            'quantity' => ['required', 'numeric', 'min:0.00000001', 'max:99999999.99999999'],
            'price_per_share' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'current_price_per_share' => ['nullable', 'numeric', 'min:0.01', 'max:999999999.99'],
            'sell_price_per_share' => ['nullable', 'required_if:type,sell', 'numeric', 'min:0.01', 'max:999999999.99'],
            'highest_price_reached' => ['nullable', 'numeric', 'min:0.01', 'max:999999999.99'],
            'fee' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'transaction_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'stop_loss' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'break_even' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'trailing' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Please specify if this is a buy or sell transaction.',
            'type.in' => 'Transaction type must be either buy or sell.',
            'symbol.required' => 'Stock symbol is required.',
            'symbol.uppercase' => 'Stock symbol must be uppercase.',
            'quantity.required' => 'Share quantity is required.',
            'quantity.min' => 'Quantity must be greater than zero.',
            'price_per_share.required' => 'Price per share is required.',
            'transaction_date.required' => 'Transaction date is required.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'symbol' => strtoupper($this->symbol ?? ''),
            'fee' => $this->fee ?? 0,
        ]);
    }
}
