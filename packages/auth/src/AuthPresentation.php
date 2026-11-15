<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Core\Translator;
use Corevia\Http\Request;

/** Non-sensitive display preferences only; locale cookies confer no identity or authorization. */
final readonly class AuthPresentation
{
    private Translator $translator;
    public function __construct(public string $locale='en'){$this->translator=new Translator(AuthMessages::catalogs(),$locale);if(!in_array($locale,['en','ar','ur'],true)){throw new \InvalidArgumentException('Unsupported starter locale.');}}
    public static function for(Request $request):self{$locale=$request->cookie('corevia_locale');return new self(in_array($locale,['en','ar','ur'],true)?$locale:'en');}
    public function text(string $message,array $parameters=[]):string
    {
        $catalogs=AuthMessages::catalogs();if(!isset($catalogs['en'][$message])){$key=array_search($message,$catalogs['en'],true);if(is_string($key)){$message=$key;}}
        return $this->translator->text($message,$parameters);
    }
    public function direction():string{return $this->translator->direction();}
    public function messages():array{return AuthMessages::catalogs()[$this->locale];}
}
