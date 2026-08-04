<?php

namespace App\Filament\Resources\ContractOrderClicks\Tables;

use App\Models\ContractOrderClick;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContractOrderClicksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Aika (UTC)')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
                TextColumn::make('company_name')
                    ->label('Yhtiö')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('contract_name')
                    ->label('Sopimus')
                    ->description(fn (ContractOrderClick $record): string => $record->contract_id)
                    ->searchable(['contract_name', 'contract_id'])
                    ->sortable(),
                TextColumn::make('annual_price_eur')
                    ->label('Vuosihinta')
                    ->money('EUR')
                    ->placeholder('Ei tietoa')
                    ->sortable(),
                TextColumn::make('consumption_kwh')
                    ->label('Kulutus, kWh')
                    ->numeric(decimalPlaces: 0)
                    ->sortable(),
                TextColumn::make('price_rank')
                    ->label('Sija')
                    ->formatStateUsing(fn (?int $state, ContractOrderClick $record): string => $state === null
                        ? 'Ei tietoa'
                        : $state.' / '.($record->rank_total ?? '?')),
                TextColumn::make('rank_consumption_kwh')
                    ->label('Sijan kulutus, kWh')
                    ->numeric(decimalPlaces: 0)
                    ->placeholder('Ei tietoa'),
                IconColumn::make('is_estimate')
                    ->label('Arvio')
                    ->boolean(),
                TextColumn::make('pricing_basis')
                    ->label('Hintaperuste')
                    ->placeholder('Ei tietoa')
                    ->toggleable(),
                TextColumn::make('session_source')
                    ->label('Lähde')
                    ->sortable(),
                TextColumn::make('session_medium')
                    ->label('Media')
                    ->sortable(),
                TextColumn::make('session_campaign')
                    ->label('Kampanja')
                    ->placeholder('Ei kampanjaa')
                    ->toggleable(),
                TextColumn::make('landing_path')
                    ->label('Laskeutumissivu')
                    ->copyable()
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('page_path')
                    ->label('Tapahtumasivu')
                    ->copyable()
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cta_location')
                    ->label('CTA')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'hero' => 'Hero',
                        'sticky' => 'Kiinteä palkki',
                        default => $state,
                    })
                    ->sortable(),
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
                self::textFilter('company_name', 'Yhtiö'),
                self::textFilter('contract_name', 'Sopimus'),
                self::textFilter('session_source', 'Lähde'),
                self::textFilter('session_medium', 'Media'),
                self::textFilter('session_campaign', 'Kampanja'),
                SelectFilter::make('cta_location')
                    ->label('CTA')
                    ->options([
                        'hero' => 'Hero',
                        'sticky' => 'Kiinteä palkki',
                    ]),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100])
            ->recordActions([])
            ->toolbarActions([]);
    }

    private static function textFilter(string $column, string $label): Filter
    {
        return Filter::make($column)
            ->label($label)
            ->schema([
                TextInput::make('value')->label($label),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when(
                    filled($data['value'] ?? null),
                    fn (Builder $query): Builder => $query->where($column, trim((string) $data['value'])),
                ));
    }
}
