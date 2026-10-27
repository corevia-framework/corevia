<?php
declare(strict_types=1);
namespace App\Http;
use Corevia\Http\{Request,Response,Router,View};
use Corevia\Persistence\DatabaseManager;

final readonly class HomeController
{
    public function __construct(private View $view,private Router $router,private DatabaseManager $database){}
    private function page(Request $request,string $title,string $template,array $data=[],int $status=200):Response
    {
        $shared=['route'=>$this->router->url(...),'csrf'=>$request->attributes['csrf_token'] ?? '',...$data];
        $content=$this->view->render($template,$shared);
        return Response::html($this->view->render('layout',['title'=>$title,'content'=>$content,'path'=>$request->path,...$shared]),$status)->withHeaders([
            'Content-Security-Policy'=>"default-src 'self'; style-src 'self'; script-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'",
            'Referrer-Policy'=>'strict-origin-when-cross-origin',
            'Cache-Control'=>'no-store',
        ]);
    }
    public function index(Request $request):Response{return $this->page($request,'Build with Corevia','welcome');}
    public function guide(Request $request):Response{return $this->page($request,'Your first route','guide',['routes'=>$this->router->routes()]);}
    public function database(Request $request):Response{return $this->page($request,'Connect your data','database',['connections'=>$this->database->configured()]);}
    public function hello(Request $request,string $name):Response{return $this->page($request,'Hello, '.$name,'hello',['name'=>$name]);}
    public function contact(Request $request):Response{return $this->page($request,'Try a web form','contact',['values'=>[],'errors'=>[],'submitted'=>false]);}
    public function submit(Request $request):Response
    {
        $input=$request->input();$values=[];$errors=[];
        foreach(['name'=>80,'email'=>254,'message'=>1000] as $field=>$max){$value=$input[$field] ?? '';if(!is_string($value) || trim($value)==='' || strlen($value)>$max){$errors[$field]='Enter a valid '.$field.'.';$values[$field]='';}else{$values[$field]=trim($value);}}
        if(!isset($errors['email']) && !filter_var($values['email'],FILTER_VALIDATE_EMAIL)){$errors['email']='Enter a valid email address.';}
        return $this->page($request,'Try a web form','contact',['values'=>$values,'errors'=>$errors,'submitted'=>$errors===[]],$errors?422:200);
    }
}
