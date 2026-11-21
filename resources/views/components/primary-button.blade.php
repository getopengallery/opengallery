<button {{ $attributes->merge(['type' => 'submit', 'class' => 'og-btn-primary']) }}>
    {{ $slot }}
</button>
