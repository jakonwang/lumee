<?php

namespace App\Tenancy;

use App\Models\Store;
use RuntimeException;

/**
 * Holds the store (tenant) for the current request.
 * Resolved by middleware: from X-Store-Id in the admin API, from the Host in the storefront.
 */
class CurrentStore
{
    private ?Store $store = null;

    /** Signed-in admin's membership in this store (null on the storefront). */
    private ?string $role = null;

    /** @var string[] */
    private array $permissions = [];

    public function setMembership(string $role, array $permissions): void
    {
        $this->role = $role;
        $this->permissions = Permissions::clean($permissions);
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    /** True if the current admin may use this area of the admin (owners can do everything). */
    public function can(string $permission): bool
    {
        return $this->isOwner() || in_array($permission, $this->permissions, true);
    }

    public function set(?Store $store): void
    {
        $this->store = $store;
    }

    public function get(): ?Store
    {
        return $this->store;
    }

    public function id(): ?int
    {
        return $this->store?->id;
    }

    public function check(): bool
    {
        return $this->store !== null;
    }

    /**
     * Blade view name for a template in the theme being rendered (the live theme, or the one
     * being previewed), falling back to its parent theme — e.g. "themes.fresh.layout" / "themes.aurora.cart".
     */
    public function themeView(string $template): string
    {
        $this->getOrFail();

        return app(\App\Themes\ThemeContext::class)->view($template);
    }

    public function getOrFail(): Store
    {
        return $this->store ?? throw new RuntimeException('No current store has been resolved.');
    }
}
