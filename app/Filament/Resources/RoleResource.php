<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = '系統管理';

    protected static ?string $navigationLabel = '角色與權限';

    protected static ?string $modelLabel = '角色';

    protected static ?string $pluralModelLabel = '角色';

    protected static ?int $navigationSort = 91;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('角色資訊')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('角色名稱(英文)')
                            ->required()
                            ->maxLength(125)
                            ->unique(ignoreRecord: true)
                            ->helperText('小寫底線命名,例:customer_support。建立後不建議改名'),
                        Forms\Components\TextInput::make('guard_name')
                            ->label('Guard')
                            ->default('web')
                            ->disabled()
                            ->dehydrated()
                            ->helperText('固定為 web(Filament admin 走 web guard)'),
                    ])->columns(2),

                Forms\Components\Section::make('權限分配')
                    ->schema([
                        Forms\Components\CheckboxList::make('permissions')
                            ->label('權限')
                            ->relationship('permissions', 'name')
                            ->options(fn () => Permission::pluck('name', 'id')->toArray())
                            ->columns(3)
                            ->searchable()
                            ->bulkToggleable()
                            ->helperText('勾選此角色擁有的權限。super_admin 透過 Gate::before 短路,即使這裡沒勾也全通'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('角色名稱')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('guard_name')
                    ->label('Guard')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('permissions_count')
                    ->label('權限數')
                    ->counts('permissions')
                    ->sortable(),
                Tables\Columns\TextColumn::make('users_count')
                    ->label('使用者數')
                    ->counts('users')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Role $record): bool => ! in_array(
                        $record->name,
                        ['super_admin', 'admin', 'customer_support', 'marketing', 'viewer'],
                        true
                    )),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit'   => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
