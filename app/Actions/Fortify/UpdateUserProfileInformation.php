<?php

namespace App\Actions\Fortify;

use App\Enums\Position;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30', Rule::unique('users')->ignore($user->id)],
            'photo' => ['nullable', 'mimes:jpg,jpeg,png', 'max:1024'],
        ];

        if ($user->position !== Position::GOALKEEPER) {
            $rules['position'] = ['required', Rule::in([Position::FIXED->value, Position::WINGER->value, Position::PIVOT->value])];
        }

        Validator::make($input, $rules)->validateWithBag('updateProfileInformation');

        if (isset($input['photo'])) {
            $user->updateProfilePhoto($input['photo']);
        }

        $data = [
            'name' => $input['name'],
            'phone' => $input['phone'],
            'email' => sprintf('%s@phone.local', preg_replace('/\D+/', '', $input['phone'])),
        ];

        if ($user->position !== Position::GOALKEEPER && isset($input['position'])) {
            $data['position'] = $input['position'];
        }

        $user->forceFill($data)->save();
    }
}
