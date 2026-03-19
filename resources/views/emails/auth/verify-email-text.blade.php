@php($expiresLabel = $expiresAt->locale(app()->getLocale())->isoFormat('lll'))
{{ __('emails.greeting_name', ['name' => $user->name]) }}

{{ __('emails.verify_email_thanks_for_registering', ['app' => config('app.name', 'Davvy')]) }}

{{ __('emails.verify_email_use_one_time_link') }}
{{ $verificationUrl }}

{{ __('emails.one_time_link_expires_at', ['expires_at' => $expiresLabel]) }}

{{ __('emails.verify_email_footer', ['app' => config('app.name', 'Davvy')]) }}
