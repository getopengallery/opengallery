<?php

namespace App\View\Components;

use App\Support\PageMeta;
use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    /** @param  PageMeta|null  $meta  search engine and link preview details; null describes the site itself */
    public function __construct(public ?PageMeta $meta = null) {}

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.app');
    }
}
