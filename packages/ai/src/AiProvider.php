<?php
declare(strict_types=1);
namespace Corevia\Ai;
interface AiProvider{public function stream(array $messages,int $maxTokens):iterable;}
