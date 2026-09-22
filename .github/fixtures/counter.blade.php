<?php

use Livewire\Component;

new class extends Component
{
    public int $count = 0;

    public string $label = 'untouched';

    public function increment(): void
    {
        // Deliberately slow: a round trip that returns instantly cannot
        // tell a helper that waits from one that does not, so the test
        // would pass against a broken wireClick(). This makes the race
        // real and therefore detectable.
        usleep(600_000);

        $this->count++;
        $this->label = 'incremented';
    }
};
?>

<div>
    <h1 id="count">{{ $count }}</h1>
    <p id="label">{{ $label }}</p>
    <button type="button" wire:click="increment">Add one</button>
</div>
