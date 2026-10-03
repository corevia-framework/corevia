<?php

declare(strict_types=1);

namespace App\Orders\Actions;

use Corevia\Core\{Action, ActionResult, ExecutionContext, State, Validator};

final class ValidateCart implements Action
{
    public function handle(State $state, ExecutionContext $context): State|ActionResult
    {
        $validator = new Validator();
        $errors = $validator->validate($state->data, ['items' => ['required', 'array', 'min' => 1, 'max' => 100], 'email' => ['required', 'email']]);
        foreach (is_array($state->data['items'] ?? null) ? $state->data['items'] : [] as $item) {
            if (!is_array($item) || $validator->validate($item, ['name' => ['required', 'string', 'min' => 1, 'max' => 200], 'price' => ['required', 'number', 'min' => 0, 'max' => 100000]])) {
                $errors['items'][] = 'invalid_item';
            }
        }
        return $errors ? ActionResult::failure('VALIDATION_FAILED', 'A valid cart and email are required.') : $state;
    }
}
