<?php

namespace Database\Seeders;

use App\Support\Settings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Default data for a fresh install: prompt templates and guardrail
     * settings. Site type presets and theme components live in config/.
     */
    public function run(): void
    {
        $this->call(PromptSeeder::class);

        if (! Settings::get('indexnow_key')) {
            Settings::set('indexnow_key', Str::lower(Str::random(32)));
        }
    }
}
