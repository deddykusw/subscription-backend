<x-mail::message>
# Payment Not Verified

Hi **{{ $order->user->name }}**,

We were unable to verify your payment for the **{{ $order->plan->name }}** plan.

<x-mail::panel>
**Order #{{ $order->id }}**
Amount: {{ $order->currency }} {{ number_format((float) $order->amount, 0, '.', ',') }}
@if($order->notes)
**Reason:** {{ $order->notes }}
@endif
</x-mail::panel>

**What to do next:**

1. Review the rejection reason above.
2. Make a new payment to: **{{ config('payment.paypal.receiver_email') }}**
3. Submit a new payment order with the correct transaction details.

If you believe this is an error, please contact our support team with your transaction ID.

Thanks,
{{ config('app.name') }}
</x-mail::message>
