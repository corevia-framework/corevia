<?php
declare(strict_types=1);
namespace Corevia\Http;
use Corevia\Core\Logger;
final readonly class ApiBoundary implements Middleware
{
    public function __construct(private string $version='1',private ?string $sunset=null,private ?Logger $logger=null){ApiResponse::version($version,$sunset);}
    public function process(Request $request,callable $next):Response
    {
        try{$response=$next($request);}
        catch(ApiProblem $error){$response=ApiResponse::error($error->errorCode,$error->getMessage(),$error->status);}
        catch(\LengthException){$response=ApiResponse::error('REQUEST_TOO_LARGE','Reduce the request size.',413);}
        catch(\JsonException|\InvalidArgumentException){$response=ApiResponse::error('INVALID_INPUT','Check the request data.',422);}
        catch(\Throwable $error){$this->logger?->log('error','API handler failed',['exception'=>$error::class]);$response=ApiResponse::error('INTERNAL_ERROR','Request could not be completed.',500);}
        return $response->withHeaders(ApiResponse::version($this->version,$this->sunset));
    }
}
