<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LegacyTestRouteSecurityTest extends TestCase
{
    public function test_legacy_test_routes_are_not_publicly_available(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('dishes/424242/models/model.glb', 'QA_RUN_LEGACY_MODEL');

        $this->get('/api/test')->assertNotFound();
        $this->get('/api/test/424242')->assertNotFound();
    }
}
