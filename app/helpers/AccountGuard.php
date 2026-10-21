<?php
// app/helpers/AccountGuard.php
// Decides whether an account may log in / use the app, based solely on
// activation state. Subscription logic has been removed (single-tenant POS).

class AccountGuard
{
    /**
     * @param array $user  the users row (needs is_active, email_verified)
     * @return array ['ok'=>bool, 'reason'=>?string]
     */
    public static function evaluate(array $user): array
    {
        if (empty($user['is_active'])) {
            return ['ok' => false, 'reason' => 'switched_off'];
        }
        if (empty($user['email_verified'])) {
            return ['ok' => false, 'reason' => 'not_activated'];
        }

        return ['ok' => true, 'reason' => null];
    }

    public static function message(string $reason): string
    {
        return [
            'not_activated' => 'Confirm your email first — open the "Confirm your email" link we sent you, then sign in.',
            'switched_off'  => 'This login is switched off. Ask the shop owner or support to turn it back on.',
        ][$reason] ?? 'Your account cannot be accessed right now. Please contact support.';
    }
}