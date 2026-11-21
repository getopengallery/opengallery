<?php

namespace App\Filament\Resources\Pages\Tables;

use App\Filament\Actions\OpenOnSiteAction;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Tables\EmptyState;
use App\Models\Page;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Page $record) => '/pages/'.$record->slug),
                IconColumn::make('is_published')
                    ->label(__('Published'))
                    ->boolean(),
                IconColumn::make('members_only')
                    ->label(__('Members only'))
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('title')
            ->filters([
                TernaryFilter::make('is_published')
                    ->label(__('Published'))
                    ->placeholder(__('All'))
                    ->trueLabel(__('Published'))
                    ->falseLabel(__('Drafts')),
                TernaryFilter::make('members_only')
                    ->label(__('Who can see it'))
                    ->placeholder(__('All'))
                    ->trueLabel(__('Members only'))
                    ->falseLabel(__('Everyone')),
            ])
            ->recordActions([
                OpenOnSiteAction::make()
                    ->url(fn (Page $record) => route('pages.show', $record))
                    ->visible(fn (Page $record) => $record->is_published)
                    ->iconButton(),
                EditAction::make()->iconButton(),
                DeleteAction::make()->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->tap(fn (Table $table) => EmptyState::apply(
                $table,
                __('No pages yet'),
                __('Pages hold texts such as an imprint, a privacy policy or an about page.'),
                Heroicon::OutlinedDocumentText,
                [CreateAction::make()->url(PageResource::getUrl('create'))->visible(fn (): bool => PageResource::canCreate())],
            ));
    }
}
