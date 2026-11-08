<?php
declare(strict_types=1);
namespace Corevia\Auth;
/** Keep detailed hold reasons in private operator systems, not public error responses. */
final class DataRetentionHold extends \RuntimeException{}
