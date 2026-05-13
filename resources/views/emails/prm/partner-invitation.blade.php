<x-mail::message>
# Partner invitation

You have been invited to join **{{ $organizationDisplayName }}** on {{ config('app.name') }} as **{{ $invitedRoleLabel }}**.

<x-mail::button :url="$acceptUrl">
Accept invitation
</x-mail::button>

This link expires soon. If you did not expect this email, you can ignore it.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
