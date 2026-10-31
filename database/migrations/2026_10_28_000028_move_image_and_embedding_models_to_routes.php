<?php

use App\Models\AiProvider;
use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;

/**
 * Image and embedding models are now set on AI 用途分配 only: copy the
 * models typed on the providers into the assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        $routes = (array) Settings::get('ai_routes', []);
        if ($routes === []) {
            return;
        }
        foreach (['image' => 'image_model', 'embedding' => 'embedding_model'] as $purpose => $column) {
            foreach (['primary', 'fallback'] as $which) {
                $id = $routes[$purpose][$which] ?? null;
                if ($id && blank($routes[$purpose]["{$which}_model"] ?? null)) {
                    $routes[$purpose]["{$which}_model"] = AiProvider::find($id)?->{$column} ?: null;
                }
            }
        }
        Settings::set('ai_routes', $routes);
    }

    public function down(): void {}
};
