<?php

namespace AgentOS\Core;

class SessionEmail
{
    public static function resolve(bool $authenticated, string $accountEmail, $guestEmail): string
    {
        if ($authenticated) {
            return sanitize_email($accountEmail);
        }

        if (!is_string($guestEmail)) {
            return '';
        }
        $candidate = trim($guestEmail);
        if (strlen($candidate) > 190 || !is_email($candidate)) {
            return '';
        }
        return sanitize_email($candidate);
    }
}
