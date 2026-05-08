<x-mail::message>
# Payment Verified

Hi **{{ $order->user->name }}**,

Great news! Your payment has been verified and your subscription is now active.

<x-mail::panel>
**Order #{{ $order->id }}**
Plan: {{ $order->plan->name }}
Amount: {{ $order->currency }} {{ number_format((float) $order->amount, 0, '.', ',') }}
@if($order->subscription)
Valid until: {{ $order->subscription->end_date->format('d F Y') }}
@endif
</x-mail::panel>

Your subscription gives you access to all features included in the **{{ $order->plan->name }}** plan.

If you have any questions, please contact our support team.

Thanks,
{{ config('app.name') }}
</x-mail::message>
