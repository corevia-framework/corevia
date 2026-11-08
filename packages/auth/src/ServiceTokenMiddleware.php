<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Http\{ApiResponse,Middleware,Request,Response};
final readonly class ServiceTokenMiddleware implements Middleware
{
    public function __construct(private ServiceAccounts $accounts,private string $tenant,private string $ability,private int $perMinute=120){}
    public function process(Request $request,callable $next):Response
    {
        try{
            if(getenv('APP_ENV')==='production'&&($request->attributes['scheme']??'')!=='https'){throw new AuthFailure('HTTPS_REQUIRED','HTTPS is required.',403);}
            if(!preg_match('/^Bearer (cvs4_[a-f0-9]{32}\.[A-Za-z0-9_-]{43})$/D',$request->header('authorization'),$match)){throw new AuthFailure('UNAUTHENTICATED','A machine credential is required.',401);}
            $scope=$this->accounts->authenticate($match[1],$this->tenant,$this->ability,$this->perMinute);return $next($request->withAttribute('service_identity',$scope));
        }catch(AuthFailure $error){$response=ApiResponse::error($error->errorCode,$error->getMessage(),$error->status);return $error->status===401?$response->withHeaders(['WWW-Authenticate'=>'Bearer']):$response;}
    }
}
