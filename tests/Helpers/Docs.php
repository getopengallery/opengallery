<?php

use App\Support\Documentation;

/** Points the documentation at the fixture pages in tests/Fixtures/docs. */
function useFixtureDocs(): Documentation
{
    $docs = new Documentation(base_path('tests/Fixtures/docs'));
    app()->instance(Documentation::class, $docs);

    return $docs;
}
