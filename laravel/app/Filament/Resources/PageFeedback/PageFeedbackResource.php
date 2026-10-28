<?php

namespace App\Filament\Resources\PageFeedback;

use App\Filament\Resources\PageFeedback\Pages\ListPageFeedback;
use App\Filament\Resources\PageFeedback\Tables\PageFeedbackTable;
use App\Models\PageFeedback;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PageFeedbackResource extends Resource
{
    protected static ?string $model = PageFeedback::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftEllipsis;

    protected static ?string $navigationLabel = 'Sivupalaute';

    protected static ?string $modelLabel = 'palaute';

    protected static ?string $pluralModelLabel = 'sivupalautteet';

    protected static ?string $slug = 'page-feedback';

    public static function table(Table $table): Table
    {
        return PageFeedbackTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPageFeedback::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }

    public static function canReorder(): bool
    {
        return false;
    }
}
