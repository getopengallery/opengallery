<button {{ $attributes->merge(['type' => 'submit', 'class' => 'og-btn-danger']) }}>
    {{ $slot }}
</button>
