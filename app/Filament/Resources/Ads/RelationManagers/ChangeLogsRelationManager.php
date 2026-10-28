<?php

namespace App\Filament\Resources\Ads\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChangeLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'changeLogs';

    protected static ?string $title = '修改日志';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('时间')->dateTime(),
                TextColumn::make('user.email')->label('操作人')->placeholder('系统'),
                TextColumn::make('action')->label('操作'),
                TextColumn::make('changes')->label('变更字段')
                    ->formatStateUsing(fn ($state, $record) => implode(', ', array_keys($record->changes ?? []))),
            ]);
    }
}
