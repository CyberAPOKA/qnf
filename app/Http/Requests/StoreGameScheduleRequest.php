<?php

namespace App\Http\Requests;

use App\Models\Game;
use App\Services\GameService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreGameScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'creates_at' => ['required', 'date'],
            'opens_at' => ['required', 'date'],
            'starts_at' => ['required', 'date'],
        ];
    }

    public function attributes(): array
    {
        return [
            'creates_at' => 'criação do jogo',
            'opens_at' => 'abertura do mercado',
            'starts_at' => 'horário do jogo',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $createsAt = $this->asSaoPauloDateTime($this->input('creates_at'));
            $opensAt = $this->asSaoPauloDateTime($this->input('opens_at'));
            $startsAt = $this->asSaoPauloDateTime($this->input('starts_at'));

            if ($opensAt->lt($createsAt)) {
                $validator->errors()->add('opens_at', 'O mercado deve abrir no mesmo horário ou depois da criação do jogo.');
            }

            if ($startsAt->lte($opensAt)) {
                $validator->errors()->add('starts_at', 'O jogo deve ser depois da abertura do mercado.');
            }

            $dateTaken = Game::query()
                ->whereDate('date', $startsAt->toDateString())
                ->exists();

            if ($dateTaken) {
                $validator->errors()->add('starts_at', 'Já existe um jogo nesta data.');
            }
        });
    }

    public function schedulePayload(): array
    {
        return [
            'creates_at' => $this->asSaoPauloDateTime($this->input('creates_at')),
            'opens_at' => $this->asSaoPauloDateTime($this->input('opens_at')),
            'starts_at' => $this->asSaoPauloDateTime($this->input('starts_at')),
        ];
    }

    private function asSaoPauloDateTime(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, GameService::TZ);
    }
}
