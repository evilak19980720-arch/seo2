<?php

namespace App\Filament\Resources\Sites\RelationManagers;

use App\Models\DesignVersion;
use App\Services\Sites\SiteGenerator;
use App\Services\Sites\SiteManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DesignVersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'designVersions';

    protected static ?string $title = '设计版本';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('version', 'desc')
            ->columns([
                TextColumn::make('version')->label('版本')->formatStateUsing(fn ($state) => 'v'.$state)
                    ->description(fn (DesignVersion $r) => $r->version === $this->getOwnerRecord()->design_version ? '当前使用' : null),
                TextColumn::make('source')->label('来源')->formatStateUsing(fn ($state) => ['ai' => 'AI 生成', 'manual' => '手动编辑', 'rollback' => '回滚'][$state] ?? $state),
                TextColumn::make('config.fonts.heading')->label('标题字体'),
                TextColumn::make('config.colors.primary')->label('主色'),
                TextColumn::make('created_at')->label('时间')->dateTime(),
            ])
            ->headerActions([
                Action::make('regenerate')->label('重新生成设计')->icon('heroicon-o-sparkles')->requiresConfirmation()
                    ->modalDescription('AI 重新生成设计配置（保留内容与 URL），然后重新渲染整站。')
                    ->action(function () {
                        $site = $this->getOwnerRecord();
                        app(SiteGenerator::class)->generateDesign($site->load(['domain', 'brandProfile']));
                        app(SiteManager::class)->rebuild($site);
                        Notification::make()->success()->title('新设计已生成，正在重新渲染')->send();
                    }),
            ])
            ->recordActions([
                Action::make('rollback')->label('回滚到此版本')->icon('heroicon-o-arrow-uturn-left')->requiresConfirmation()
                    ->visible(fn (DesignVersion $r) => $r->version !== $this->getOwnerRecord()->design_version)
                    ->action(function (DesignVersion $record) {
                        $site = $this->getOwnerRecord();
                        app(SiteGenerator::class)->saveDesign($site, $record->config, $record->custom_css, 'rollback');
                        app(SiteManager::class)->rebuild($site);
                        Notification::make()->success()->title("已回滚到 v{$record->version}，正在重新渲染")->send();
                    }),
            ]);
    }
}
