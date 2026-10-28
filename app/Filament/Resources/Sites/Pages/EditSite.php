<?php

namespace App\Filament\Resources\Sites\Pages;

use App\Filament\Resources\Sites\Schemas\SiteForm;
use App\Filament\Resources\Sites\SiteResource;
use App\Models\DesignVersion;
use App\Services\Integrations\Lighthouse;
use App\Services\Render\StaticSiteBuilder;
use App\Services\Theme\DesignConfigValidator;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\File;

class EditSite extends EditRecord
{
    protected static string $resource = SiteResource::class;

    protected ?string $previousCss = null;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return '站点设置';
    }

    protected function getHeaderActions(): array
    {
        return [
            SiteResource::generateAction(),
            SiteForm::importBrandAction(),
            SiteForm::copyBrandAction(),
            SiteResource::deleteAction(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! empty($data['design_config'])) {
            $validator = new DesignConfigValidator;
            $data['design_config'] = $validator->validate($data['design_config'], $this->record->typeConfig());
        }

        return $data;
    }

    protected function beforeSave(): void
    {
        $this->previousCss = $this->record->getOriginal('custom_css');
        $newCss = $this->data['custom_css'] ?? null;
        if ($newCss === $this->previousCss || ! filled($newCss) || ! Lighthouse::available() || ! $this->record->built_at) {
            return;
        }

        // Custom CSS must not drop the home page below the Lighthouse bar.
        $site = $this->record->replicate()->setRelations($this->record->getRelations());
        $site->id = $this->record->id;
        $site->custom_css = $newCss;
        $dir = storage_path('app/lighthouse-check/'.$this->record->id);
        app(StaticSiteBuilder::class)->buildAll($site->load(['domain', 'brandProfile']), null, $dir);
        $result = app(Lighthouse::class)->auditSite($dir, ['home' => '/'])['home'] ?? null;
        File::deleteDirectory($dir);

        $min = config('platform.lighthouse');
        if ($result && ($result['performance'] < $min['min_performance'] || $result['seo'] < $min['min_seo'])) {
            Notification::make()->danger()->title("自定义 CSS 导致首页 Lighthouse 不达标（性能 {$result['performance']} / SEO {$result['seo']}），已拒绝保存")->send();
            $this->halt();
        }
    }

    protected function afterSave(): void
    {
        $site = $this->record;

        if ($site->wasChanged(['design_config', 'custom_css'])) {
            $version = (int) $site->designVersions()->max('version') + 1;
            DesignVersion::create(['site_id' => $site->id, 'version' => $version, 'config' => $site->design_config, 'custom_css' => $site->custom_css, 'source' => 'manual']);
            $site->updateQuietly(['design_version' => $version]);
            Notification::make()->info()->title('设计已保存为 v'.$version.'，点击「更新」重新渲染静态页')->send();
        }
    }
}
