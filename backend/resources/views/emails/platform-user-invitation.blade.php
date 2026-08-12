@component('mail::message')
# Platform administration invitation

You have been invited to be a **platform administrator** on Vee-Care — the platform that operates hospital applications, tenants and domains.

Set your password to activate the account by clicking the button below. The invitation expires on **{{ $expiresAt->format('F j, Y') }}** and can only be used once.

@component('mail::button', ['url' => $acceptUrl])
Activate your account
@endcomponent

If you did not expect this invitation, you can safely ignore this email. No password has been set for your account and no one else can use this link.

Thanks,<br>
The Vee-Care team
@endcomponent
