<?php

declare(strict_types=1);

namespace App\Sub;

// The PSR-4 rule App\ => src/ matches this namespace, but since this file
// lives directly under src/ (not src/Sub/), the derived path src/Sub/Missing.php
// does not exist: expected_path_missing.
class Missing
{
}
