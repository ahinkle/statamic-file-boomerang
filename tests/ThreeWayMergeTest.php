<?php

use Ahinkle\FileBoomerang\ThreeWayMerge;

it('combines edits to different parts of a file', function () {
    $merged = (new ThreeWayMerge)(
        "title: Welcome home\nsummary: Our church\nbody: Sunday at 9\n",
        "title: Welcome\nsummary: Our church\nbody: Sunday at 9\n",
        "title: Welcome\nsummary: Our church\nbody: Sunday at 10\n",
    );

    expect($merged)->toBe("title: Welcome home\nsummary: Our church\nbody: Sunday at 10\n");
});

it('gives up when both sides change the same lines', function () {
    expect((new ThreeWayMerge)("body: Sunday at 11\n", "body: Sunday at 9\n", "body: Sunday at 10\n"))->toBeNull();
});

it('gives up on binary content', function () {
    expect((new ThreeWayMerge)("GIF89a\0ours", "GIF89a\0base", "GIF89a\0theirs"))->toBeNull();
});

it('keeps the exact bytes, including a missing final newline', function () {
    expect((new ThreeWayMerge)("one\ntwo\nthree", "one\ntwo\nthree", "one\n2\nthree"))->toBe("one\n2\nthree");
});
