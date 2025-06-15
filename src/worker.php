<?php

namespace App;

use App\Core\Worker;

require_once 'vendor/autoload.php';

set_time_limit(0);
ini_set('max_execution_time ', 0);

new Worker();
