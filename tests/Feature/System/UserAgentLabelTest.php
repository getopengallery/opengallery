<?php

use App\Support\UserAgentLabel;

describe('the label of a user agent', function () {
    it('names the browser and the system', function (string $userAgent, string $label) {
        expect(UserAgentLabel::from($userAgent))->toBe($label);
    })->with([
        'Chrome on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36', 'Chrome on Windows'],
        'Edge on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 Edg/126.0.0.0', 'Edge on Windows'],
        'Firefox on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:127.0) Gecko/20100101 Firefox/127.0', 'Firefox on macOS'],
        'Safari on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', 'Safari on macOS'],
        'Chrome on Android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36', 'Chrome on Android'],
        'Safari on an iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'Safari on iOS'],
        'Safari on an iPad' => ['Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'Safari on iPadOS'],
        'Firefox on Linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0', 'Firefox on Linux'],
    ]);

    it('names only the system or only the browser when the other is unknown', function (string $userAgent, string $label) {
        expect(UserAgentLabel::from($userAgent))->toBe($label);
    })->with([
        'system only' => ['SomeApp/2.0 (Windows NT 10.0)', 'Windows'],
        'browser only' => ['Firefox/127.0', 'Firefox'],
    ]);

    it('has no label for an empty or unrecognised user agent', function (?string $userAgent) {
        expect(UserAgentLabel::from($userAgent))->toBeNull();
    })->with([null, '', '   ', 'curl/8.7.1', 'x7#!garbage']);

    it('translates the label, and names unknown devices', function () {
        expect(UserAgentLabel::display('Firefox on macOS'))->toBe('Firefox on macOS')
            ->and(UserAgentLabel::display('Safari'))->toBe('Safari')
            ->and(UserAgentLabel::display(null))->toBe('Unknown device');

        app()->setLocale('de');

        expect(UserAgentLabel::display('Firefox on macOS'))->toBe('Firefox auf macOS')
            ->and(UserAgentLabel::display(null))->not->toBe('Unknown device');
    });
});
