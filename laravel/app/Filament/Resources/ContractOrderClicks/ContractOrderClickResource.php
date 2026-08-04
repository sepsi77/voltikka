<?php

namespace App\Filament\Resources\ContractOrderClicks;

use App\Filament\Resources\ContractOrderClicks\Pages\ListContractOrderClicks;
use App\Filament\Resources\ContractOrderClicks\Tables\ContractOrderClicksTable;
use App\Models\ContractOrderClick;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ContractOrderClickResource extends Resource
{
    protected static ?string $model = ContractOrderClick::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCursorArrowRays;

    protected static ?string $navigationLabel = 'Sopimustilaukseen siirtymiset';

    protected static ?string $modelLabel = 'sopimustilaukseen siirtyminen';

    protected static ?string $pluralModelLabel = 'sopimustilaukseen siirtymiset';

    public static function table(Table $table): Table
    {
        return ContractOrderClicksTable::configure($table);
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
            'index' => ListContractOrderClicks::route('/'),
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
