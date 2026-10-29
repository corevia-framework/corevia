<?php
declare(strict_types=1);
namespace Corevia\Auth;
interface MailTransport{public function send(string $id,AccountMessage $message):void;}
