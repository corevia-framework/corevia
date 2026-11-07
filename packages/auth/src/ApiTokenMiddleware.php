<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Http\{ApiResponse,Middleware,Request,Response};
final readonly class ApiTokenMiddleware implements Middleware
{
    public function __construct(private ApiTokens $tokens,private string $scope,private ?string $tenant=null,private int $perMinute=120){}
    public function process(Request $request,callable $next):Response
    {
        try{
            if(getenv('APP_ENV')==='production'&&($request->attributes['scheme']??'')!=='https'){throw new AuthFailure('HTTPS_REQUIRED','HTTPS is required.',403);}
            if(!preg_match('/^Bearer (cv4_[A-Za-z0-9_-]{43})$/D',$request->header('authorization'),$match)){throw new AuthFailure('UNAUTHENTICATED','A bearer credential is required.',401);}
            $context=$this->tokens->authenticate($match[1],$this->scope,$this->tenant,$this->perMinute);
            return $next($request->withAttribute('api_identity',$context)->withAttribute('api_tenant',$this->tenant));
        }catch(AuthFailure $error){$response=ApiResponse::error($error->errorCode,$error->getMessage(),$error->status);return $error->status===401?$response->withHeaders(['WWW-Authenticate'=>'Bearer']):$response;}
    }
}
