<?php

declare(strict_types=1);

/*
 * Only the keys overridden here; Livewire merges the rest from its package config.
 * The form builder (Builder v1) nests pages → blocks → nested blocks → logic groups → rules, which
 * needs deeper dot paths than Livewire's default of 10, and definitions carry `_source` snapshots
 * that roughly double the payload (ADR 0022).
 */
return [
    'payload' => [
        'max_size' => 8 * 1024 * 1024,
        'max_nesting_depth' => 40,
        'max_calls' => 50,
        'max_components' => 200,
    ],
];
