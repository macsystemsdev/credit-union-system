<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests render views that call @vite(...). On a clean clone
        // with no built assets this throws ViteManifestNotFoundException.
        // withoutVite() makes tests independent of the npm build step.
        $this->withoutVite();
    }
}
