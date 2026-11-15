<?php
declare(strict_types=1);
namespace Corevia\Ai;
interface ToolHandler { public function execute(mixed $input,ToolExecution $execution):mixed; }
