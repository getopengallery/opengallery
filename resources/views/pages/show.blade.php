<x-app-layout :meta="$meta ?? null">
    <x-slot:title>{{ $page->title }}</x-slot:title>

    <x-slot name="header">
        <h1 class="text-heading font-semibold">{{ $page->title }}</h1>
    </x-slot>

    <div class="og-container">
        <x-block name="page-content" :page="$page" />
    </div>
</x-app-layout>
