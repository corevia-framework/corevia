<?php
declare(strict_types=1);
return ['connection'=>getenv('QUEUE_CONNECTION')?:null,'prefix'=>getenv('QUEUE_TABLE_PREFIX')?:'cv_work'];
