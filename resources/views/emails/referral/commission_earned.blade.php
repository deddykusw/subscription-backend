<x-mail::message>
# Commission {{ ucfirst($commission->status->value) }}

Hi **{{ $commission->referrer->name }}**,

@if($commission->status->value === 'credited')
Your referral commission has been credited to your balance!
@else
A referral commission has been created for your account and is pending review.
@endif

<x-mail::panel>
**Commission Details**
Referred user: {{ $commission->referred->name ?? 'N/A' }}
Commission amount: IDR {{ number_format((float) $commission->amount, 0, '.', ',') }}
Rate: {{ $commission->commission_percentage }}%
Status: {{ $commission->status->label() }}
@if($commission->credited_at)
Credited on: {{ $commission->credited_at->format('d F Y') }}
@endif
</x-mail::panel>

You can view your full earnings and request a payout from your referral dashboard.

Thanks,
{{ config('app.name') }}
</x-mail::message>
