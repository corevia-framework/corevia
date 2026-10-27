<?php
declare(strict_types=1);
return ['run_store'=>getenv('RUN_STORE') ?: 'json','connection'=>getenv('RUN_DB_CONNECTION') ?: null];
