@php($expiresLabel = $expiresAt->locale(app()->getLocale())->isoFormat('lll'))
{{ __('emails.greeting_name', ['name' => $user->name]) }}

{{ __('emails.admin_invite_account_created', ['app' => config('app.name', 'Davvy')]) }}

{{ __('emails.admin_invite_use_one_time_link') }}
{{ $inviteUrl }}

{{ __('emails.one_time_link_expires_at', ['expires_at' => $expiresLabel]) }}

{{ __('emails.admin_invite_footer', ['app' => config('app.name', 'Davvy')]) }}
