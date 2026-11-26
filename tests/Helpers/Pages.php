<?php

/**
 * Rich editor markup for a custom block, as Filament stores it.
 *
 * @param  array<string, mixed>  $config
 */
function customBlockMarkup(string $id, array $config): string
{
    return '<div data-type="customBlock" data-id="'.$id.'" data-config="'.e(json_encode($config)).'"></div>';
}
