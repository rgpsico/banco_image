<?php

namespace Tests\Unit;

use App\Enums\TenantRole;
use PHPUnit\Framework\TestCase;

class TenantRoleTest extends TestCase
{
    public function test_hierarchy(): void
    {
        $this->assertTrue(TenantRole::Owner->atLeast(TenantRole::Admin));
        $this->assertTrue(TenantRole::Admin->atLeast(TenantRole::Editor));
        $this->assertTrue(TenantRole::Editor->atLeast(TenantRole::Viewer));
        $this->assertFalse(TenantRole::Viewer->atLeast(TenantRole::Editor));
        $this->assertFalse(TenantRole::Editor->atLeast(TenantRole::Admin));
        $this->assertSame(['owner', 'admin', 'editor', 'viewer'], TenantRole::values());
    }
}
