{{--
    The OpenGallery mark (an open frame with the sun over the horizon), drawn
    in the current text colour so it follows light and dark mode. Used when no
    logo is uploaded in Admin → Settings; public/favicon.svg is the same mark.
--}}
<svg {{ $attributes->merge(['viewBox' => '14 14 92 92', 'aria-hidden' => 'true']) }} xmlns="http://www.w3.org/2000/svg">
    <path d="M96 20 H24 V100 H80" fill="none" stroke="currentColor" stroke-width="8" stroke-linecap="square"/>
    <circle cx="52" cy="56" r="12" fill="currentColor"/>
    <line x1="24" y1="80" x2="80" y2="80" stroke="currentColor" stroke-width="8"/>
</svg>
