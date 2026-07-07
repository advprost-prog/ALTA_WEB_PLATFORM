<?php

namespace App\Services\Commerce;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Collection;

class CustomerService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function resolveFromCheckout(array $data): Customer
    {
        $normalizedPhone = $this->normalizePhone($this->stringOrNull($data['phone'] ?? null));
        $normalizedEmail = $this->normalizeEmail($this->stringOrNull($data['email'] ?? null));

        $phoneMatch = $normalizedPhone
            ? Customer::query()->where('normalized_phone', $normalizedPhone)->oldest('id')->first()
            : null;
        $emailMatch = $normalizedEmail
            ? Customer::query()->where('normalized_email', $normalizedEmail)->oldest('id')->first()
            : null;

        $customer = $phoneMatch ?: $emailMatch;
        $updateData = $data;

        if ($phoneMatch && $emailMatch && $phoneMatch->id !== $emailMatch->id) {
            $updateData['email'] = null;
        }

        if (! $customer) {
            $customer = $this->createFromOrderData($data);
        }

        return $this->updateFromCheckout($customer, $updateData);
    }

    public function normalizePhone(?string $phone): ?string
    {
        $phone = $this->clean($phone);

        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '38'.$digits;
        }

        return $digits === '' ? null : $digits;
    }

    public function normalizeEmail(?string $email): ?string
    {
        $email = $this->clean($email);

        return $email === null ? null : mb_strtolower($email);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createFromOrderData(array $data): Customer
    {
        $fullName = $this->nameFromData($data);
        $companyName = $this->clean($this->stringOrNull($data['company_name'] ?? null));
        $phone = $this->clean($this->stringOrNull($data['phone'] ?? null));
        $email = $this->clean($this->stringOrNull($data['email'] ?? null));

        return Customer::query()->create([
            'type' => $companyName ? Customer::TYPE_COMPANY : Customer::TYPE_INDIVIDUAL,
            'name' => $fullName ?: $companyName,
            'full_name' => $fullName,
            'company_name' => $companyName,
            'phone' => $phone,
            'email' => $email,
            'normalized_phone' => $this->normalizePhone($phone),
            'normalized_email' => $this->normalizeEmail($email),
            'city' => $this->clean($this->stringOrNull($data['city'] ?? null)),
            'address' => $this->clean($this->stringOrNull($data['address'] ?? null)),
            'is_active' => true,
            'marketing_consent' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateFromCheckout(Customer $customer, array $data): Customer
    {
        $incomingFullName = $this->nameFromData($data);
        $incomingCompanyName = $this->clean($this->stringOrNull($data['company_name'] ?? null));
        $incomingPhone = $this->clean($this->stringOrNull($data['phone'] ?? null));
        $incomingEmail = $this->clean($this->stringOrNull($data['email'] ?? null));
        $incomingNormalizedPhone = $this->normalizePhone($incomingPhone);
        $incomingNormalizedEmail = $this->normalizeEmail($incomingEmail);

        if ($this->isBetterText($incomingFullName, $customer->full_name)) {
            $customer->full_name = $incomingFullName;
            $customer->name = $incomingFullName;
        }

        if ($this->isBetterText($incomingCompanyName, $customer->company_name)) {
            $customer->company_name = $incomingCompanyName;
            $customer->type = Customer::TYPE_COMPANY;
        }

        if ($incomingPhone && (! $customer->normalized_phone || $customer->normalized_phone === $incomingNormalizedPhone)) {
            $customer->phone = $this->isBetterText($incomingPhone, $customer->phone) ? $incomingPhone : $customer->phone;
            $customer->normalized_phone = $incomingNormalizedPhone;
        }

        if ($incomingEmail && (! $customer->normalized_email || $customer->normalized_email === $incomingNormalizedEmail)) {
            $customer->email = $this->isBetterText($incomingEmail, $customer->email) ? $incomingEmail : $customer->email;
            $customer->normalized_email = $incomingNormalizedEmail;
        }

        $incomingCity = $this->clean($this->stringOrNull($data['city'] ?? null));
        $incomingAddress = $this->clean($this->stringOrNull($data['address'] ?? null));

        if ($this->isBetterText($incomingCity, $customer->city)) {
            $customer->city = $incomingCity;
        }

        if ($this->isBetterText($incomingAddress, $customer->address)) {
            $customer->address = $incomingAddress;
        }

        $customer->save();
        $this->syncDeliveryAddressFromCheckout($customer, $data);

        return $customer->refresh();
    }

    /**
     * @return Collection<int, Customer>
     */
    public function findPotentialDuplicates(Customer $customer): Collection
    {
        if (! $customer->normalized_phone && ! $customer->normalized_email) {
            return new Collection;
        }

        return Customer::query()
            ->whereKeyNot($customer->id)
            ->where(function ($query) use ($customer): void {
                if ($customer->normalized_phone) {
                    $query->orWhere('normalized_phone', $customer->normalized_phone);
                }

                if ($customer->normalized_email) {
                    $query->orWhere('normalized_email', $customer->normalized_email);
                }
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncDeliveryAddressFromCheckout(Customer $customer, array $data): void
    {
        $city = $this->clean($this->stringOrNull($data['city'] ?? null));
        $address = $this->clean($this->stringOrNull($data['address'] ?? null));

        if (! $city && ! $address) {
            return;
        }

        $query = $customer->addresses()
            ->where('type', CustomerAddress::TYPE_DELIVERY)
            ->where('city', $city)
            ->where('address', $address);

        $deliveryMethodId = $data['delivery_method_id'] ?? null;

        if ($deliveryMethodId) {
            $query->where('delivery_method_id', $deliveryMethodId);
        }

        $addressRecord = $query->first();

        if ($addressRecord) {
            return;
        }

        $hasDefault = $customer->addresses()
            ->where('type', CustomerAddress::TYPE_DELIVERY)
            ->where('is_default', true)
            ->exists();

        $customer->addresses()->create([
            'type' => CustomerAddress::TYPE_DELIVERY,
            'recipient_name' => $this->nameFromData($data),
            'recipient_phone' => $this->clean($this->stringOrNull($data['phone'] ?? null)),
            'city' => $city,
            'address' => $address,
            'delivery_method_id' => $deliveryMethodId ?: null,
            'is_default' => ! $hasDefault,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function nameFromData(array $data): ?string
    {
        return $this->clean($this->stringOrNull(
            $data['full_name']
                ?? $data['customer_name']
                ?? $data['name']
                ?? null,
        ));
    }

    private function isBetterText(?string $incoming, ?string $current): bool
    {
        $incoming = $this->clean($incoming);

        if ($incoming === null) {
            return false;
        }

        $current = $this->clean($current);

        return $current === null || mb_strlen($incoming) > mb_strlen($current);
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
