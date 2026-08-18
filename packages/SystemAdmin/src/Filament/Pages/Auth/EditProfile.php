<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

final class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            $this->getTimezoneFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }

    /**
     * Validated on write as well as constrained by the options: the column is a plain
     * string, and an identifier that PHP no longer recognises would otherwise reach
     * the timezone resolver and silently drop the administrator back to server time.
     */
    private function getTimezoneFormComponent(): Select
    {
        $identifiers = timezone_identifiers_list();

        return Select::make('timezone')
            ->label('Timezone')
            ->options(array_combine($identifiers, $identifiers))
            ->searchable()
            ->native(false)
            ->placeholder('UTC (server time)')
            ->rule(Rule::in($identifiers))
            ->helperText('Timestamps across this panel render in this zone, always labelled with it. Leave unset to read the same UTC values as Horizon, Flare and the server logs.');
    }
}
