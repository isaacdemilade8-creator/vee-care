@component('mail::message')
# Hospital account invitation

You have been invited to join **{{ $tenantName }}** on Vee-Care as a member of the hospital team.

Set your password to activate the account by clicking the button below. The invitation expires on **{{ $expiresAt ? $expiresAt->format('F j, Y') : 'a limited time' }}** and can only be used once.

@component('mail::button', ['url' => $acceptUrl])
Activate your account
@endcomponent

If you did not expect this invitation, you can safely ignore this email. No password has been set for your account and no one else can use this link.

Thanks,<br>
The Vee-Care team
@endcomponent
