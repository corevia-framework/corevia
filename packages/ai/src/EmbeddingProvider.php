<?php
declare(strict_types=1);
namespace Corevia\Ai;
interface EmbeddingProvider{public function embed(array $texts):array;}
