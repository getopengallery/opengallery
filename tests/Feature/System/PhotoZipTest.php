<?php

use App\Support\PhotoZip;

it('names a file in the zip after the photo title with the extension of the stored file', function (?string $title, string $stored, string $expected) {
    expect(PhotoZip::fileNameFor($title, $stored))->toBe($expected);
})->with([
    'a plain title' => ['Beach 001', 'lake.jpg', 'Beach 001.jpg'],
    'characters not allowed in file names' => ['a/b:c*?"<>|d', 'lake.jpg', 'a-b-c-d.jpg'],
    'a control character' => ["Tab\tbed", 'lake.jpg', 'Tab-bed.jpg'],
    'dots and dashes around the title' => [' ..Sunset-- ', 'lake.jpg', 'Sunset.jpg'],
    'no title' => [null, 'lake.jpg', 'lake.jpg'],
    'a title that is only separators' => ['/ : .', 'lake.jpg', 'lake.jpg'],
    'a stored file without extension' => ['Beach', 'lake', 'Beach'],
]);

it('shortens very long titles', function () {
    expect(PhotoZip::fileNameFor(str_repeat('a', 400), 'lake.jpg'))->toBe(str_repeat('a', 150).'.jpg');
});
