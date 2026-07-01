@component('mail::message')
# Réinitialiser le mot de passe

Vous avez demandé à réinitialiser votre mot de passe.

Cliquez sur le bouton ci-dessous :

@component('mail::button', ['url' => "http://localhost:4200/change-password?token={$token}"])
Changer le mot de passe
@endcomponent

Merci,<br>
{{ config('app.name') }}
@endcomponent
