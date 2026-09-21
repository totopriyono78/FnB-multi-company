<?php

namespace App\Filament\Tables\Columns;

use Closure;
use Filament\Tables\Columns\ToggleColumn;

/** ToggleColumn dengan label aksesibel per baris dan penanda nonaktif untuk pembaca layar. */
class LabeledToggleColumn extends ToggleColumn
{
    protected string $view = 'filament.tables.columns.labeled-toggle';

    protected string|Closure|null $switchLabel = null;

    public function switchLabel(string|Closure|null $label): static
    {
        $this->switchLabel = $label;

        return $this;
    }

    public function getSwitchLabel(): string
    {
        return (string) ($this->evaluate($this->switchLabel) ?? $this->getLabel());
    }
}
