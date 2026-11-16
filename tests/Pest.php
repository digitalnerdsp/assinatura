<?php

use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

require_once __DIR__.'/Helpers/wizard.php';
