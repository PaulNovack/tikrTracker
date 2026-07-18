<?php

namespace App;

enum UserRole: string
{
    case Guest = 'guest';
    case Trader = 'trader';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Guest => 'Guest',
            self::Trader => 'Trader',
            self::Admin => 'Admin',
        };
    }

    public function isGuest(): bool
    {
        return $this === self::Guest;
    }

    public function isTrader(): bool
    {
        return $this === self::Trader;
    }

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }

    public function canAccessAdminFeatures(): bool
    {
        return $this === self::Admin;
    }
}
