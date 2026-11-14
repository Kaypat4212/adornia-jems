<?php
namespace App\Services;
use App\Models\DeliveryRate;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class Delivery
{
    public function options(string $state): Collection
    {
        if (! in_array($state, config('delivery.states'), true)) {
            return collect();
        }
        $scope = DeliveryRate::where('state', $state)->exists() ? $state : '*';

        return DeliveryRate::where('state', $scope)->where('active', true)->whereNotNull('fee')->orderBy('name')->get();
    }

    public function quote(string $state, int $rateId, bool $lock = false): DeliveryRate
    {
        $allowed = $this->options($state)->pluck('id')->all();
        if (! in_array($rateId, $allowed, true)) {
            throw ValidationException::withMessages(['delivery_rate_id' => 'Select an available delivery option for your state.']);
        }
        $rate = DeliveryRate::when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($rateId);
        if (! $rate->active || $rate->fee === null) {
            throw ValidationException::withMessages(['delivery_rate_id' => 'This delivery option is no longer available. Please refresh checkout.']);
        }

        return $rate;
    }
}
