<?php
declare(strict_types=1);
// Return named idempotent handlers: function(array $input, JobClaim $claim, ExecutionScope $scope):array.
// Use $claim->idempotencyKey() with external providers and renew leases before expiry.
return [];
