<?php

namespace App\Filament\Resources\PageFeedback\Tables;

use App\Services\Analytics\PageFeedbackCatalog;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PageFeedbackTable
{
    public static function configure(Table $table): Table
    {
        $pageTypes = array_combine(PageFeedbackCatalog::pageTypes(), PageFeedbackCatalog::pageTypes());
        $reasons = PageFeedbackCatalog::allReasonLabels();
        $goals = PageFeedbackCatalog::allGoalLabels();

        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Aika (UTC)')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
                TextColumn::make('page_type')
                    ->label('Sivutyyppi')
                    ->description(fn ($record): string => $record->page_path)
                    ->sortable(),
                TextColumn::make('vote')
                    ->label('Löysi')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'up' => 'Kyllä',
                        'down' => 'Ei',
                        default => '–',
                    })
                    ->placeholder('–')
                    ->sortable(),
                TextColumn::make('reason_code')
                    ->label('Syy')
                    ->formatStateUsing(fn (?string $state): string => $reasons[$state] ?? (string) $state)
                    ->placeholder('–')
                    ->description(fn ($record): ?string => $record->reason_text)
                    ->wrap(),
                TextColumn::make('goal_code')
                    ->label('Tavoite')
                    ->formatStateUsing(fn (?string $state): string => $goals[$state] ?? (string) $state)
                    ->placeholder('–')
                    ->description(fn ($record): ?string => $record->goal_text)
                    ->wrap(),
                TextColumn::make('device')
                    ->label('Laite')
                    ->toggleable(),
                TextColumn::make('subject_id')
                    ->label('Kohde')
                    ->placeholder('–')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('consumption_kwh')
                    ->label('Kulutus, kWh')
                    ->numeric(decimalPlaces: 0)
                    ->placeholder('–')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('pricing_filter')
                    ->label('Hinnoittelu')
                    ->placeholder('–')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('result_count')
                    ->label('Tuloksia')
                    ->placeholder('–')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('session_source')
                    ->label('Lähde')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('session_medium')
                    ->label('Media')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('session_campaign')
                    ->label('Kampanja')
                    ->placeholder('–')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('landing_path')
                    ->label('Laskeutumissivu')
                    ->copyable()
                    ->wrap()
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('occurred_at')
                    ->label('Aikaväli')
                    ->schema([
                        DatePicker::make('from')->label('Alkaen'),
                        DatePicker::make('until')->label('Päättyen'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('occurred_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('occurred_at', '<=', $date))),
                SelectFilter::make('page_type')
                    ->label('Sivutyyppi')
                    ->options($pageTypes),
                SelectFilter::make('vote')
                    ->label('Löysi')
                    ->options(['up' => 'Kyllä', 'down' => 'Ei']),
                SelectFilter::make('reason_code')
                    ->label('Syy')
                    ->options($reasons),
                SelectFilter::make('goal_code')
                    ->label('Tavoite')
                    ->options($goals),
                Filter::make('has_text')
                    ->label('Vain vastaukset, joissa on tekstiä')
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query
                        ->whereNotNull('reason_text')
                        ->orWhereNotNull('goal_text'))),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
