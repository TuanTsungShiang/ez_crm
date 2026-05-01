<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MemberResource\Pages;
use App\Filament\Resources\MemberResource\RelationManagers\PointTransactionsRelationManager;
use App\Models\Member;
use App\Models\MemberGroup;
use App\Models\Tag;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;

class MemberResource extends Resource
{
    protected static ?string $model = Member::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = '會員管理';

    protected static ?string $navigationLabel = '會員';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = '會員';

    protected static ?string $pluralModelLabel = '會員';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('基本資料')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('真實姓名')
                            ->required()
                            ->maxLength(100),
                        Forms\Components\TextInput::make('nickname')
                            ->label('暱稱')
                            ->maxLength(100),
                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(191)
                            ->unique(ignoreRecord: true),
                        Forms\Components\TextInput::make('phone')
                            ->label('手機')
                            ->maxLength(20)
                            ->unique(ignoreRecord: true),
                        Forms\Components\TextInput::make('password')
                            ->label('密碼')
                            ->password()
                            ->dehydrateStateUsing(fn ($state) => $state ? bcrypt($state) : null)
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $operation) => $operation === 'create')
                            ->minLength(8),
                    ])->columns(2),

                Forms\Components\Section::make('分類與狀態')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('狀態')
                            ->options([
                                1 => '正常',
                                0 => '停用',
                                2 => '待驗證',
                            ])
                            ->default(1)
                            ->required(),
                        Forms\Components\Select::make('member_group_id')
                            ->label('會員群組')
                            ->relationship('group', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        Forms\Components\Select::make('tags')
                            ->label('標籤')
                            ->relationship('tags', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload(),
                    ])->columns(2),

                Forms\Components\Section::make('個人資料')
                    ->relationship('profile')
                    ->schema([
                        Forms\Components\Select::make('gender')
                            ->label('性別')
                            ->options([
                                0 => '不提供',
                                1 => '男',
                                2 => '女',
                            ])
                            ->nullable(),
                        Forms\Components\DatePicker::make('birthday')
                            ->label('生日')
                            ->nullable(),
                        Forms\Components\Textarea::make('bio')
                            ->label('自我介紹')
                            ->nullable()
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('姓名')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('nickname')
                    ->label('暱稱')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone')
                    ->label('手機')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
                    ->color(fn (int $state) => match ($state) {
                        1 => 'success',
                        0 => 'danger',
                        2 => 'warning',
                    })
                    ->formatStateUsing(fn (int $state) => match ($state) {
                        1 => '正常',
                        0 => '停用',
                        2 => '待驗證',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('group.name')
                    ->label('群組')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('tags.name')
                    ->label('標籤')
                    ->badge()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('points')
                    ->label('點數')
                    ->numeric()
                    ->sortable()
                    ->formatStateUsing(fn (int $state): string => number_format($state))
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                    ->badge(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('狀態')
                    ->options([
                        1 => '正常',
                        0 => '停用',
                        2 => '待驗證',
                    ]),
                Tables\Filters\SelectFilter::make('member_group_id')
                    ->label('群組')
                    ->relationship('group', 'name'),
                Tables\Filters\TrashedFilter::make()
                    ->label('已刪除'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            PointTransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMembers::route('/'),
            'create' => Pages\CreateMember::route('/create'),
            'edit' => Pages\EditMember::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function mutateFormDataBeforeCreate(array $data): array
    {
        $data['uuid'] = Str::uuid();
        return $data;
    }
}
